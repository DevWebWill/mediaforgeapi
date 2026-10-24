<?php

namespace App\Jobs;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\Exceptions\UnsafeWebhookUrlException;
use App\Services\Webhooks\WebhookSigner;
use App\Services\Webhooks\WebhookUrlGuard;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Entrega un evento a un endpoint. Semántica at-least-once: el receptor debe
 * deduplicar por MediaForge-Event-Id. Los reintentos usan backoff exponencial y
 * el estado de cada entrega queda en webhook_deliveries.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(public readonly int $deliveryId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDays(2);
    }

    public function handle(WebhookSigner $signer, WebhookUrlGuard $guard): void
    {
        $delivery = WebhookDelivery::query()->with(['event', 'endpoint'])->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== WebhookDeliveryStatus::Pending) {
            return;
        }

        $endpoint = $delivery->endpoint;

        if ($endpoint === null || $endpoint->trashed() || ! $endpoint->is_active) {
            $this->finish($delivery, WebhookDeliveryStatus::Failed, error: 'El endpoint está desactivado o eliminado.');

            return;
        }

        $attempt = $delivery->attempts + 1;
        $body = json_encode($delivery->event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $started = hrtime(true);
        $statusCode = null;

        try {
            // Se revalida en cada envío (el DNS puede haber cambiado) y se fija la IP validada
            $target = $guard->inspect($endpoint->url);

            $curl = $target['ips'] !== []
                ? [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target['host'], $target['port'], $this->curlIp($target['ips'][0]))]]
                : [];

            $response = Http::withOptions([
                'allow_redirects' => false,
                'connect_timeout' => (int) config('mediaforge.webhooks.connect_timeout'),
                'timeout' => (int) config('mediaforge.webhooks.timeout'),
                'curl' => $curl,
            ])->withHeaders([
                'User-Agent' => 'MediaForge-Webhooks/1.0',
                'MediaForge-Event-Id' => $delivery->event->public_id,
                'MediaForge-Event-Type' => $delivery->event->type,
                'MediaForge-Delivery-Id' => $delivery->public_id,
                'MediaForge-Delivery-Attempt' => (string) $attempt,
                'MediaForge-Signature' => $signer->header($body, $endpoint->signingSecrets(), $timestamp),
            ])->withBody($body, 'application/json')->post($endpoint->url);

            $statusCode = $response->status();
            $error = $response->successful() ? null : 'Respuesta HTTP '.$statusCode;
        } catch (UnsafeWebhookUrlException $e) {
            $this->finish($delivery, WebhookDeliveryStatus::Failed, $attempt, error: $e->getMessage());

            return;
        } catch (ConnectionException $e) {
            $error = 'Error de conexión o timeout.';
        } catch (Throwable $e) {
            report($e);
            $error = 'Error interno al enviar.';
        }

        $durationMs = intdiv(hrtime(true) - $started, 1_000_000);

        if ($error === null) {
            $this->finish($delivery, WebhookDeliveryStatus::Succeeded, $attempt, $statusCode, null, $durationMs);

            return;
        }

        $maxAttempts = (int) config('mediaforge.webhooks.max_attempts');

        if ($attempt >= $maxAttempts) {
            $this->finish($delivery, WebhookDeliveryStatus::Failed, $attempt, $statusCode, $error, $durationMs);

            return;
        }

        $backoff = (array) config('mediaforge.webhooks.backoff');
        $delay = (int) ($backoff[$attempt - 1] ?? end($backoff));

        $delivery->forceFill([
            'attempts' => $attempt,
            'last_status_code' => $statusCode,
            'last_error' => $error,
            'last_duration_ms' => $durationMs,
            'next_attempt_at' => now()->addSeconds($delay),
        ])->save();

        Log::info('webhook.retry_scheduled', ['delivery_id' => $delivery->public_id, 'attempt' => $attempt, 'status' => $statusCode]);

        $this->release($delay);
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery !== null && $delivery->status === WebhookDeliveryStatus::Pending) {
            $this->finish($delivery, WebhookDeliveryStatus::Failed, error: 'La entrega se interrumpió.');
        }
    }

    private function finish(WebhookDelivery $delivery, WebhookDeliveryStatus $status, ?int $attempts = null, ?int $statusCode = null, ?string $error = null, ?int $durationMs = null): void
    {
        $delivery->forceFill([
            'status' => $status,
            'attempts' => $attempts ?? $delivery->attempts,
            'last_status_code' => $statusCode ?? $delivery->last_status_code,
            'last_error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'last_duration_ms' => $durationMs ?? $delivery->last_duration_ms,
            'next_attempt_at' => null,
            'delivered_at' => $status === WebhookDeliveryStatus::Succeeded ? now() : null,
        ])->save();

        Log::info('webhook.delivery_finished', [
            'delivery_id' => $delivery->public_id,
            'status' => $status->value,
            'attempts' => $delivery->attempts,
            'http_status' => $statusCode,
        ]);
    }

    private function curlIp(string $ip): string
    {
        return str_contains($ip, ':') ? '['.$ip.']' : $ip;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['webhook_delivery:'.$this->deliveryId];
    }
}
