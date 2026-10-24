<?php

namespace App\Http\Resources\V1;

use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WebhookEndpoint */
class WebhookEndpointResource extends JsonResource
{
    /** Secreto en claro: solo se incluye al crear o rotar. */
    private ?string $plainSecret = null;

    public function withSecret(string $secret): self
    {
        $this->plainSecret = $secret;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'object' => 'webhook',
            'url' => $this->url,
            'description' => $this->description,
            'events' => $this->events,
            'is_active' => $this->is_active,
            'secret' => $this->when($this->plainSecret !== null, fn () => $this->plainSecret),
            'previous_secret_expires_at' => $this->previous_secret_expires_at?->isFuture()
                ? $this->previous_secret_expires_at->toIso8601String()
                : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
