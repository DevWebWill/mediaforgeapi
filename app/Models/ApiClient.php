<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\JobPriority;
use App\Models\Concerns\HasPublicId;
use Database\Factories\ApiClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aplicación consumidora de la API.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property ClientStatus $status
 */
class ApiClient extends Model
{
    /** @use HasFactory<ApiClientFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'name',
        'status',
        'rate_limit_per_minute',
        'max_active_jobs',
        'storage_quota_bytes',
        'max_upload_bytes',
        'max_priority',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    public static function publicIdPrefix(): string
    {
        return 'cli';
    }

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'rate_limit_per_minute' => 'integer',
            'max_active_jobs' => 'integer',
            'storage_quota_bytes' => 'integer',
            'max_upload_bytes' => 'integer',
            'max_priority' => JobPriority::class,
        ];
    }

    /** @return HasMany<ApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /** @return HasMany<MediaJob, $this> */
    public function mediaJobs(): HasMany
    {
        return $this->hasMany(MediaJob::class);
    }

    /** @return HasMany<MediaAsset, $this> */
    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /** @return HasMany<WebhookEndpoint, $this> */
    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    public function isActive(): bool
    {
        return $this->status === ClientStatus::Active;
    }

    public function rateLimitPerMinute(): int
    {
        return $this->rate_limit_per_minute ?? (int) config('mediaforge.client_defaults.rate_limit_per_minute');
    }

    public function maxActiveJobs(): int
    {
        return $this->max_active_jobs ?? (int) config('mediaforge.client_defaults.max_active_jobs');
    }

    public function storageQuotaBytes(): int
    {
        return $this->storage_quota_bytes ?? (int) config('mediaforge.client_defaults.storage_quota_bytes');
    }

    public function maxUploadBytes(): int
    {
        $global = (int) config('mediaforge.uploads.max_bytes');

        return $this->max_upload_bytes !== null ? min($this->max_upload_bytes, $global) : $global;
    }

    public function maxPriority(): JobPriority
    {
        return $this->max_priority ?? JobPriority::from(config('mediaforge.client_defaults.max_priority'));
    }
}
