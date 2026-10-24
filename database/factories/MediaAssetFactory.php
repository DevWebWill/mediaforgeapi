<?php

namespace Database\Factories;

use App\Enums\AssetKind;
use App\Models\ApiClient;
use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    protected $model = MediaAsset::class;

    public function definition(): array
    {
        return [
            'api_client_id' => ApiClient::factory(),
            'kind' => AssetKind::Original,
            'disk' => 'media',
            'path' => 'test/originals/'.Str::lower((string) Str::ulid()).'.mp4',
            'original_name' => 'video.mp4',
            'extension' => 'mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', Str::random()),
        ];
    }
}
