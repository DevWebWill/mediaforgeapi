<?php

namespace Tests\Unit;

use App\Media\Operations\OperationRegistry;
use App\Media\Profiles\ProfileRegistry;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MediaOperationsTest extends TestCase
{
    private function resolve(string $operation, array $input): array
    {
        return app(OperationRegistry::class)->get($operation)->resolveParameters($input);
    }

    public function test_all_profiles_reference_registered_operations_and_valid_parameters(): void
    {
        $operations = app(OperationRegistry::class);

        foreach (app(ProfileRegistry::class)->all() as $profile) {
            foreach ($profile->operations as $name) {
                $operation = $operations->get($name);
                $this->assertIsArray($operation->resolveParameters($profile->parametersFor($operation)), "{$profile->id}/{$name}");
            }
        }
    }

    public function test_unknown_parameters_are_rejected(): void
    {
        try {
            $this->resolve('compress', ['vf' => 'movie=/etc/passwd']);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('parameters.vf', $e->errors());
        }
    }

    public function test_codec_allowlist_is_enforced(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('compress', ['video_codec' => 'rawvideo']);
    }

    public function test_encoding_controls_reject_out_of_range_values(): void
    {
        foreach (['max_video_bitrate_kbps' => 25001, 'audio_sample_rate' => 96000, 'audio_channels' => 6, 'deinterlace' => 'invalid'] as $key => $value) {
            try {
                $this->resolve('convert', ['format' => 'mp4', $key => $value]);
                $this->fail('Invalid encoding control accepted: '.$key);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('parameters.'.$key, $e->errors());
            }
        }
    }

    public function test_cbr_requires_h264_and_excludes_separate_max_bitrate(): void
    {
        foreach ([['format' => 'webm'], ['format' => 'mp4', 'max_video_bitrate_kbps' => 1000]] as $parameters) {
            try {
                $this->resolve('convert', [...$parameters, 'constant_video_bitrate_kbps' => 1000]);
                $this->fail('Conflicting CBR encoding control accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('parameters.constant_video_bitrate_kbps', $e->errors());
            }
        }
    }

    public function test_compress_applies_defaults(): void
    {
        $params = $this->resolve('compress', []);

        $this->assertSame('mp4', $params['format']);
        $this->assertArrayHasKey('crf', $params);
    }

    public function test_trim_requires_end_after_start(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('trim', ['start' => 10, 'end' => 5]);
    }

    public function test_trim_rejects_end_and_duration_together(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('trim', ['start' => 0, 'end' => 5, 'duration' => 5]);
    }

    public function test_resize_requires_even_dimensions(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('resize', ['width' => 1081, 'height' => 1920]);
    }

    public function test_client_parameters_override_profile_defaults(): void
    {
        $operation = app(OperationRegistry::class)->get('resize');
        $profile = app(ProfileRegistry::class)->get('tiktok');

        $params = $operation->resolveParameters([...$profile->parametersFor($operation), 'crf' => 28]);

        $this->assertSame(28, $params['crf']);
        $this->assertSame(1080, $params['width']);
    }
}
