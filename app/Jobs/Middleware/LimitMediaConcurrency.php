<?php

namespace App\Jobs\Middleware;

use App\Jobs\ProcessMediaJob;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Limita los trabajos FFmpeg simultáneos en todo el servicio, aunque haya varias
 * réplicas del worker. Cada hueco es un lock con TTL en la caché compartida (Redis);
 * si un worker muere, su hueco se libera solo al expirar el TTL.
 */
class LimitMediaConcurrency
{
    public function handle(ProcessMediaJob $job, Closure $next): void
    {
        $slots = max(1, (int) config('mediaforge.jobs.max_concurrent'));
        $ttl = (int) config('mediaforge.jobs.timeout') + 120;

        $lock = $this->acquire($slots, $ttl);

        if ($lock === null) {
            Log::debug('media_job.waiting_for_slot', ['media_job_id' => $job->mediaJobId]);
            $job->release((int) config('mediaforge.jobs.concurrency_wait_seconds', 15));

            return;
        }

        try {
            $next($job);
        } finally {
            $lock->release();
        }
    }

    private function acquire(int $slots, int $ttl): ?Lock
    {
        foreach (range(0, $slots - 1) as $slot) {
            $lock = Cache::lock('mediaforge:ffmpeg-slot:'.$slot, $ttl);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
