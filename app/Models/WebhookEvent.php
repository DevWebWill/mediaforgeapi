<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $api_client_id
 * @property int|null $media_job_id
 * @property string $type
 * @property string $dedupe_key
 * @property array<string, mixed> $payload
 * @property Carbon $created_at
 */
class WebhookEvent extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    public static function publicIdPrefix(): string
    {
        return 'evt';
    }

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /** @return BelongsTo<MediaJob, $this> */
    public function mediaJob(): BelongsTo
    {
        return $this->belongsTo(MediaJob::class);
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
