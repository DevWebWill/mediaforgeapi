<?php

namespace App\Services\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Http\Resources\V1\MediaJobResource;
use App\Jobs\DeliverWebhook;
use App\Models\MediaJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Crea eventos de webhook y sus entregas. Un fallo aquí nunca debe afectar al
 * trabajo: el estado ya está guardado y el error solo se registra.
 */
class WebhookEmitter
{
    public function jobFinished(MediaJob $job): void
    {
        $type = WebhookEventType::forStatus($job->status);

        if ($type === null) {
            return;
        }

        try {
            $endpoints = $this->endpointsFor($job, $type);

            if ($endpoints->isEmpty()) {
                return;
            }

            $job = $job->fresh(['inputAsset']) ?? $job;
            $dedupeKey = sprintf('%s:%s:%s', $job->public_id, $type->value, $job->finished_at?->format('U.u') ?? '0');

            $this->emit($job->api_client_id, $job->id, $type, $dedupeKey, [
                'job' => (new MediaJobResource($job))->resolve(),
            ], $endpoints);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Evento de prueba para verificar la integración de un endpoint. */
    public function ping(WebhookEndpoint $endpoint): ?WebhookDelivery
    {
        $event = $this->emit($endpoint->api_client_id, null, WebhookEventType::Ping, 'ping:'.$endpoint->public_id.':'.bin2hex(random_bytes(8)), [
            'webhook_id' => $endpoint->public_id,
        ], collect([$endpoint]));

        return $event?->deliveries()->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, WebhookEndpoint>  $endpoints
     */
    private function emit(int $clientId, ?int $jobId, WebhookEventType $type, string $dedupeKey, array $data, Collection $endpoints): ?WebhookEvent
    {
        try {
            [$event, $deliveries] = DB::transaction(function () use ($clientId, $jobId, $type, $dedupeKey, $data, $endpoints): array {
                $event = new WebhookEvent;
                $event->forceFill([
                    'api_client_id' => $clientId,
                    'media_job_id' => $jobId,
                    'type' => $type->value,
                    'dedupe_key' => $dedupeKey,
                    'payload' => [],
                ])->save();

                $event->forceFill(['payload' => [
                    'id' => $event->public_id,
                    'object' => 'event',
                    'type' => $type->value,
                    'created_at' => $event->created_at->toIso8601String(),
                    'data' => $data,
                ]])->save();

                $deliveries = $endpoints->map(fn (WebhookEndpoint $endpoint) => $event->deliveries()->create([
                    'webhook_endpoint_id' => $endpoint->id,
                    'status' => WebhookDeliveryStatus::Pending,
                    'next_attempt_at' => now(),
                ]));

                return [$event, $deliveries];
            });
        } catch (UniqueConstraintViolationException) {
            // Ya emitido para esta transición
            return null;
        }

        foreach ($deliveries as $delivery) {
            DeliverWebhook::dispatch($delivery->id)->onQueue((string) config('mediaforge.webhooks.queue'));
        }

        return $event;
    }

    /** @return Collection<int, WebhookEndpoint> */
    private function endpointsFor(MediaJob $job, WebhookEventType $type): Collection
    {
        $query = WebhookEndpoint::query()
            ->where('api_client_id', $job->api_client_id)
            ->where('is_active', true);

        // Si el trabajo indicó un webhook concreto, solo se notifica a ese
        if ($job->webhook_endpoint_id !== null) {
            $query->whereKey($job->webhook_endpoint_id);
        }

        return $query->get()->filter(fn (WebhookEndpoint $endpoint) => $endpoint->subscribesTo($type->value))->values();
    }
}
