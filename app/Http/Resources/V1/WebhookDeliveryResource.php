<?php

namespace App\Http\Resources\V1;

use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WebhookDelivery */
class WebhookDeliveryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'object' => 'webhook_delivery',
            'event_id' => $this->event->public_id,
            'event_type' => $this->event->type,
            'job_id' => $this->event->payload['data']['job']['id'] ?? null,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'last_status_code' => $this->last_status_code,
            'last_error' => $this->last_error,
            'last_duration_ms' => $this->last_duration_ms,
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
