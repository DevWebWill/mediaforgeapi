<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Jobs\ProcessMediaJob;
use App\Models\MediaAsset;
use App\Models\MediaJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Services\Webhooks\Exceptions\UnsafeWebhookUrlException;
use App\Services\Webhooks\WebhookEmitter;
use App\Services\Webhooks\WebhookSigner;
use App\Services\Webhooks\WebhookUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://hooks.test/mediaforge';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config(['mediaforge.webhooks.allowed_hosts' => ['hooks.test']]);
        Http::preventStrayRequests();
    }

    /** @return array<string, array{string}> */
    public static function unsafeUrls(): array
    {
        return [
            'loopback' => ['https://127.0.0.1/hook'],
            'privada 10/8' => ['https://10.1.2.3/hook'],
            'privada 192.168' => ['https://192.168.1.10/hook'],
            'metadatos cloud' => ['https://169.254.169.254/latest/meta-data'],
            'ipv6 loopback' => ['https://[::1]/hook'],
            'ipv6 ula' => ['https://[fd00::1]/hook'],
            'ipv4 mapeada' => ['https://[::ffff:10.0.0.1]/hook'],
            'cgnat' => ['https://100.64.0.1/hook'],
            'no especificada' => ['https://0.0.0.0/hook'],
            'credenciales' => ['https://user:pass@93.184.216.34/hook'],
            'puerto no permitido' => ['https://93.184.216.34:6379/hook'],
            'esquema' => ['ftp://93.184.216.34/hook'],
            'fragmento' => ['https://93.184.216.34/hook#x'],
            'host decimal' => ['https://2130706433/hook'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_guard_rejects_unsafe_urls(string $url): void
    {
        $this->expectException(UnsafeWebhookUrlException::class);

        app(WebhookUrlGuard::class)->inspect($url);
    }

    public function test_guard_accepts_public_ip_and_rejects_http_in_production(): void
    {
        $guard = app(WebhookUrlGuard::class);

        $this->assertSame(['93.184.216.34'], $guard->inspect('https://93.184.216.34/hook')['ips']);

        config(['mediaforge.webhooks.allow_http' => false]);
        $this->expectException(UnsafeWebhookUrlException::class);
        $guard->inspect('http://93.184.216.34/hook');
    }

    public function test_guard_rejects_hostnames_resolving_to_private_ips(): void
    {
        $guard = new class extends WebhookUrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34', '10.0.0.5'];
            }
        };

        $this->expectException(UnsafeWebhookUrlException::class);
        $guard->inspect('https://rebind.example.com/hook');
    }

    public function test_crud_shows_secret_only_on_create_and_rotation(): void
    {
        [, $headers] = $this->apiClient();

        $created = $this->postJson('/api/v1/webhooks', [
            'url' => self::URL,
            'events' => ['job.completed', 'job.failed'],
            'description' => 'Aplicación cliente',
        ], $headers)->assertCreated()->assertJsonPath('data.object', 'webhook');

        $id = $created->json('data.id');
        $secret = $created->json('data.secret');
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertStringStartsWith('whk_', $id);

        $this->getJson("/api/v1/webhooks/{$id}", $headers)->assertOk()->assertJsonMissingPath('data.secret');
        $this->getJson('/api/v1/webhooks', $headers)->assertOk()->assertJsonCount(1, 'data');

        $this->patchJson("/api/v1/webhooks/{$id}", ['events' => ['job.cancelled'], 'is_active' => false], $headers)
            ->assertOk()
            ->assertJsonPath('data.events', ['job.cancelled'])
            ->assertJsonPath('data.is_active', false);

        $rotated = $this->postJson("/api/v1/webhooks/{$id}/rotate-secret", [], $headers)->assertOk();
        $this->assertNotSame($secret, $rotated->json('data.secret'));
        $this->assertNotNull($rotated->json('data.previous_secret_expires_at'));

        $endpoint = WebhookEndpoint::query()->wherePublicId($id)->firstOrFail();
        $this->assertSame([$rotated->json('data.secret'), $secret], $endpoint->signingSecrets());
        // El secreto se guarda cifrado
        $this->assertStringNotContainsString($secret, (string) $endpoint->getRawOriginal('previous_secret'));

        $this->deleteJson("/api/v1/webhooks/{$id}", [], $headers)->assertNoContent();
        $this->getJson("/api/v1/webhooks/{$id}", $headers)->assertNotFound();
    }

    public function test_store_validates_url_events_and_limit(): void
    {
        [, $headers] = $this->apiClient();

        $this->postJson('/api/v1/webhooks', ['url' => 'https://10.0.0.1/x', 'events' => ['job.completed']], $headers)
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->postJson('/api/v1/webhooks', ['url' => self::URL, 'events' => ['job.started']], $headers)
            ->assertUnprocessable();

        config(['mediaforge.webhooks.max_endpoints_per_client' => 1]);
        $this->postJson('/api/v1/webhooks', ['url' => self::URL, 'events' => ['job.completed']], $headers)->assertCreated();
        $this->postJson('/api/v1/webhooks', ['url' => self::URL.'/2', 'events' => ['job.completed']], $headers)
            ->assertUnprocessable()->assertJsonPath('error.code', 'webhook_limit_exceeded');
    }

    public function test_clients_cannot_see_other_clients_webhooks(): void
    {
        [, $owner] = $this->apiClient();
        [, $other] = $this->apiClient();

        $id = $this->postJson('/api/v1/webhooks', ['url' => self::URL, 'events' => ['job.completed']], $owner)->json('data.id');

        $this->getJson("/api/v1/webhooks/{$id}", $other)->assertNotFound();
        $this->deleteJson("/api/v1/webhooks/{$id}", [], $other)->assertNotFound();
        $this->getJson('/api/v1/webhooks', $other)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_completed_job_sends_signed_webhook(): void
    {
        Http::fake(['hooks.test/*' => Http::response('ok', 200)]);

        $job = MediaJob::factory()->create();
        [$endpoint, $secret] = $this->endpointFor($job->api_client_id, ['job.completed']);
        $this->endpointFor($job->api_client_id, ['job.failed'], 'https://hooks.test/failed-only');

        dispatch(new ProcessMediaJob($job->id));

        $this->assertSame(JobStatus::Completed, $job->fresh()->status);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($job, $secret): bool {
            $payload = json_decode($request->body(), true);
            $signer = app(WebhookSigner::class);

            return $request->url() === self::URL
                && $request->header('MediaForge-Event-Type')[0] === 'job.completed'
                && $request->header('MediaForge-Event-Id')[0] === $payload['id']
                && $request->header('MediaForge-Delivery-Attempt')[0] === '1'
                && $payload['type'] === 'job.completed'
                && $payload['data']['job']['id'] === $job->public_id
                && $payload['data']['job']['status'] === 'completed'
                && $signer->verify($request->body(), $request->header('MediaForge-Signature')[0], $secret)
                && ! $signer->verify($request->body(), $request->header('MediaForge-Signature')[0], 'whsec_wrong');
        });

        $delivery = WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->sole();
        $this->assertSame(WebhookDeliveryStatus::Succeeded, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(200, $delivery->last_status_code);
    }

    public function test_failed_deliveries_retry_with_backoff_until_exhausted(): void
    {
        Http::fake(['hooks.test/*' => Http::response('boom', 500)]);
        config(['mediaforge.webhooks.max_attempts' => 3, 'mediaforge.webhooks.backoff' => [10, 60]]);

        $job = MediaJob::factory()->create();
        $this->endpointFor($job->api_client_id, ['job.completed']);
        dispatch(new ProcessMediaJob($job->id));

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame(WebhookDeliveryStatus::Pending, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(500, $delivery->last_status_code);
        $this->assertEqualsWithDelta(now()->addSeconds(10)->timestamp, $delivery->next_attempt_at->timestamp, 2);

        DeliverWebhook::dispatchSync($delivery->id);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertEqualsWithDelta(now()->addSeconds(60)->timestamp, $delivery->fresh()->next_attempt_at->timestamp, 2);

        DeliverWebhook::dispatchSync($delivery->id);
        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::Failed, $delivery->status);
        $this->assertSame(3, $delivery->attempts);
        $this->assertNull($delivery->next_attempt_at);
        Http::assertSentCount(3);
    }

    public function test_redeliver_and_ping_endpoints(): void
    {
        Http::fake(['hooks.test/*' => Http::sequence()->push('down', 503)->push('ok', 200)->push('ok', 200)]);
        config(['mediaforge.webhooks.max_attempts' => 1]);
        [, $headers] = $this->apiClient();

        $id = $this->postJson('/api/v1/webhooks', ['url' => self::URL, 'events' => ['job.completed']], $headers)->json('data.id');

        $ping = $this->postJson("/api/v1/webhooks/{$id}/ping", [], $headers)
            ->assertAccepted()
            ->assertJsonPath('data.event_type', 'webhook.ping')
            ->assertJsonPath('data.status', 'failed');

        $this->postJson("/api/v1/webhooks/{$id}/deliveries/{$ping->json('data.id')}/redeliver", [], $headers)
            ->assertAccepted()
            ->assertJsonPath('data.status', 'succeeded');

        $this->postJson("/api/v1/webhooks/{$id}/ping", [], $headers)->assertJsonPath('data.status', 'succeeded');

        $this->getJson("/api/v1/webhooks/{$id}/deliveries?status=succeeded", $headers)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_job_level_webhook_url_targets_only_that_endpoint(): void
    {
        Http::fake(['hooks.test/*' => Http::response('ok', 200)]);
        [$client, $headers] = $this->apiClient();
        $this->endpointFor($client->id, ['job.completed']);
        $this->endpointFor($client->id, ['job.completed'], 'https://hooks.test/other');

        $fileId = $this->post('/api/v1/files', ['file' => $this->fixture('sample.mp4')], $headers)->assertCreated()->json('data.id');

        $this->postJson('/api/v1/jobs', ['file_id' => $fileId, 'operation' => 'analyze', 'webhook_url' => 'https://hooks.test/unknown'], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('webhook_url', 'error.details');

        $this->postJson('/api/v1/jobs', ['file_id' => $fileId, 'operation' => 'analyze', 'webhook_url' => 'https://hooks.test/other'], $headers)
            ->assertAccepted()
            ->assertJsonPath('data.webhook_url', 'https://hooks.test/other');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://hooks.test/other');
    }

    public function test_events_are_not_duplicated_and_skip_clients_without_webhooks(): void
    {
        Http::fake(['hooks.test/*' => Http::response('ok', 200)]);

        $withoutHooks = MediaJob::factory()->create();
        dispatch(new ProcessMediaJob($withoutHooks->id));
        $this->assertSame(0, WebhookEvent::query()->count());

        $job = MediaJob::factory()->create();
        $this->endpointFor($job->api_client_id, ['job.completed']);
        dispatch(new ProcessMediaJob($job->id));
        app(WebhookEmitter::class)->jobFinished($job->fresh());

        $this->assertSame(1, WebhookEvent::query()->count());
        Http::assertSentCount(1);
    }

    public function test_cancelled_job_emits_cancelled_event_and_inactive_endpoints_are_skipped(): void
    {
        Http::fake(['hooks.test/*' => Http::response('ok', 200)]);
        [$client, $headers] = $this->apiClient();
        $this->endpointFor($client->id, ['job.cancelled']);
        [$inactive] = $this->endpointFor($client->id, ['job.cancelled'], 'https://hooks.test/inactive');
        $inactive->forceFill(['is_active' => false])->save();

        $job = MediaJob::factory()->for($client, 'apiClient')->create([
            'input_asset_id' => MediaAsset::factory()->for($client, 'apiClient'),
            'scheduled_at' => now()->addHour(),
        ]);

        $this->postJson("/api/v1/jobs/{$job->public_id}/cancel", [], $headers)->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::URL
            && $request->header('MediaForge-Event-Type')[0] === 'job.cancelled');
    }

    public function test_disabled_endpoint_at_send_time_marks_delivery_failed(): void
    {
        Http::fake();
        $job = MediaJob::factory()->create();
        [$endpoint] = $this->endpointFor($job->api_client_id, ['job.completed']);

        $event = WebhookEvent::query()->forceCreate([
            'api_client_id' => $job->api_client_id, 'type' => 'job.completed', 'dedupe_key' => 'k', 'payload' => ['id' => 'evt_x'],
        ]);
        $delivery = $event->deliveries()->create(['webhook_endpoint_id' => $endpoint->id, 'status' => WebhookDeliveryStatus::Pending]);
        $endpoint->delete();

        DeliverWebhook::dispatchSync($delivery->id);

        $this->assertSame(WebhookDeliveryStatus::Failed, $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_signature_rejects_old_timestamps(): void
    {
        $signer = new WebhookSigner;
        $header = $signer->header('{}', ['whsec_a', 'whsec_b'], 1_000);

        $this->assertTrue($signer->verify('{}', $header, 'whsec_b', 300, 1_100));
        $this->assertFalse($signer->verify('{}', $header, 'whsec_a', 300, 2_000));
        $this->assertFalse($signer->verify('{"x":1}', $header, 'whsec_a', 300, 1_100));
    }

    /**
     * @param  list<string>  $events
     * @return array{WebhookEndpoint, string}
     */
    private function endpointFor(int $clientId, array $events, string $url = self::URL): array
    {
        $secret = WebhookSigner::generateSecret();
        $endpoint = new WebhookEndpoint(['url' => $url, 'events' => $events, 'is_active' => true, 'secret' => $secret]);
        $endpoint->api_client_id = $clientId;
        $endpoint->save();

        return [$endpoint, $secret];
    }
}
