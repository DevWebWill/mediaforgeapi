<?php

namespace Tests\Feature;

use App\Enums\JobErrorCode;
use App\Enums\JobStatus;
use App\Jobs\ProcessMediaJob;
use App\Media\Processing\Exceptions\PermanentProcessingException;
use App\Media\Processing\Exceptions\ProcessingCancelledException;
use App\Media\Processing\MediaProcessor;
use App\Media\Processing\ProcessingContext;
use App\Models\MediaJob;
use App\Services\Jobs\JobStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessMediaJobTest extends TestCase
{
    use RefreshDatabase;

    private function useProcessor(\Closure $handler): void
    {
        $this->app->instance(MediaProcessor::class, new class($handler) implements MediaProcessor
        {
            public function __construct(private \Closure $handler) {}

            public function process(MediaJob $job, ProcessingContext $context): array
            {
                return ($this->handler)($job, $context);
            }
        });
    }

    private function run_(MediaJob $job): ProcessMediaJob
    {
        $message = (new ProcessMediaJob($job->id))->withFakeQueueInteractions();
        $this->app->call([$message, 'handle']);
        $job->refresh();

        return $message;
    }

    public function test_simulated_processor_completes_the_job(): void
    {
        $job = MediaJob::factory()->create(['processing_deadline' => now()->addHour()]);

        $this->run_($job)->assertNotReleased();

        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertSame(100, $job->progress);
        $this->assertTrue($job->result['simulated']);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->finished_at);
        $this->assertNotNull($job->processing_ms);
        $this->assertNull($job->worker_token);
        $this->assertNull($job->deadline_missed_by_seconds);
    }

    public function test_missed_deadline_is_recorded(): void
    {
        $job = MediaJob::factory()->create(['processing_deadline' => now()->addMinute()]);
        $this->travel(5)->minutes();

        $this->run_($job);

        $this->assertGreaterThanOrEqual(240, $job->deadline_missed_by_seconds);
    }

    public function test_cancelled_and_finished_jobs_are_not_processed(): void
    {
        $called = false;
        $this->useProcessor(function () use (&$called) {
            $called = true;

            return [];
        });

        foreach ([JobStatus::Cancelled, JobStatus::Completed, JobStatus::Failed] as $status) {
            $job = MediaJob::factory()->status($status)->create();
            $this->run_($job);
            $this->assertSame($status, $job->status);
        }

        $this->assertFalse($called);
    }

    public function test_job_being_processed_by_live_worker_is_not_claimed_twice(): void
    {
        $job = MediaJob::factory()->status(JobStatus::Processing)->create([
            'worker_token' => 'other-worker',
            'heartbeat_at' => now(),
            'attempts' => 1,
        ]);

        $this->run_($job);

        $this->assertSame(JobStatus::Processing, $job->status);
        $this->assertSame('other-worker', $job->worker_token);
        $this->assertSame(1, $job->attempts);
    }

    public function test_stale_processing_job_is_reclaimed(): void
    {
        $job = MediaJob::factory()->status(JobStatus::Processing)->create([
            'worker_token' => 'dead-worker',
            'heartbeat_at' => now()->subMinutes(10),
            'attempts' => 1,
        ]);

        $this->run_($job);

        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertSame(2, $job->attempts);
        $this->assertTrue($job->events()->where('type', 'reclaimed')->exists());
    }

    public function test_stale_worker_cannot_overwrite_result_after_reclaim(): void
    {
        $job = MediaJob::factory()->create();
        $machine = app(JobStateMachine::class);

        $this->assertTrue($machine->claim($job, 'first'));
        $this->travel(10)->minutes();
        $this->assertTrue($machine->claim($job, 'second'));

        $this->assertFalse($machine->complete($job, 'first', ['stale' => true], 10));
        $this->assertTrue($machine->complete($job, 'second', ['ok' => true], 10));
        $this->assertSame(['ok' => true], $job->fresh()->result);
    }

    public function test_permanent_error_fails_without_retry(): void
    {
        $this->useProcessor(fn () => throw new PermanentProcessingException(JobErrorCode::InputInvalid, 'Archivo no válido.'));
        $job = MediaJob::factory()->create();

        $this->run_($job)->assertNotReleased();

        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertSame('input_invalid', $job->error_code);
        $this->assertSame(1, $job->attempts);
    }

    public function test_transient_error_is_retried_with_backoff(): void
    {
        $this->useProcessor(fn () => throw new RuntimeException('disk hiccup /var/secret/path'));
        $job = MediaJob::factory()->create();

        $this->run_($job)->assertReleased(30);

        $this->assertSame(JobStatus::Retrying, $job->status);
        $this->assertSame('processing_failed', $job->error_code);
        $this->assertStringNotContainsString('/var/secret', (string) $job->error_message);
    }

    public function test_transient_error_on_last_attempt_fails(): void
    {
        $this->useProcessor(fn () => throw new RuntimeException('boom'));
        $job = MediaJob::factory()->status(JobStatus::Retrying)->create(['attempts' => 2, 'max_attempts' => 3]);

        $this->run_($job)->assertNotReleased();

        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertSame(3, $job->attempts);
    }

    public function test_cancellation_during_processing_is_confirmed(): void
    {
        $this->useProcessor(function (MediaJob $job, ProcessingContext $context) {
            app(JobStateMachine::class)->requestCancellation($job);

            throw new ProcessingCancelledException;
        });
        $job = MediaJob::factory()->create();

        $this->run_($job);

        $this->assertSame(JobStatus::Cancelled, $job->status);
    }

    public function test_cancellation_requested_before_completion_wins(): void
    {
        $this->useProcessor(function (MediaJob $job) {
            app(JobStateMachine::class)->requestCancellation($job);

            return ['done' => true];
        });
        $job = MediaJob::factory()->create();

        $this->run_($job);

        $this->assertSame(JobStatus::Cancelled, $job->status);
    }

    public function test_failed_hook_closes_unfinished_job(): void
    {
        $job = MediaJob::factory()->status(JobStatus::Processing)->create(['worker_token' => 'w', 'heartbeat_at' => now()]);

        (new ProcessMediaJob($job->id))->failed(new RuntimeException('timeout'));

        $this->assertSame(JobStatus::Failed, $job->fresh()->status);
        $this->assertSame('processing_timeout', $job->fresh()->error_code);
    }

    public function test_events_record_full_lifecycle(): void
    {
        $job = MediaJob::factory()->create();
        $this->run_($job);

        $this->assertSame(
            [['queued', 'processing'], ['processing', 'completed']],
            $job->events()->orderBy('id')->get()->map(fn ($e) => [$e->from_status->value, $e->to_status->value])->all(),
        );
    }
}
