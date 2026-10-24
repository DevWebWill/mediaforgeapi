<?php

namespace App\Console\Commands;

use App\Models\MediaJob;
use App\Services\Jobs\JobDispatcher;
use App\Services\Jobs\JobStateMachine;
use Illuminate\Console\Command;

class RetryFailedJob extends Command
{
    protected $signature = 'mediaforge:jobs:retry {job : Public id del trabajo fallido} {--reason= : Motivo (queda en el historial)}';

    protected $description = 'Vuelve a encolar un trabajo fallido reiniciando sus intentos';

    public function handle(JobStateMachine $stateMachine, JobDispatcher $dispatcher): int
    {
        $job = MediaJob::query()->where('public_id', $this->argument('job'))->first();

        if ($job === null) {
            $this->error('Trabajo no encontrado.');

            return self::FAILURE;
        }

        $reason = (string) ($this->option('reason') ?: 'Reintento manual desde administración.');

        if (! $stateMachine->retryFailed($job, $reason)) {
            $this->error("Solo se pueden reintentar trabajos fallidos (estado actual: {$job->status->value}).");

            return self::FAILURE;
        }

        if (! $dispatcher->dispatch($job)) {
            $this->warn('El trabajo quedó en cola en MySQL pero no se pudo enviar a Redis; la recuperación lo reenviará.');

            return self::SUCCESS;
        }

        $this->info("Trabajo {$job->public_id} reencolado.");

        return self::SUCCESS;
    }
}
