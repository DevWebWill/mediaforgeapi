<?php

namespace App\Models;

use App\Enums\JobPriority;
use App\Enums\JobStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\MediaJobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Trabajo de procesamiento multimedia. MySQL es la fuente de verdad de su estado;
 * Redis solo transporta el mensaje hasta el worker.
 *
 * @property int $id
 * @property string $public_id
 * @property int $api_client_id
 * @property int $input_asset_id
 * @property string $operation
 * @property string|null $profile
 * @property int|null $profile_version
 * @property array<string, mixed> $parameters
 * @property JobStatus $status
 * @property JobPriority $priority
 * @property string $queue
 * @property string|null $reference
 * @property string|null $request_id
 * @property string|null $idempotency_key
 * @property string|null $request_fingerprint
 * @property Carbon|null $idempotency_expires_at
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $processing_deadline
 * @property int|null $deadline_missed_by_seconds
 * @property int|null $progress
 * @property int $attempts
 * @property int $max_attempts
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array<string, mixed>|null $result
 * @property string|null $worker_token
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $processing_ms
 * @property Carbon $created_at
 * @property-read MediaAsset $inputAsset
 * @property-read ApiClient $apiClient
 */
class MediaJob extends Model
{
    /** @use HasFactory<MediaJobFactory> */
    use HasFactory, HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected $attributes = [
        'attempts' => 0,
    ];

    public static function publicIdPrefix(): string
    {
        return 'job';
    }

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'result' => 'array',
            'status' => JobStatus::class,
            'priority' => JobPriority::class,
            'profile_version' => 'integer',
            'progress' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'processing_ms' => 'integer',
            'deadline_missed_by_seconds' => 'integer',
            'idempotency_expires_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'processing_deadline' => 'datetime',
            'heartbeat_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApiClient, $this> */
    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function inputAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'input_asset_id')->withTrashed();
    }

    /** @return HasMany<MediaAsset, $this> */
    public function outputs(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function webhookEndpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class);
    }

    /** @return HasMany<MediaJobEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(MediaJobEvent::class);
    }

    public function scopeOwnedBy(Builder $query, ApiClient $client): Builder
    {
        return $query->where($this->qualifyColumn('api_client_id'), $client->id);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), JobStatus::active());
    }
}
