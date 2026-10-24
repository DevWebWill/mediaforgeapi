<?php

namespace App\Http\Resources\V1;

use App\Models\MediaJob;
use App\Services\Jobs\CompletionEstimator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MediaJob */
class MediaJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'object' => 'job',
            'status' => $this->status->value,
            'operation' => $this->operation,
            'profile' => $this->profile,
            'profile_version' => $this->profile_version,
            'parameters' => $this->parameters,
            'priority' => $this->priority->value,
            'reference' => $this->reference,
            'webhook_url' => $this->webhook_endpoint_id !== null ? $this->webhookEndpoint?->url : null,
            'file_id' => $this->inputAsset->public_id,
            'progress' => $this->progress,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'cancel_requested' => $this->cancel_requested_at !== null,
            'error' => $this->error_code !== null ? [
                'code' => $this->error_code,
                'message' => $this->error_message,
            ] : null,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'processing_deadline' => $this->processing_deadline?->toIso8601String(),
            'deadline_missed_by_seconds' => $this->deadline_missed_by_seconds,
            // Basada en el histórico real; null si no hay muestras suficientes
            'estimated_completion_at' => app(CompletionEstimator::class)->estimate($this->resource)?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'processing_ms' => $this->processing_ms,
            'links' => [
                'self' => url("/api/v1/jobs/{$this->public_id}"),
                'result' => url("/api/v1/jobs/{$this->public_id}/result"),
                'cancel' => url("/api/v1/jobs/{$this->public_id}/cancel"),
                'logs' => url("/api/v1/jobs/{$this->public_id}/logs"),
            ],
        ];
    }
}
