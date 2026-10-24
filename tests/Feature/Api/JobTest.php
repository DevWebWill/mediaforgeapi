<?php

namespace Tests\Feature\Api;

use App\Enums\JobStatus;
use App\Jobs\ProcessMediaJob;
use App\Models\ApiClient;
use App\Models\MediaAsset;
use App\Models\MediaJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class JobTest extends TestCase
{
    use RefreshDatabase;

    private ApiClient $client;

    /** @var array<string, string> */
    private array $headers;

    private MediaAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->client, $this->headers] = $this->apiClient();
        $this->asset = MediaAsset::factory()->for($this->client, 'apiClient')->create();
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'file_id' => $this->asset->public_id,
            'operation' => 'compress',
            'parameters' => ['crf' => 26],
            ...$overrides,
        ];
    }

    public function test_job_is_persisted_queued_and_returns_202(): void
    {
        $response = $this->postJson('/api/v1/jobs', $this->payload(['reference' => 'post-42']), $this->headers)
            ->assertAccepted()
            ->assertHeader('Location')
            ->assertJsonPath('data.object', 'job')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.file_id', $this->asset->public_id)
            ->assertJsonPath('data.parameters.crf', 26)
            ->assertJsonPath('data.reference', 'post-42')
            ->assertJsonPath('data.estimated_completion_at', null);

        $job = MediaJob::query()->wherePublicId($response->json('data.id'))->firstOrFail();

        $this->assertSame($this->client->id, $job->api_client_id);
        $this->assertNotNull($job->queued_at);
        Queue::assertPushedOn('media', ProcessMediaJob::class, fn (ProcessMediaJob $m) => $m->mediaJobId === $job->id);
    }

    public function test_profile_defaults_are_merged_and_client_values_win(): void
    {
        $this->postJson('/api/v1/jobs', $this->payload([
            'operation' => 'resize',
            'profile' => 'tiktok',
            'parameters' => ['crf' => 28],
        ]), $this->headers)
            ->assertAccepted()
            ->assertJsonPath('data.profile', 'tiktok')
            ->assertJsonPath('data.profile_version', 1)
            ->assertJsonPath('data.parameters.width', 1080)
            ->assertJsonPath('data.parameters.crf', 28);
    }

    public function test_profile_incompatible_with_operation_is_rejected(): void
    {
        $this->postJson('/api/v1/jobs', $this->payload(['operation' => 'thumbnail', 'profile' => 'tiktok', 'parameters' => []]), $this->headers)
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['profile']]]);
    }

    public function test_invalid_operation_and_arbitrary_parameters_are_rejected(): void
    {
        $this->postJson('/api/v1/jobs', $this->payload(['operation' => 'shell']), $this->headers)
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['operation']]]);

        $this->postJson('/api/v1/jobs', $this->payload(['parameters' => ['filter_complex' => 'movie=/etc/passwd']]), $this->headers)
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['parameters.filter_complex']]]);

        $this->assertSame(0, MediaJob::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_file_of_another_client_cannot_be_used(): void
    {
        $foreign = MediaAsset::factory()->create();

        $this->postJson('/api/v1/jobs', $this->payload(['file_id' => $foreign->public_id]), $this->headers)
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['file_id']]]);
    }

    public function test_priority_above_client_maximum_is_rejected(): void
    {
        $this->postJson('/api/v1/jobs', $this->payload(['priority' => 'high']), $this->headers)
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['priority']]]);
    }

    public function test_allowed_high_priority_uses_high_queue(): void
    {
        $this->client->update(['max_priority' => 'high']);

        $this->postJson('/api/v1/jobs', $this->payload(['priority' => 'high']), $this->headers)->assertAccepted();

        Queue::assertPushedOn('media-high', ProcessMediaJob::class);
    }

    public function test_past_processing_deadline_is_rejected(): void
    {
        $this->postJson('/api/v1/jobs', $this->payload(['processing_deadline' => now()->subHour()->toIso8601String()]), $this->headers)
            ->assertUnprocessable();
    }

    public function test_idempotent_replay_returns_same_job(): void
    {
        $headers = [...$this->headers, 'Idempotency-Key' => 'publish-123-video'];

        $first = $this->postJson('/api/v1/jobs', $this->payload(), $headers)->assertAccepted();
        $second = $this->postJson('/api/v1/jobs', $this->payload(), $headers)
            ->assertAccepted()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, MediaJob::query()->count());
        Queue::assertPushed(ProcessMediaJob::class, 1);
    }

    public function test_idempotency_key_reused_with_different_payload_returns_409(): void
    {
        $headers = [...$this->headers, 'Idempotency-Key' => 'publish-123-video'];

        $this->postJson('/api/v1/jobs', $this->payload(), $headers)->assertAccepted();
        $this->postJson('/api/v1/jobs', $this->payload(['parameters' => ['crf' => 30]]), $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_reused');
    }

    public function test_idempotency_key_is_scoped_per_client(): void
    {
        [$other, $otherHeaders] = $this->apiClient();
        $otherAsset = MediaAsset::factory()->for($other, 'apiClient')->create();

        $this->postJson('/api/v1/jobs', $this->payload(), [...$this->headers, 'Idempotency-Key' => 'same-key-123'])->assertAccepted();
        $this->postJson('/api/v1/jobs', $this->payload(['file_id' => $otherAsset->public_id]), [...$otherHeaders, 'Idempotency-Key' => 'same-key-123'])
            ->assertAccepted()
            ->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(2, MediaJob::query()->count());
    }

    public function test_expired_idempotency_key_creates_new_job(): void
    {
        $headers = [...$this->headers, 'Idempotency-Key' => 'publish-123-video'];

        $first = $this->postJson('/api/v1/jobs', $this->payload(), $headers)->json('data.id');
        $this->travel(25)->hours();
        $second = $this->postJson('/api/v1/jobs', $this->payload(), $headers)->assertAccepted()->json('data.id');

        $this->assertNotSame($first, $second);
    }

    public function test_invalid_idempotency_key_returns_400(): void
    {
        $this->postJson('/api/v1/jobs', $this->payload(), [...$this->headers, 'Idempotency-Key' => 'bad key!'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_idempotency_key');
    }

    public function test_active_jobs_limit_is_enforced(): void
    {
        $this->client->update(['max_active_jobs' => 1]);

        $this->postJson('/api/v1/jobs', $this->payload(), $this->headers)->assertAccepted();
        $this->postJson('/api/v1/jobs', $this->payload(), $this->headers)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'active_jobs_limit_exceeded');
    }

    public function test_jobs_are_isolated_between_clients(): void
    {
        $job = MediaJob::factory()->for($this->asset, 'inputAsset')->create();
        [, $otherHeaders] = $this->apiClient();

        $this->getJson("/api/v1/jobs/{$job->public_id}", $otherHeaders)->assertNotFound();
        $this->postJson("/api/v1/jobs/{$job->public_id}/cancel", [], $otherHeaders)->assertNotFound();
        $this->getJson("/api/v1/jobs/{$job->public_id}/result", $otherHeaders)->assertNotFound();
        $this->getJson("/api/v1/jobs/{$job->public_id}/logs", $otherHeaders)->assertNotFound();
        $this->getJson('/api/v1/jobs', $otherHeaders)->assertOk()->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/jobs/{$job->public_id}", $this->headers)->assertOk();
    }

    public function test_list_filters_by_status_and_reference(): void
    {
        MediaJob::factory()->for($this->asset, 'inputAsset')->create(['reference' => 'a']);
        MediaJob::factory()->for($this->asset, 'inputAsset')->status(JobStatus::Completed)->create(['reference' => 'b']);

        $this->getJson('/api/v1/jobs?status=completed', $this->headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', 'b');
        $this->getJson('/api/v1/jobs?reference=a', $this->headers)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/jobs?status=bogus', $this->headers)->assertUnprocessable();
    }

    public function test_queued_job_is_cancelled_immediately(): void
    {
        $job = MediaJob::factory()->for($this->asset, 'inputAsset')->create();

        $this->postJson("/api/v1/jobs/{$job->public_id}/cancel", [], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->postJson("/api/v1/jobs/{$job->public_id}/cancel", [], $this->headers)->assertOk();
    }

    public function test_processing_job_gets_cooperative_cancellation_request(): void
    {
        $job = MediaJob::factory()->for($this->asset, 'inputAsset')->status(JobStatus::Processing)->create(['worker_token' => 'w', 'heartbeat_at' => now()]);

        $this->postJson("/api/v1/jobs/{$job->public_id}/cancel", [], $this->headers)
            ->assertAccepted()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.cancel_requested', true);
    }

    public function test_finished_job_cannot_be_cancelled(): void
    {
        $job = MediaJob::factory()->for($this->asset, 'inputAsset')->status(JobStatus::Completed)->create();

        $this->postJson("/api/v1/jobs/{$job->public_id}/cancel", [], $this->headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'job_not_cancellable');
    }

    public function test_result_requires_completed_job(): void
    {
        $job = MediaJob::factory()->for($this->asset, 'inputAsset')->create();

        $this->getJson("/api/v1/jobs/{$job->public_id}/result", $this->headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'job_not_completed');
    }

    public function test_logs_list_job_events(): void
    {
        $id = $this->postJson('/api/v1/jobs', $this->payload(), $this->headers)->json('data.id');

        $this->getJson("/api/v1/jobs/{$id}/logs", $this->headers)
            ->assertOk()
            ->assertJsonPath('data.0.type', 'created')
            ->assertJsonPath('data.1.to_status', 'queued');
    }
}
