<?php

namespace App\Jobs;

use App\Enums\JobErrorCode;
use App\Jobs\Middleware\LimitMediaConcurrency;
use App\Media\Processing\Exceptions\PermanentProcessingException;
use App\Media\Processing\Exceptions\ProcessingCancelledException;
use App\Media\Processing\MediaProcessor;
use App\Media\Processing\ProcessingContext;
use App\Models\MediaJob;
use App\Services\Jobs\JobStateMachine;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mensaje de cola que ejecuta un MediaJob. Solo transporta el id: el estado real
 * se lee y se escribe en MySQL a través de JobStateMachine.
 *
 * Reintentos: los gestiona esta clase según el tipo de error (no Laravel), para
 * no repetir errores deterministas; el límite real son los intentos guardados en
 * MySQL (max_attempts). En la cola se usa retryUntil() en lugar de $tries porque
 * las esperas por falta de hueco (concurrencia) o por scheduled_at también cuentan
 * como intentos en Laravel. Laravel solo actúa como red de seguridad (timeout del
 * worker o proceso caído), y en ese caso failed() cierra el trabajo.
 */
class ProcessMediaJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $mediaJobId)
    {
        $this->timeout = (int) config('mediaforge.jobs.timeout');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('mediaforge.jobs.retry_window_hours', 24));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new LimitMediaConcurrency];
    }

    public function handle(JobStateMachine $stateMachine, MediaProcessor $processor): void
    {
        $job = MediaJob::query()->find($this->mediaJobId);

        if ($job === null) {
            return;
        }

        // Programado para más tarde (p. ej. reencolado por recuperación antes de tiempo)
        if ($job->scheduled_at !== null && $job->scheduled_at->isFuture() && ! $job->status->isTerminal()) {
            $this->release(max(1, (int) ceil(now()->diffInSeconds($job->scheduled_at))));

            return;
        }

        $workerToken = Str::random(40);

        if (! $stateMachine->claim($job, $workerToken)) {
            // Cancelado, finalizado o en ejecución por otro worker: nada que hacer
            Log::info('media_job.skip_unclaimable', ['job_id' => $job->public_id, 'status' => $job->status->value]);

            return;
        }

        Context::add('job_id', $job->public_id);
        $startedAt = hrtime(true);
        $elapsedMs = fn (): int => intdiv(hrtime(true) - $startedAt, 1_000_000);

        try {
            $result = $processor->process($job, new ProcessingContext($job, $workerToken, $stateMachine));
        } catch (ProcessingCancelledException) {
            $stateMachine->confirmCancellation($job, $workerToken, $elapsedMs());

            return;
        } catch (PermanentProcessingException $e) {
            $stateMachine->fail($job, $e->errorCode, $e->getMessage(), $workerToken, $elapsedMs());

            return;
        } catch (Throwable $e) {
            report($e);
            $this->handleTransientFailure($stateMachine, $job, $workerToken, $elapsedMs());

            return;
        }

        if ($stateMachine->isCancellationRequested($job)) {
            $stateMachine->confirmCancellation($job, $workerToken, $elapsedMs());

            return;
        }

        $stateMachine->complete($job, $workerToken, $result, $elapsedMs());

        Log::info('media_job.completed', [
            'job_id' => $job->public_id,
            'operation' => $job->operation,
            'profile' => $job->profile,
            'attempt' => $job->attempts,
            'processing_ms' => $job->processing_ms,
            'queue_wait_ms' => $job->queued_at && $job->started_at ? (int) $job->queued_at->diffInMilliseconds($job->started_at) : null,
        ]);
    }

    /**
     * Laravel invoca este método si el worker supera el timeout o se agotan los
     * intentos por fallos no controlados. Garantiza que el trabajo no quede colgado.
     */
    public function failed(?Throwable $exception): void
    {
        $job = MediaJob::query()->find($this->mediaJobId);

        if ($job === null || $job->status->isTerminal()) {
            return;
        }

        app(JobStateMachine::class)->fail(
            $job,
            JobErrorCode::ProcessingTimeout,
            'El procesamiento superó el tiempo máximo o el worker se interrumpió.',
        );
    }

    private function handleTransientFailure(JobStateMachine $stateMachine, MediaJob $job, string $workerToken, int $elapsedMs): void
    {
        $code = JobErrorCode::ProcessingFailed;

        if ($job->attempts >= $job->max_attempts) {
            $stateMachine->fail($job, $code, 'El procesamiento falló tras agotar los reintentos.', $workerToken, $elapsedMs);

            return;
        }

        $backoff = (array) config('mediaforge.jobs.backoff');
        $delay = (int) ($backoff[$job->attempts - 1] ?? end($backoff) ?: 60);

        if ($stateMachine->scheduleRetry($job, $workerToken, $code, 'Error transitorio; se reintentará.', $delay)) {
            $this->release($delay);
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['media_job:'.$this->mediaJobId];
    }

    public function displayName(): string
    {
        return 'ProcessMediaJob#'.$this->mediaJobId;
    }
}
