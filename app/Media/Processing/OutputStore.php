<?php

namespace App\Media\Processing;

use App\Media\Ffmpeg\OutputSpec;
use App\Models\MediaAsset;
use App\Models\MediaJob;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Directorio temporal por intento y registro de las salidas.
 *
 * Las salidas se escriben primero en tmp/{job}/{intento} y solo cuando FFmpeg termina
 * bien se mueven a {cliente}/results/{job}/ y se registran, bajo fencing, como
 * MediaAsset. Las salidas de intentos anteriores se sustituyen, de modo que un
 * reintento nunca deja resultados duplicados.
 */
class OutputStore
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('mediaforge.storage.disk'));
    }

    /** Crea (vacío) el directorio de trabajo del intento y devuelve su ruta absoluta. */
    public function createWorkspace(MediaJob $job): string
    {
        $relative = $this->workspacePath($job);
        $disk = $this->disk();

        $disk->deleteDirectory($relative);
        $disk->makeDirectory($relative);

        return $disk->path($relative);
    }

    public function deleteWorkspace(MediaJob $job): void
    {
        $this->disk()->deleteDirectory('tmp/'.$job->public_id);
    }

    /**
     * @param  array<string, mixed>  $metadata
     *
     * @throws RuntimeException si el worker ya no es propietario del trabajo
     */
    public function register(MediaJob $job, ProcessingContext $context, string $localFile, OutputSpec $spec, array $metadata): MediaAsset
    {
        $disk = $this->disk();
        $diskName = (string) config('mediaforge.storage.disk');
        $client = $job->apiClient;

        $directory = sprintf('%s/results/%s', $client->public_id, $job->public_id);
        $relative = sprintf('%s/%s.%s', $directory, Str::lower((string) Str::ulid()), $spec->extension);

        $disk->makeDirectory($directory);
        $size = (int) filesize($localFile);
        $checksum = (string) hash_file('sha256', $localFile);

        if (! @rename($localFile, $disk->path($relative))) {
            throw new RuntimeException('No se pudo mover la salida al almacenamiento definitivo.');
        }

        $asset = null;
        $replacedPaths = [];
        $retention = (int) config('mediaforge.retention.results_hours');

        $owned = $context->whileOwned(function (MediaJob $current) use (&$asset, &$replacedPaths, $client, $diskName, $relative, $spec, $size, $checksum, $metadata, $retention): void {
            $previous = MediaAsset::query()->where('media_job_id', $current->id)->get();
            foreach ($previous as $old) {
                $replacedPaths[] = $old->path;
                $old->delete();
            }

            $asset = new MediaAsset;
            $asset->forceFill([
                'api_client_id' => $client->id,
                'media_job_id' => $current->id,
                'kind' => $spec->kind,
                'disk' => $diskName,
                'path' => $relative,
                'original_name' => $current->public_id.'.'.$spec->extension,
                'extension' => $spec->extension,
                'mime_type' => $spec->mimeType,
                'size_bytes' => $size,
                'checksum_sha256' => $checksum,
                'metadata' => $metadata ?: null,
                'expires_at' => $retention > 0 ? now()->addHours($retention) : null,
            ])->save();
        });

        if (! $owned || $asset === null) {
            $disk->delete($relative);

            throw new RuntimeException('El trabajo ya no pertenece a este worker; se descarta la salida.');
        }

        $disk->delete($replacedPaths);

        return $asset;
    }

    private function workspacePath(MediaJob $job): string
    {
        return sprintf('tmp/%s/attempt-%d', $job->public_id, $job->attempts);
    }
}
