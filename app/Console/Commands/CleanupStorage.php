<?php

namespace App\Console\Commands;

use App\Enums\JobStatus;
use App\Models\MediaAsset;
use App\Models\MediaJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Limpieza periódica:
 * - Archivos caducados (expires_at), salvo los que usa un trabajo activo.
 * - Directorios temporales huérfanos de workers interrumpidos.
 * - Claves de idempotencia caducadas.
 */
class CleanupStorage extends Command
{
    protected $signature = 'mediaforge:cleanup {--dry-run : Solo muestra lo que haría}';

    protected $description = 'Elimina archivos caducados, temporales huérfanos y claves de idempotencia vencidas';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Archivos caducados eliminados: '.$this->expiredAssets($dryRun));
        $this->info('Directorios temporales eliminados: '.$this->orphanWorkspaces($dryRun));
        $this->info('Claves de idempotencia liberadas: '.$this->expiredIdempotencyKeys($dryRun));

        return self::SUCCESS;
    }

    private function expiredAssets(bool $dryRun): int
    {
        $count = 0;

        MediaAsset::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            // Ni originales usados por un trabajo activo ni salidas de un trabajo en curso
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('media_jobs')
                ->whereIn('media_jobs.status', JobStatus::active())
                ->where(fn ($w) => $w->whereColumn('media_jobs.input_asset_id', 'media_assets.id')
                    ->orWhereColumn('media_jobs.id', 'media_assets.media_job_id')))
            ->chunkById(200, function ($assets) use ($dryRun, &$count): void {
                foreach ($assets as $asset) {
                    $count++;
                    $this->line(($dryRun ? '[dry-run] ' : '').'Caducado '.$asset->public_id);

                    if (! $dryRun) {
                        $asset->delete();
                        Storage::disk($asset->disk)->delete($asset->path);
                    }
                }
            });

        return $count;
    }

    private function orphanWorkspaces(bool $dryRun): int
    {
        $disk = Storage::disk((string) config('mediaforge.storage.disk'));
        $threshold = now()->subHours((int) config('mediaforge.retention.tmp_hours'))->getTimestamp();
        $count = 0;

        foreach ($disk->directories('tmp') as $directory) {
            $jobId = basename($directory);
            $inProgress = MediaJob::query()->where('public_id', $jobId)->where('status', JobStatus::Processing)->exists();
            $modified = @filemtime($disk->path($directory));

            if ($inProgress || ($modified !== false && $modified > $threshold)) {
                continue;
            }

            $count++;
            $this->line(($dryRun ? '[dry-run] ' : '').'Temporal huérfano '.$directory);

            if (! $dryRun) {
                $disk->deleteDirectory($directory);
            }
        }

        return $count;
    }

    private function expiredIdempotencyKeys(bool $dryRun): int
    {
        $query = MediaJob::query()->whereNotNull('idempotency_key')->where('idempotency_expires_at', '<', now());

        if ($dryRun) {
            return $query->count();
        }

        return $query->update([
            'idempotency_key' => null,
            'request_fingerprint' => null,
            'idempotency_expires_at' => null,
        ]);
    }
}
