<?php

namespace App\Services\Jobs;

use App\Enums\JobErrorCode;
use App\Enums\JobStatus;
use App\Models\MediaJob;
use App\Services\Webhooks\WebhookEmitter;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Única vía para cambiar el estado de un trabajo.
 *
 * Cada transición se hace en una transacción corta con SELECT ... FOR UPDATE sobre
 * la fila, se valida contra JobStatus::allowedTransitions() y registra un evento.
 * Nunca se mantiene una transacción abierta mientras se ejecuta FFmpeg.
 *
 * El worker_token actúa como token de exclusión: un worker solo puede finalizar el
 * trabajo que reclamó. Si otro worker lo reclama tras considerarlo interrumpido,
 * el primero ya no puede sobrescribir el resultado.
 */
final class JobStateMachine
{
    public function __construct(private readonly WebhookEmitter $webhooks) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $context
     * @param  (Closure(MediaJob): bool)|null  $guard  condición adicional evaluada con la fila bloqueada
     */
    public function transition(
        MediaJob $job,
        JobStatus $to,
        array $attributes = [],
        ?string $message = null,
        array $context = [],
        ?Closure $guard = null,
        string $eventType = 'status_changed',
    ): bool {
        $applied = DB::transaction(function () use ($job, $to, $attributes, $message, $context, $guard, $eventType): bool {
            /** @var MediaJob|null $current */
            $current = MediaJob::query()->whereKey($job->id)->lockForUpdate()->first();

            if ($current === null || ! $current->status->canTransitionTo($to) || ($guard !== null && ! $guard($current))) {
                return false;
            }

            $from = $current->status;
            $current->forceFill([...$attributes, 'status' => $to])->save();

            $current->events()->create([
                'type' => $eventType,
                'from_status' => $from,
                'to_status' => $to,
                'message' => $message,
                'context' => $context ?: null,
            ]);

            $job->setRawAttributes($current->getAttributes(), true);

            return true;
        });

        if ($applied) {
            Log::info('media_job.transition', [
                'job_id' => $job->public_id,
                'status' => $to->value,
                'operation' => $job->operation,
                'attempt' => $job->attempts,
            ]);

            if ($to->isTerminal()) {
                $this->webhooks->jobFinished($job);
            }
        }

        return $applied;
    }

    public function markQueued(MediaJob $job): bool
    {
        return $this->transition($job, JobStatus::Queued, ['queued_at' => now()]);
    }

    /**
     * Reclama el trabajo para un worker. También recupera trabajos "processing"
     * cuyo worker dejó de enviar heartbeat (proceso caído o contenedor reiniciado).
     */
    public function claim(MediaJob $job, string $workerToken): bool
    {
        $staleBefore = now()->subSeconds((int) config('mediaforge.jobs.stale_after_seconds'));

        $exhausted = false;

        $claimed = DB::transaction(function () use ($job, $workerToken, $staleBefore, &$exhausted): bool {
            /** @var MediaJob|null $current */
            $current = MediaJob::query()->whereKey($job->id)->lockForUpdate()->first();

            if ($current === null) {
                return false;
            }

            $isStale = $current->status === JobStatus::Processing
                && ($current->heartbeat_at === null || $current->heartbeat_at->lessThan($staleBefore));

            if (! $current->status->isClaimable() && ! $isStale) {
                return false;
            }

            $from = $current->status;

            // Intentos agotados (p. ej. el worker murió en el último intento): se cierra como fallido
            if ($current->attempts >= $current->max_attempts) {
                $code = $isStale ? JobErrorCode::ProcessingTimeout : JobErrorCode::ProcessingFailed;
                $message = 'Se agotaron los intentos de procesamiento.';
                $current->forceFill([
                    'status' => JobStatus::Failed,
                    'error_code' => $code->value,
                    'error_message' => $message,
                    ...$this->finishAttributes($current, null),
                ])->save();
                $current->events()->create([
                    'type' => 'status_changed',
                    'from_status' => $from,
                    'to_status' => JobStatus::Failed,
                    'message' => $message,
                    'context' => ['error_code' => $code->value],
                ]);
                $job->setRawAttributes($current->getAttributes(), true);
                $exhausted = true;

                return false;
            }

            $current->forceFill([
                'status' => JobStatus::Processing,
                'worker_token' => $workerToken,
                'heartbeat_at' => now(),
                'started_at' => $current->started_at ?? now(),
                'attempts' => $current->attempts + 1,
                'progress' => null,
            ])->save();

            $current->events()->create([
                'type' => $isStale ? 'reclaimed' : 'status_changed',
                'from_status' => $from,
                'to_status' => JobStatus::Processing,
                'message' => $isStale ? 'Trabajo recuperado tras interrupción del worker anterior.' : null,
                'context' => ['attempt' => $current->attempts],
            ]);

            $job->setRawAttributes($current->getAttributes(), true);

            return true;
        });

        if ($exhausted) {
            $this->webhooks->jobFinished($job);
        }

        return $claimed;
    }

