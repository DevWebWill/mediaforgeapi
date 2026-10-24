<?php

namespace App\Media\Processing;

use App\Models\MediaJob;
use App\Services\Jobs\JobStateMachine;

/**
 * Canal entre el motor multimedia y el sistema de trabajos: permite notificar
 * progreso (heartbeat) y comprobar cancelaciones sin acoplar el motor a la cola.
 */
final class ProcessingContext
{
    private float $lastCancelCheck = 0.0;

    private bool $cancelRequested = false;

    public function __construct(
        public readonly MediaJob $job,
        private readonly string $workerToken,
        private readonly JobStateMachine $stateMachine,
        private readonly float $cancelCheckIntervalSeconds = 2.0,
    ) {}

    public function heartbeat(?int $progress = null): void
    {
        $this->stateMachine->heartbeat($this->job, $this->workerToken, $progress);
    }

    /** @param  \Closure(MediaJob): void  $callback */
    public function whileOwned(\Closure $callback): bool
    {
        return $this->stateMachine->whileOwned($this->job, $this->workerToken, $callback);
    }

    public function isCancellationRequested(): bool
    {
        if ($this->cancelRequested) {
            return true;
        }

        $now = microtime(true);
        if ($now - $this->lastCancelCheck >= $this->cancelCheckIntervalSeconds) {
            $this->lastCancelCheck = $now;
            $this->cancelRequested = $this->stateMachine->isCancellationRequested($this->job);
        }

        return $this->cancelRequested;
    }
}
