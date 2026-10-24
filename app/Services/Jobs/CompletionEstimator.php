<?php

namespace App\Services\Jobs;

use App\Enums\JobStatus;
use App\Models\MediaJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Estimación de finalización a partir del histórico real: milisegundos de proceso
 * por megabyte de entrada en los últimos trabajos completados de cada operación,
 * más la cola pendiente por delante. Sin muestras suficientes devuelve null:
 * nunca se inventa una estimación.
 */
class CompletionEstimator
{
    private const int SAMPLE_SIZE = 200;

    private const int CACHE_SECONDS = 300;

    public function estimate(MediaJob $job): ?Carbon
    {
        if ($job->status->isTerminal()) {
            return null;
        }

        $rate = $this->msPerMegabyte($job->operation);
        $size = $job->inputAsset?->size_bytes;

        if ($rate === null || $size === null) {
            return null;
        }

        $ownMs = $rate * $size / 1_048_576;

        if ($job->status === JobStatus::Processing) {
            $remaining = $ownMs * (1 - min(99, (int) $job->progress) / 100);

            return now()->addMilliseconds((int) $remaining);
        }

        $start = $job->scheduled_at !== null && $job->scheduled_at->isFuture() ? $job->scheduled_at->copy() : now();
        $backlogMs = $this->backlogMs($job);

        if ($backlogMs === null) {
            return null;
        }

        return $start->addMilliseconds((int) ($backlogMs + $ownMs));
    }

    /** Trabajo pendiente por delante (misma cola o de mayor prioridad), repartido entre los huecos. */
    private function backlogMs(MediaJob $job): ?float
    {
        $rate = $this->msPerMegabyte(null);
        if ($rate === null) {
            return null;
        }

        $queues = array_values((array) config('mediaforge.jobs.queues'));
        $position = array_search($job->queue, $queues, true);
        $ahead = $position === false ? [$job->queue] : array_slice($queues, 0, $position + 1);

        $bytes = (int) MediaJob::query()
            ->join('media_assets', 'media_assets.id', '=', 'media_jobs.input_asset_id')
            ->whereIn('media_jobs.status', [JobStatus::Pending, JobStatus::Queued, JobStatus::Retrying, JobStatus::Processing])
            ->whereIn('media_jobs.queue', $ahead)
            ->where('media_jobs.id', '<', $job->id)
            ->sum('media_assets.size_bytes');

        return $rate * $bytes / 1_048_576 / max(1, (int) config('mediaforge.jobs.max_concurrent'));
    }

    /** Media de ms por MB; null si no hay suficientes muestras. $operation null = todas. */
    public function msPerMegabyte(?string $operation): ?float
    {
        // -1 indica "sin muestras suficientes" (Cache::remember no guarda null)
        $rate = Cache::remember('mediaforge:estimate:'.($operation ?? '*'), self::CACHE_SECONDS, function () use ($operation): float {
            $samples = DB::table('media_jobs')
                ->join('media_assets', 'media_assets.id', '=', 'media_jobs.input_asset_id')
                ->where('media_jobs.status', JobStatus::Completed->value)
                ->whereNotNull('media_jobs.processing_ms')
                ->where('media_assets.size_bytes', '>', 0)
                ->when($operation !== null, fn ($q) => $q->where('media_jobs.operation', $operation))
                ->orderByDesc('media_jobs.id')
                ->limit(self::SAMPLE_SIZE)
                ->get(['media_jobs.processing_ms', 'media_assets.size_bytes']);

            if ($samples->count() < (int) config('mediaforge.jobs.estimate_min_samples', 5)) {
                return -1.0;
            }

            return (float) $samples->avg(fn ($row): float => $row->processing_ms / ($row->size_bytes / 1_048_576));
        });

        return $rate >= 0 ? (float) $rate : null;
    }
}
