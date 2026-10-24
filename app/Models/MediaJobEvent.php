<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Historial de eventos de un trabajo (transiciones y diagnóstico saneado).
 *
 * @property int $id
 * @property int $media_job_id
 * @property string $type
 * @property JobStatus|null $from_status
 * @property JobStatus|null $to_status
 * @property string|null $message
 * @property array<string, mixed>|null $context
 * @property Carbon $created_at
 */
class MediaJobEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['type', 'from_status', 'to_status', 'message', 'context'];

    protected function casts(): array
    {
        return [
            'from_status' => JobStatus::class,
            'to_status' => JobStatus::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MediaJob, $this> */
    public function mediaJob(): BelongsTo
    {
        return $this->belongsTo(MediaJob::class);
    }
}
