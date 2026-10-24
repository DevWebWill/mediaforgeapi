<?php

namespace Database\Factories;

use App\Enums\JobPriority;
use App\Enums\JobStatus;
use App\Models\MediaAsset;
use App\Models\MediaJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaJob>
 */
class MediaJobFactory extends Factory
{
    protected $model = MediaJob::class;

    public function definition(): array
    {
        return [
            'input_asset_id' => MediaAsset::factory(),
            'api_client_id' => fn (array $attributes) => MediaAsset::query()->findOrFail($attributes['input_asset_id'])->api_client_id,
            'operation' => 'analyze',
            'parameters' => [],
            'status' => JobStatus::Queued,
            'priority' => JobPriority::Normal,
            'queue' => 'media',
            'max_attempts' => 3,
            'queued_at' => now(),
        ];
    }

    public function status(JobStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
