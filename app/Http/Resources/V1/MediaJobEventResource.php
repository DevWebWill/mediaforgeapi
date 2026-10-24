<?php

namespace App\Http\Resources\V1;

use App\Models\MediaJobEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MediaJobEvent */
class MediaJobEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'object' => 'job_event',
            'type' => $this->type,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status?->value,
            'message' => $this->message,
            'context' => $this->context,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
