<?php

namespace App\Console\Commands;

use App\Enums\JobStatus;
use App\Models\MediaJob;
use App\Services\Jobs\JobDispatcher;
use App\Services\Jobs\JobStateMachine;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Red de seguridad de la cola: reenvía trabajos cuyo mensaje se perdió (Redis caído
 * al crear, datos de Redis perdidos) o cuyo worker murió. Reenviar es seguro porque
 * un mensaje duplicado no puede reclamar un trabajo que ya está en curso o finalizado.
 */
class RecoverJobs extends Command
{
    protected $signature = 'mediaforge:jobs:recover {--limit=500 : Máximo de trabajos por ejecución} {--dry-run : Solo muestra lo que haría}';

    protected $description = 'Reencola trabajos atascados (pending, mensajes perdidos o workers caídos)';

    public function handle(JobDispatcher $dispatcher, JobStateMachine $stateMachine): int
    {
        $limit = (int) $this->option('limit');
        $now = now();
        $pendingBefore = $now->copy()->subSeconds((int) config('mediaforge.jobs.recover.pending_after_seconds'));
        $queuedBefore = $now->copy()->subSeconds((int) config('mediaforge.jobs.recover.queued_after_seconds'));
        $staleBefore = $now->copy()->subSeconds((int) config('mediaforge.jobs.stale_after_seconds'));

        $groups = [
            'pending' => MediaJob::query()->where('status', JobStatus::Pending)->where('created_at', '<', $pendingBefore),
            'queued' => MediaJob::query()->whereIn('status', [JobStatus::Queued, JobStatus::Retrying])
                ->where('updated_at', '<', $queuedBefore)
                ->where(fn (Builder $q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<', $queuedBefore)),
            'stale' => MediaJob::query()->where('status', JobStatus::Processing)
                ->where(fn (Builder $q) => $q->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<', $staleBefore)),
        ];

        $total = 0;

        foreach ($groups as $reason => $query) {
            $jobs = $query->orderBy('id')->limit(max(0, $limit - $total))->get();

            foreach ($jobs as $job) {
                $this->line(sprintf('%s %s (%s)', $this->option('dry-run') ? '[dry-run]' : 'Reencolado', $job->public_id, $reason));

                if ($this->option('dry-run')) {
                    continue;
                }

                if ($dispatcher->dispatch($job) && $reason !== 'pending') {
                    $stateMachine->recordRequeue($job, "Reencolado por recuperación automática ({$reason}).");
                }
            }

            $total += $jobs->count();
        }

        $this->info("Trabajos reencolados: {$total}");

        return self::SUCCESS;
    }
}
