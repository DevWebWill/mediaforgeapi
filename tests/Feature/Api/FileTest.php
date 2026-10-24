<?php

namespace Tests\Feature\Api;

use App\Enums\JobStatus;
use App\Models\MediaAsset;
use App\Models\MediaJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    public function test_valid_video_is_stored_with_random_name(): void
    {
        [$client, $headers] = $this->apiClient();

        $response = $this->post('/api/v1/files', ['file' => $this->fixture('sample.mp4', 'Mi vídeo.mp4')], $headers)
            ->assertCreated()
            ->assertHeader('Location')
            ->assertJsonPath('data.object', 'file')
            ->assertJsonPath('data.mime_type', 'video/mp4')
            ->assertJsonPath('data.original_name', 'Mi vídeo.mp4')
            ->assertJsonMissingPath('data.path');

        $asset = MediaAsset::query()->wherePublicId($response->json('data.id'))->firstOrFail();

        $this->assertSame($client->id, $asset->api_client_id);
        $this->assertStringStartsWith($client->public_id.'/originals/', $asset->path);
        $this->assertStringNotContainsString('vídeo', $asset->path);
        $this->assertSame(hash_file('sha256', base_path('tests/Fixtures/media/sample.mp4')), $asset->checksum_sha256);
        Storage::disk('media')->assertExists($asset->path);
    }

    public function test_path_traversal_in_filename_does_not_affect_storage_path(): void
    {
        [, $headers] = $this->apiClient();

        $id = $this->post('/api/v1/files', ['file' => $this->fixture('sample.mp4', '../../../etc/evil.mp4')], $headers)
            ->assertCreated()
            ->json('data.id');

        $asset = MediaAsset::query()->wherePublicId($id)->firstOrFail();
        $this->assertStringNotContainsString('..', $asset->path);
        $this->assertSame('evil.mp4', $asset->original_name);
    }

    public function test_disguised_file_is_rejected(): void
    {
        [, $headers] = $this->apiClient();

        $this->post('/api/v1/files', ['file' => $this->fixture('fake.mp4')], $headers)
            ->assertStatus(415)
            ->assertJsonPath('error.code', 'unsupported_media_type');

        $this->assertSame(0, MediaAsset::query()->count());
    }

    public function test_disallowed_extension_is_rejected(): void
    {
        [, $headers] = $this->apiClient();

        $this->post('/api/v1/files', ['file' => $this->fixture('sample.mp4', 'video.php')], $headers)
            ->assertStatus(415);
    }

    public function test_file_over_client_limit_is_rejected(): void
    {
        [, $headers] = $this->apiClient(['max_upload_bytes' => 1000]);

        $this->post('/api/v1/files', ['file' => $this->fixture('sample.mp4')], $headers)
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'file_too_large');
    }

    public function test_storage_quota_is_enforced(): void
    {
        [$client, $headers] = $this->apiClient(['storage_quota_bytes' => 60_000]);
        MediaAsset::factory()->for($client, 'apiClient')->create(['size_bytes' => 50_000]);

        $this->post('/api/v1/files', ['file' => $this->fixture('sample.mp4')], $headers)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'storage_quota_exceeded');
    }

    public function test_missing_file_returns_validation_error(): void
    {
        [, $headers] = $this->apiClient();

        $this->post('/api/v1/files', [], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['file']]]);
    }

    public function test_client_cannot_access_files_of_another_client(): void
    {
        [, $ownerHeaders] = $this->apiClient();
        [, $otherHeaders] = $this->apiClient();

        $id = $this->post('/api/v1/files', ['file' => $this->fixture('sample.mp4')], $ownerHeaders)->json('data.id');

        $this->getJson("/api/v1/files/{$id}", $otherHeaders)->assertNotFound();
        $this->get("/api/v1/files/{$id}/download", $otherHeaders)->assertNotFound();
        $this->deleteJson("/api/v1/files/{$id}", [], $otherHeaders)->assertNotFound();
        $this->getJson('/api/v1/files', $otherHeaders)->assertOk()->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/files/{$id}", $ownerHeaders)->assertOk();
    }

    public function test_owner_can_download_file(): void
    {
        [, $headers] = $this->apiClient();
        $id = $this->post('/api/v1/files', ['file' => $this->fixture('sample.mp4')], $headers)->json('data.id');

        $response = $this->get("/api/v1/files/{$id}/download", $headers)->assertOk();

        $this->assertSame(file_get_contents(base_path('tests/Fixtures/media/sample.mp4')), $response->streamedContent());
    }

    public function test_file_used_by_active_job_cannot_be_deleted(): void
    {
        [$client, $headers] = $this->apiClient();
        $asset = MediaAsset::factory()->for($client, 'apiClient')->create();
        MediaJob::factory()->for($asset, 'inputAsset')->create(['status' => JobStatus::Queued]);

        $this->deleteJson("/api/v1/files/{$asset->public_id}", [], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'file_in_use');
    }

    public function test_unused_file_is_deleted(): void
    {
        [, $headers] = $this->apiClient();
        $id = $this->post('/api/v1/files', ['file' => $this->fixture('sample.mp4')], $headers)->json('data.id');
        $path = MediaAsset::query()->wherePublicId($id)->value('path');

        $this->deleteJson("/api/v1/files/{$id}", [], $headers)->assertNoContent();

        Storage::disk('media')->assertMissing($path);
        $this->getJson("/api/v1/files/{$id}", $headers)->assertNotFound();
    }
}
