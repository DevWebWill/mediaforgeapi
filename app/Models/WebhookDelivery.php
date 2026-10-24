<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Entrega de un evento a un endpoint (semántica at-least-once).
 *
 * @property int $id
 * @property string $public_id
 * @property int $webhook_event_id
 * @property int $webhook_endpoint_id
 * @property WebhookDeliveryStatus $status
 * @property int $attempts
 * @property int|null $last_status_code
 * @property string|null $last_error
 * @property int|null $last_duration_ms
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $delivered_at
 * @property Carbon $created_at
 * @property-read WebhookEvent $event
 * @property-read WebhookEndpoint|null $endpoint
 */
class WebhookDelivery extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected $attributes = ['attempts' => 0];

    public static function publicIdPrefix(): string
    {
        return 'dlv';
    }

    protected function casts(): array
    {
        return [
            'status' => WebhookDeliveryStatus::class,
            'attempts' => 'integer',
            'last_status_code' => 'integer',
            'last_duration_ms' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WebhookEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class, 'webhook_event_id');
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id')->withTrashed();
    }
}
