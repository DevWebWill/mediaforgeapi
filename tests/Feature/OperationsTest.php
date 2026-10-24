<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Jobs\ProcessMediaJob;
use App\Models\MediaAsset;
use App\Models\MediaJob;
use App\Services\ApiKeys\ApiKeyService;
use App\Services\Jobs\CompletionEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
    }

    public function test_recover_requeues_lost_pending_and_stale_jobs_only(): void
    {
        Queue::fake();

        $lostPending = MediaJob::factory()->status(JobStatus::Pending)->create(['created_at' => now()->subMinutes(10)]);
        $freshPending = MediaJob::factory()->status(JobStatus::Pending)->create();
        $stale = MediaJob::factory()->status(JobStatus::Processing)->create(['heartbeat_at' => now()->subHour(), 'worker_token' => 'x']);
        $alive = MediaJob::factory()->status(JobStatus::Processing)->create(['heartbeat_at' => now(), 'worker_token' => 'y']);
        $oldQueued = MediaJob::factory()->create();
        MediaJob::query()->whereKey($oldQueued->id)->update(['updated_at' => now()->subDay()]);
        $freshQueued = MediaJob::factory()->create();
        MediaJob::factory()->status(JobStatus::Completed)->create(['created_at' => now()->subDay()]);

        $this->artisan('mediaforge:jobs:recover')->assertSuccessful();

        $pushed = collect(Queue::pushedJobs()[ProcessMediaJob::class] ?? [])->map(fn ($p) => $p['job']->mediaJobId)->sort()->values()->all();
        $this->assertSame(collect([$lostPending->id, $stale->id, $oldQueued->id])->sort()->values()->all(), $pushed);
        $this->assertSame(JobStatus::Queued, $lostPending->fresh()->status);
        $this->assertSame(JobStatus::Pending, $freshPending->fresh()->status);
        $this->assertTrue($stale->events()->where('type', 'requeued')->exists());
        $this->assertNotContains($alive->id, $pushed);
        $this->assertNotContains($freshQueued->id, $pushed);
    }

    public function test_reclaimed_stale_job_is_processed_again(): void
    {
        $stale = MediaJob::factory()->status(JobStatus::Processing)->create([
            'heartbeat_at' => now()->subHour(), 'worker_token' => 'dead', 'attempts' => 1,
        ]);

        $this->artisan('mediaforge:jobs:recover')->assertSuccessful();

        $this->assertSame(JobStatus::Completed, $stale->fresh()->status);
        $this->assertSame(2, $stale->fresh()->attempts);
    }

    public function test_cleanup_removes_expired_files_but_keeps_files_in_use(): void
    {
        $disk = Storage::disk('media');

        $expired = MediaAsset::factory()->create(['expires_at' => now()->subMinute()]);
        $inUse = MediaAsset::factory()->create(['expires_at' => now()->subMinute()]);
        $valid = MediaAsset::factory()->create(['expires_at' => now()->addDay()]);
        foreach ([$expired, $inUse, $valid] as $asset) {
            $disk->put($asset->path, 'x');
        }
        MediaJob::factory()->create(['input_asset_id' => $inUse->id]);

        $disk->put('tmp/job_orphan/attempt-1/output.mp4', 'x');
        touch($disk->path('tmp/job_orphan'), now()->subDay()->getTimestamp());
        $disk->put('tmp/job_recent/attempt-1/output.mp4', 'x');

        $withKey = MediaJob::factory()->status(JobStatus::Completed)->create([
            'idempotency_key' => 'abc', 'request_fingerprint' => str_repeat('a', 64), 'idempotency_expires_at' => now()->subMinute(),
        ]);

        $this->artisan('mediaforge:cleanup')->assertSuccessful();

        $this->assertSoftDeleted($expired);
        $disk->assertMissing($expired->path);
        $this->assertNotSoftDeleted($inUse);
        $disk->assertExists($inUse->path);
        $disk->assertExists($valid->path);
        $disk->assertMissing('tmp/job_orphan');
        $disk->assertExists('tmp/job_recent/attempt-1/output.mp4');
        $this->assertNull($withKey->fresh()->idempotency_key);
    }

    public function test_failed_job_can_be_listed_and_retried_by_admin(): void
    {
        $failed = MediaJob::factory()->status(JobStatus::Failed)->create([
            'attempts' => 3, 'error_code' => 'processing_failed', 'error_message' => 'boom', 'finished_at' => now(),
        ]);

        $this->artisan('mediaforge:jobs:failed')->expectsOutputToContain($failed->public_id)->assertSuccessful();

        $this->artisan('mediaforge:jobs:retry', ['job' => $failed->public_id])->assertSuccessful();

        $failed->refresh();
        $this->assertSame(JobStatus::Completed, $failed->status);
        $this->assertSame(1, $failed->attempts);
        $this->assertNull($failed->error_code);
        $this->assertTrue($failed->events()->where('type', 'manual_retry')->exists());
    }

    public function test_only_failed_jobs_can_be_retried(): void
    {
        $completed = MediaJob::factory()->status(JobStatus::Completed)->create();

        $this->artisan('mediaforge:jobs:retry', ['job' => $completed->public_id])->assertFailed();
        $this->assertSame(JobStatus::Completed, $completed->fresh()->status);
    }

    public function test_concurrency_limit_defers_job_until_a_slot_is_free(): void
    {
        config(['mediaforge.jobs.max_concurrent' => 1]);
        $job = MediaJob::factory()->create();

        $lock = Cache::lock('mediaforge:ffmpeg-slot:0', 60);
        $this->assertTrue($lock->get());

        ProcessMediaJob::dispatchSync($job->id);
        $this->assertSame(JobStatus::Queued, $job->fresh()->status);

        $lock->release();

        ProcessMediaJob::dispatchSync($job->id);
        $this->assertSame(JobStatus::Completed, $job->fresh()->status);
        // El hueco se libera al terminar
        $this->assertTrue(Cache::lock('mediaforge:ffmpeg-slot:0', 60)->get());
    }

    public function test_scheduled_job_is_dispatched_with_delay(): void
    {
        Queue::fake();
        [$client, $headers] = $this->apiClient();
        $asset = MediaAsset::factory()->for($client)->create();

        $this->postJson('/api/v1/jobs', [
            'file_id' => $asset->public_id,
            'operation' => 'analyze',
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ], $headers)->assertAccepted();

        Queue::assertPushed(ProcessMediaJob::class, fn (ProcessMediaJob $job) => $job->delay !== null);
    }

    public function test_scheduled_job_is_not_processed_early(): void
    {
        $job = MediaJob::factory()->create(['scheduled_at' => now()->addHour()]);

        ProcessMediaJob::dispatchSync($job->id);

        $this->assertSame(JobStatus::Queued, $job->fresh()->status);
    }

    public function test_estimation_requires_enough_history(): void
    {
        $estimator = app(CompletionEstimator::class);
        $queued = MediaJob::factory()->create(['operation' => 'compress']);
        $this->createCompletedSamples('compress', 4);

        $this->assertNull($estimator->estimate($queued));
        $this->getJson("/api/v1/jobs/{$queued->public_id}", $this->headersFor($queued))
            ->assertJsonPath('data.estimated_completion_at', null);

        Cache::flush();
        $this->createCompletedSamples('compress', 1);

        $estimate = $estimator->estimate($queued->fresh());
        $this->assertNotNull($estimate);
        $this->assertTrue($estimate->isFuture());
        $this->assertNull($estimator->estimate(MediaJob::factory()->status(JobStatus::Completed)->create()));
    }

    private function createCompletedSamples(string $operation, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $asset = MediaAsset::factory()->create(['size_bytes' => 10 * 1_048_576]);
            MediaJob::factory()->status(JobStatus::Completed)->create([
                'input_asset_id' => $asset->id,
                'operation' => $operation,
                'processing_ms' => 20_000,
                'finished_at' => now(),
            ]);
        }
    }

    /** @return array<string, string> */
    private function headersFor(MediaJob $job): array
    {
        $issued = app(ApiKeyService::class)->issue($job->apiClient, 'test');

        return ['Authorization' => 'Bearer '.$issued->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_stats_command_reports_jobs_and_errors(): void
    {
        MediaJob::factory()->status(JobStatus::Completed)->create(['finished_at' => now(), 'processing_ms' => 1200]);
        MediaJob::factory()->status(JobStatus::Failed)->create(['finished_at' => now(), 'error_code' => 'input_invalid']);

        $this->artisan('mediaforge:stats', ['--json' => true])
            ->expectsOutputToContain('"input_invalid": 1')
            ->assertSuccessful();

        $this->artisan('mediaforge:stats')->assertSuccessful();
    }
}
