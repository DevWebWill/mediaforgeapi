<?php

namespace App\Services\Jobs;

use App\Enums\JobStatus;
use App\Jobs\ProcessMediaJob;
use App\Models\MediaJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía un MediaJob a la cola respetando su prioridad y scheduled_at.
 * Si Redis falla el trabajo sigue persistido en MySQL y la recuperación lo reenviará.
 */
final class JobDispatcher
{
    public function __construct(private readonly JobStateMachine $stateMachine) {}

    public function dispatch(MediaJob $job): bool
    {
        try {
            $pending = ProcessMediaJob::dispatch($job->id)->onQueue($job->queue);

            if ($job->scheduled_at !== null && $job->scheduled_at->isFuture()) {
                $pending->delay($job->scheduled_at);
            }

            // El envío real ocurre al destruir el PendingDispatch: se fuerza dentro del try
            unset($pending);
        } catch (Throwable $e) {
            report($e);
            Log::warning('media_job.dispatch_failed', ['job_id' => $job->public_id]);

            return false;
        }

        // Con la cola síncrona (o un worker muy rápido) el trabajo puede haber avanzado ya
        $job->refresh();
        if ($job->status === JobStatus::Pending) {
            $this->stateMachine->markQueued($job);
        }

        return true;
    }
}
