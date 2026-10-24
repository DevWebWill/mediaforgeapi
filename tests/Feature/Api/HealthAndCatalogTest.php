<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthAndCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_public_and_does_not_leak_details(): void
    {
        Storage::fake('media');

        $response = $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['status', 'checks' => ['database', 'queue', 'storage', 'ffmpeg', 'ffprobe'], 'timestamp']);

        $body = $response->getContent();
        $this->assertStringNotContainsString('/usr/bin', $body);
        $this->assertStringNotContainsString((string) config('database.connections.mysql.password'), $body === '' ? 'x' : $body.'never');
    }

    public function test_health_reports_degraded_with_503_when_dependency_fails(): void
    {
        Storage::fake('media');
        config(['mediaforge.binaries.ffmpeg' => '/nonexistent/ffmpeg']);

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.ffmpeg', 'fail');
    }

    public function test_catalog_lists_operations_and_profiles(): void
    {
        [, $headers] = $this->apiClient();

        $this->getJson('/api/v1/operations', $headers)->assertOk()->assertJsonCount(7, 'data');
        $this->getJson('/api/v1/operations/compress', $headers)->assertOk()->assertJsonPath('data.name', 'compress');
        $this->getJson('/api/v1/operations/nope', $headers)->assertNotFound();

        $this->getJson('/api/v1/profiles', $headers)->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/v1/profiles/tiktok', $headers)
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonStructure(['data' => ['operations', 'parameters', 'notice']]);
    }
}
