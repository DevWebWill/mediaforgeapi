<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Media\Profiles\ProfileRegistry;
use App\Models\MediaAsset;
use App\Models\MediaJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pruebas de integración con FFmpeg/ffprobe reales sobre las fixtures.
 * La cola es síncrona: el trabajo se procesa dentro de la petición POST /jobs.
 */
class FfmpegProcessingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config(['mediaforge.processor' => 'ffmpeg', 'mediaforge.ffmpeg.nice' => false]);
        [, $this->headers] = $this->apiClient();
    }

    public function test_upload_stores_probed_metadata(): void
    {
        $this->post('/api/v1/files', ['file' => $this->fixture('sample.mp4')], $this->headers)
            ->assertCreated()
            ->assertJsonPath('data.metadata.width', 320)
            ->assertJsonPath('data.metadata.height', 240)
            ->assertJsonPath('data.metadata.has_audio', true)
            ->assertJsonPath('data.metadata.video_codec', 'h264');
    }

    public function test_corrupt_upload_is_rejected_and_not_stored(): void
    {
        $this->post('/api/v1/files', ['file' => $this->fixture('corrupt.mp4')], $this->headers)
            ->assertStatus(415)
            ->assertJsonPath('error.code', 'unsupported_media_type');

        $this->assertSame(0, MediaAsset::query()->count());
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_upload_longer_than_limit_is_rejected(): void
    {
        config(['mediaforge.limits.max_duration_seconds' => 1]);

        $this->post('/api/v1/files', ['file' => $this->fixture('sample.mp4')], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'media_limit_exceeded');
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $expected  metadatos esperados en la salida
     */
    #[DataProvider('operations')]
    public function test_operation_produces_valid_output(string $fixture, string $operation, array $parameters, string $kind, string $extension, array $expected): void
    {
        $job = $this->runJob($fixture, $operation, $parameters);

        $this->assertSame(JobStatus::Completed, $job->status, (string) $job->error_message);
        $this->assertSame(100, $job->progress);

        $output = $job->outputs()->sole();
        $this->assertSame($kind, $output->kind->value);
        $this->assertSame($extension, $output->extension);
        $this->assertGreaterThan(0, $output->size_bytes);
        $this->assertNotNull($output->expires_at);
        Storage::disk('media')->assertExists($output->path);
        $this->assertStringStartsWith($job->apiClient->public_id.'/results/'.$job->public_id.'/', $output->path);

        foreach ($expected as $key => $value) {
            $this->assertEqualsWithDelta($value, $output->metadata[$key] ?? null, is_float($value) ? 0.15 : 0, "metadata.{$key}");
        }

        $this->assertSame($output->public_id, $job->result['outputs'][0]['file_id']);
        // El directorio temporal se elimina siempre
        $this->assertSame([], Storage::disk('media')->allFiles('tmp'));
    }

    /** @return iterable<string, array<mixed>> */
    public static function operations(): iterable
    {
        yield 'compress h264' => ['sample.mp4', 'compress', ['max_width' => 160, 'preset' => 'veryfast'], 'result', 'mp4', ['width' => 160, 'height' => 120, 'video_codec' => 'h264', 'audio_codec' => 'aac']];
        yield 'compress keeps smaller size' => ['sample.mp4', 'compress', ['max_width' => 1920, 'preset' => 'veryfast'], 'result', 'mp4', ['width' => 320, 'height' => 240]];
        yield 'convert webm' => ['sample.mp4', 'convert', ['format' => 'webm', 'preset' => 'veryfast'], 'result', 'webm', ['video_codec' => 'vp9', 'audio_codec' => 'opus']];
        yield 'convert h265 mkv' => ['sample.mp4', 'convert', ['format' => 'mkv', 'video_codec' => 'h265', 'preset' => 'veryfast'], 'result', 'mkv', ['video_codec' => 'hevc']];
        yield 'resize crop vertical' => ['sample.mp4', 'resize', ['width' => 180, 'height' => 320, 'strategy' => 'crop', 'preset' => 'veryfast', 'fps' => 15], 'result', 'mp4', ['width' => 180, 'height' => 320, 'fps' => 15.0]];
        yield 'resize pad' => ['sample.mp4', 'resize', ['width' => 320, 'height' => 320, 'strategy' => 'pad', 'pad_color' => '#FFFFFF', 'preset' => 'veryfast'], 'result', 'mp4', ['width' => 320, 'height' => 320]];
        yield 'resize fit' => ['sample.mp4', 'resize', ['width' => 200, 'height' => 200, 'strategy' => 'fit', 'preset' => 'veryfast'], 'result', 'mp4', ['width' => 200, 'height' => 150]];
        yield 'resize without audio' => ['sample-noaudio.mp4', 'resize', ['width' => 120, 'height' => 160, 'preset' => 'veryfast'], 'result', 'mp4', ['width' => 120, 'height' => 160, 'has_audio' => false]];
        yield 'trim accurate' => ['sample.mp4', 'trim', ['start' => 0.5, 'duration' => 1, 'preset' => 'veryfast'], 'result', 'mp4', ['duration_seconds' => 1.0]];
        yield 'trim fast' => ['sample.mp4', 'trim', ['start' => 0, 'end' => 1, 'mode' => 'fast'], 'result', 'mp4', ['video_codec' => 'h264']];
        yield 'extract mp3' => ['sample.mp4', 'extract_audio', ['format' => 'mp3', 'bitrate_kbps' => 128], 'result', 'mp3', ['audio_codec' => 'mp3', 'has_video' => false, 'duration_seconds' => 2.0]];
        yield 'extract m4a from audio file' => ['sample.mp3', 'extract_audio', ['format' => 'm4a'], 'result', 'm4a', ['audio_codec' => 'aac', 'has_video' => false]];
        yield 'thumbnail representative' => ['sample.mp4', 'thumbnail', [], 'thumbnail', 'jpg', ['width' => 320, 'height' => 240]];
        yield 'thumbnail at time png' => ['sample.mp4', 'thumbnail', ['mode' => 'at_time', 'time' => 1.5, 'width' => 100, 'height' => 100, 'strategy' => 'crop', 'format' => 'png'], 'thumbnail', 'png', ['width' => 100, 'height' => 100]];
        yield 'thumbnail webp width only' => ['sample.mp4', 'thumbnail', ['width' => 160, 'format' => 'webp'], 'thumbnail', 'webp', ['width' => 160, 'height' => 120]];
    }

    public function test_analyze_returns_metadata_without_outputs(): void
    {
        $job = $this->runJob('sample.mp4', 'analyze', []);

        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertSame(0, $job->outputs()->count());
        $this->assertSame(320, $job->result['media']['width']);
        $this->assertEqualsWithDelta(2.0, $job->result['media']['duration_seconds'], 0.1);
        $this->assertTrue($job->result['media']['has_audio']);
    }

    public function test_result_endpoint_and_download_of_output(): void
    {
        $job = $this->runJob('sample.mp4', 'thumbnail', []);

        $fileId = $this->getJson("/api/v1/jobs/{$job->public_id}/result", $this->headers)
            ->assertOk()
            ->assertJsonPath('data.outputs.0.kind', 'thumbnail')
            ->json('data.outputs.0.id');

        $this->get("/api/v1/files/{$fileId}/download", $this->headers)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    /** @param  array<string, mixed>  $parameters */
    #[DataProvider('inputErrors')]
    public function test_input_errors_fail_without_retry(string $fixture, string $operation, array $parameters, string $errorCode): void
    {
        $job = $this->runJob($fixture, $operation, $parameters);

        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertSame($errorCode, $job->error_code);
        $this->assertSame(1, $job->attempts);
        $this->assertSame(0, $job->outputs()->count());
    }

    /** @return iterable<string, array<mixed>> */
    public static function inputErrors(): iterable
    {
        yield 'trim start beyond duration' => ['sample.mp4', 'trim', ['start' => 5, 'duration' => 1], 'input_invalid'];
        yield 'trim end beyond duration' => ['sample.mp4', 'trim', ['start' => 0, 'end' => 10], 'input_invalid'];
        yield 'thumbnail beyond duration' => ['sample.mp4', 'thumbnail', ['mode' => 'at_time', 'time' => 9], 'input_invalid'];
        yield 'video operation on audio file' => ['sample.mp3', 'compress', [], 'input_unsupported'];
        yield 'thumbnail of audio file' => ['sample.mp3', 'thumbnail', [], 'input_unsupported'];
        yield 'extract audio without audio' => ['sample-noaudio.mp4', 'extract_audio', [], 'input_unsupported'];
        yield 'fast trim incompatible container' => ['sample.mp4', 'trim', ['start' => 0, 'end' => 1, 'mode' => 'fast', 'format' => 'webm'], 'input_unsupported'];
    }

    public function test_profile_max_duration_is_enforced(): void
    {
        config(['mediaforge.profiles.tiktok.max_duration_seconds' => 1]);
        $this->refreshApplicationRegistries();

        $fileId = $this->upload('sample.mp4');
        $jobId = $this->postJson('/api/v1/jobs', ['file_id' => $fileId, 'operation' => 'resize', 'profile' => 'tiktok'], $this->headers)
            ->assertAccepted()
            ->json('data.id');

        $job = MediaJob::query()->where('public_id', $jobId)->sole();
        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertSame('input_unsupported', $job->error_code);
    }

    public function test_missing_input_file_fails_permanently(): void
    {
        $fileId = $this->upload('sample.mp4');
        $asset = MediaAsset::query()->where('public_id', $fileId)->sole();
        Storage::disk('media')->delete($asset->path);

        $jobId = $this->postJson('/api/v1/jobs', ['file_id' => $fileId, 'operation' => 'analyze'], $this->headers)->json('data.id');

        $job = MediaJob::query()->where('public_id', $jobId)->sole();
        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertSame('input_not_found', $job->error_code);
    }

    /** @param  array<string, mixed>  $parameters */
    private function runJob(string $fixture, string $operation, array $parameters): MediaJob
    {
        $fileId = $this->upload($fixture);

        $payload = ['file_id' => $fileId, 'operation' => $operation];
        if ($parameters !== []) {
            $payload['parameters'] = $parameters;
        }

        $jobId = $this->postJson('/api/v1/jobs', $payload, $this->headers)->assertAccepted()->json('data.id');

        return MediaJob::query()->where('public_id', $jobId)->sole();
    }

    private function upload(string $fixture): string
    {
        return $this->post('/api/v1/files', ['file' => $this->fixture($fixture)], $this->headers)
            ->assertCreated()
            ->json('data.id');
    }

    /** Los registros son singletons construidos con la configuración: se regeneran tras cambiarla. */
    private function refreshApplicationRegistries(): void
    {
        $this->app->forgetInstance(ProfileRegistry::class);
    }
}
