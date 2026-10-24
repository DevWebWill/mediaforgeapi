<?php

namespace App\Models;

use App\Enums\AssetKind;
use App\Models\Concerns\HasPublicId;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Archivo almacenado (original subido, resultado o miniatura).
 *
 * @property int $id
 * @property string $public_id
 * @property int $api_client_id
 * @property int|null $media_job_id
 * @property AssetKind $kind
 * @property string $disk
 * @property string $path
 * @property string|null $original_name
 * @property string $extension
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $checksum_sha256
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property-read MediaJob|null $mediaJob
 */
class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'media_job_id',
        'kind',
        'disk',
        'path',
        'original_name',
        'extension',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'metadata',
        'expires_at',
    ];

    public static function publicIdPrefix(): string
    {
        return 'file';
    }

    protected function casts(): array
    {
        return [
            'kind' => AssetKind::class,
            'size_bytes' => 'integer',
            'metadata' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApiClient, $this> */
    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    /** @return BelongsTo<MediaJob, $this> */
    public function mediaJob(): BelongsTo
    {
        return $this->belongsTo(MediaJob::class);
    }

    /** Aislamiento por cliente: toda consulta de la API debe pasar por aquí. */
    public function scopeOwnedBy(Builder $query, ApiClient $client): Builder
    {
        return $query->where($this->qualifyColumn('api_client_id'), $client->id);
    }
}