    /** @param  array<string, mixed>  $result */
    public function complete(MediaJob $job, string $workerToken, array $result, int $processingMs): bool
    {
        return $this->transition($job, JobStatus::Completed, [
            'result' => $result,
            'progress' => 100,
            'error_code' => null,
            'error_message' => null,
            ...$this->finishAttributes($job, $processingMs),
        ], guard: $this->ownedBy($workerToken));
    }

    public function fail(MediaJob $job, JobErrorCode $code, string $message, ?string $workerToken = null, ?int $processingMs = null): bool
    {
        return $this->transition($job, JobStatus::Failed, [
            'error_code' => $code->value,
            'error_message' => mb_substr($message, 0, 1000),
            ...$this->finishAttributes($job, $processingMs),
        ], message: $message, context: ['error_code' => $code->value], guard: $workerToken !== null ? $this->ownedBy($workerToken) : null);
    }

    public function scheduleRetry(MediaJob $job, string $workerToken, JobErrorCode $code, string $message, int $delaySeconds): bool
    {
        return $this->transition($job, JobStatus::Retrying, [
            'error_code' => $code->value,
            'error_message' => mb_substr($message, 0, 1000),
            'worker_token' => null,
            'heartbeat_at' => null,
        ], message: $message, context: ['error_code' => $code->value, 'retry_in_seconds' => $delaySeconds], guard: $this->ownedBy($workerToken));
    }

    /** Cancelación inmediata de un trabajo que aún no se está ejecutando. */
    public function cancel(MediaJob $job, string $reason = 'Cancelado por el cliente.'): bool
    {
        return $this->transition($job, JobStatus::Cancelled, [
            'cancel_requested_at' => $job->cancel_requested_at ?? now(),
            ...$this->finishAttributes($job, null),
        ], message: $reason, guard: fn (MediaJob $current): bool => $current->status !== JobStatus::Processing);
    }

    /** Confirmación por parte del worker de una cancelación cooperativa. */
    public function confirmCancellation(MediaJob $job, string $workerToken, ?int $processingMs): bool
    {
        return $this->transition($job, JobStatus::Cancelled, [
            ...$this->finishAttributes($job, $processingMs),
        ], message: 'Cancelado durante el procesamiento.', guard: $this->ownedBy($workerToken));
    }

    /**
     * Solicita la cancelación cooperativa de un trabajo en ejecución.
     * El worker la detecta y detiene únicamente su propio proceso.
     */
    public function requestCancellation(MediaJob $job): bool
    {
        return DB::transaction(function () use ($job): bool {
            /** @var MediaJob|null $current */
            $current = MediaJob::query()->whereKey($job->id)->lockForUpdate()->first();

            if ($current === null || $current->status !== JobStatus::Processing) {
                return false;
            }

            if ($current->cancel_requested_at === null) {
                $current->forceFill(['cancel_requested_at' => now()])->save();
                $current->events()->create(['type' => 'cancel_requested', 'message' => 'Cancelación solicitada por el cliente.']);
            }

            $job->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }

    /**
     * Ejecuta $callback dentro de una transacción solo si el worker sigue siendo
     * el propietario del trabajo (fencing). Se usa para registrar salidas: un worker
     * que perdió el trabajo no puede escribir resultados.
     *
     * @param  Closure(MediaJob): void  $callback
     */
    public function whileOwned(MediaJob $job, string $workerToken, Closure $callback): bool
    {
        return DB::transaction(function () use ($job, $workerToken, $callback): bool {
            /** @var MediaJob|null $current */
            $current = MediaJob::query()->whereKey($job->id)->lockForUpdate()->first();

            if ($current === null || ! ($this->ownedBy($workerToken))($current)) {
                return false;
            }

            $callback($current);

            return true;
        });
    }

    /**
     * Reintento manual (administración) de un trabajo fallido. Es la única vía para
     * salir de un estado terminal y no forma parte de las transiciones normales.
     */
    public function retryFailed(MediaJob $job, string $reason): bool
    {
        return DB::transaction(function () use ($job, $reason): bool {
            /** @var MediaJob|null $current */
            $current = MediaJob::query()->whereKey($job->id)->lockForUpdate()->first();

            if ($current === null || $current->status !== JobStatus::Failed) {
                return false;
            }

            $current->forceFill([
                'status' => JobStatus::Queued,
                'attempts' => 0,
                'progress' => null,
                'error_code' => null,
                'error_message' => null,
                'result' => null,
                'worker_token' => null,
                'heartbeat_at' => null,
                'cancel_requested_at' => null,
                'started_at' => null,
                'finished_at' => null,
                'processing_ms' => null,
                'deadline_missed_by_seconds' => null,
                'queued_at' => now(),
            ])->save();

            $current->events()->create([
                'type' => 'manual_retry',
                'from_status' => JobStatus::Failed,
                'to_status' => JobStatus::Queued,
                'message' => $reason,
            ]);

            $job->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }

    /** Deja constancia de que la recuperación volvió a encolar el trabajo. */
    public function recordRequeue(MediaJob $job, string $reason): void
    {
        MediaJob::query()->whereKey($job->id)->update(['queued_at' => now()]);
        $job->events()->create(['type' => 'requeued', 'message' => $reason]);
    }

    public function heartbeat(MediaJob $job, string $workerToken, ?int $progress = null): void
    {
        $attributes = ['heartbeat_at' => now()];
        if ($progress !== null) {
            $attributes['progress'] = max(0, min(99, $progress));
        }

        MediaJob::query()
            ->whereKey($job->id)
            ->where('status', JobStatus::Processing)
            ->where('worker_token', $workerToken)
            ->update($attributes);
    }

    public function isCancellationRequested(MediaJob $job): bool
    {
        return MediaJob::query()->whereKey($job->id)->whereNotNull('cancel_requested_at')->exists();
    }

    /** @return Closure(MediaJob): bool */
    private function ownedBy(string $workerToken): Closure
    {
        return fn (MediaJob $current): bool => $current->status === JobStatus::Processing
            && $current->worker_token !== null
            && hash_equals($current->worker_token, $workerToken);
    }

    /** @return array<string, mixed> */
    private function finishAttributes(MediaJob $job, ?int $processingMs): array
    {
        $finishedAt = now();
        $attributes = [
            'finished_at' => $finishedAt,
            'worker_token' => null,
            'heartbeat_at' => null,
        ];

        if ($processingMs !== null) {
            $attributes['processing_ms'] = $processingMs;
        }

        if ($job->processing_deadline !== null && $finishedAt->greaterThan($job->processing_deadline)) {
            $attributes['deadline_missed_by_seconds'] = (int) $job->processing_deadline->diffInSeconds($finishedAt);
        }

        return $attributes;
    }
}
