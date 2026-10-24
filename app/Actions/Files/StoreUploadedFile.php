<?php

namespace App\Actions\Files;

use App\Enums\ApiErrorCode;
use App\Enums\AssetKind;
use App\Exceptions\ApiException;
use App\Media\Ffmpeg\Exceptions\ProbeFailedException;
use App\Media\Ffmpeg\Ffprobe;
use App\Media\Ffmpeg\MediaInfo;
use App\Models\ApiClient;
use App\Models\MediaAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Valida y almacena un archivo subido. El archivo nunca se carga completo en memoria:
 * PHP lo deja en un temporal y se copia por streaming al disco "media".
 */
final class StoreUploadedFile
{
    public function __construct(private readonly Ffprobe $ffprobe) {}

    public function handle(ApiClient $client, UploadedFile $file): MediaAsset
    {
        if (! $file->isValid()) {
            throw new ApiException(ApiErrorCode::UploadFailed, 'La subida del archivo no se completó correctamente.', 400);
        }

        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw new ApiException(ApiErrorCode::UnsupportedMediaType, 'El archivo está vacío.', 415);
        }

        if ($size > $client->maxUploadBytes()) {
            throw new ApiException(ApiErrorCode::FileTooLarge, 'El archivo supera el tamaño máximo permitido.', 413, [
                'max_bytes' => $client->maxUploadBytes(),
            ]);
        }

        [$extension, $mime] = $this->detectFormat($file);
        $media = $this->inspect($file);

        $this->ensureQuota($client, $size);
        $this->ensureFreeSpace($size);

        $diskName = (string) config('mediaforge.storage.disk');
        $disk = Storage::disk($diskName);
        $directory = sprintf('%s/originals/%s', $client->public_id, now()->format('Y/m'));
        $filename = Str::lower((string) Str::ulid()).'.'.$extension;
        $checksum = hash_file('sha256', $file->getRealPath());

        $path = $disk->putFileAs($directory, $file, $filename);

        try {
            return DB::transaction(function () use ($client, $size, $path, $diskName, $file, $extension, $mime, $checksum, $media): MediaAsset {
                // Re-comprobación con la fila del cliente bloqueada para evitar carreras entre subidas
                ApiClient::query()->whereKey($client->id)->lockForUpdate()->first();
                $this->ensureQuota($client, $size);

                return $client->mediaAssets()->create([
                    'kind' => AssetKind::Original,
                    'disk' => $diskName,
                    'path' => $path,
                    'original_name' => $this->displayName($file),
                    'extension' => $extension,
                    'mime_type' => $mime,
                    'size_bytes' => $size,
                    'checksum_sha256' => $checksum,
                    'metadata' => $media->toArray(),
                    'expires_at' => ($hours = (int) config('mediaforge.retention.originals_hours')) > 0 ? now()->addHours($hours) : null,
                ]);
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    /**
     * Extensión declarada en lista permitida + tipo MIME detectado por la firma real
     * (finfo), sin confiar en el Content-Type del cliente.
     *
     * @return array{string, string}
     */
    private function detectFormat(UploadedFile $file): array
    {
        $formats = (array) config('mediaforge.uploads.formats');
        $extension = Str::lower($file->getClientOriginalExtension());

        if (! isset($formats[$extension])) {
            throw new ApiException(ApiErrorCode::UnsupportedMediaType, 'Extensión de archivo no admitida.', 415, [
                'allowed_extensions' => array_keys($formats),
            ]);
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        if (! in_array($mime, $formats[$extension], true)) {
            throw new ApiException(ApiErrorCode::UnsupportedMediaType, 'El contenido del archivo no corresponde a un formato multimedia admitido.', 415);
        }

        return [$extension, $mime];
    }

    /**
     * Analiza el contenido real con ffprobe antes de aceptarlo: rechaza archivos
     * corruptos, sin pistas multimedia o que superan los límites del servicio.
     */
    private function inspect(UploadedFile $file): MediaInfo
    {
        try {
            $media = $this->ffprobe->analyze((string) $file->getRealPath());
        } catch (ProbeFailedException) {
            throw new ApiException(ApiErrorCode::UnsupportedMediaType, 'El archivo está dañado o no es un formato multimedia admitido.', 415);
        }

        if (! in_array($media->demuxer, (array) config('mediaforge.ffmpeg.allowed_demuxers'), true)) {
            throw new ApiException(ApiErrorCode::UnsupportedMediaType, 'El contenedor del archivo no está admitido.', 415);
        }

        if (! $media->hasVideo && ! $media->hasAudio) {
            throw new ApiException(ApiErrorCode::UnsupportedMediaType, 'El archivo no contiene pistas de audio ni de vídeo.', 415);
        }

        $decodeSeconds = (float) config('mediaforge.uploads.decode_check_seconds');
        if ($decodeSeconds > 0) {
            try {
                $this->ffprobe->verifyDecodable((string) $file->getRealPath(), $media, $decodeSeconds);
            } catch (ProbeFailedException) {
                throw new ApiException(ApiErrorCode::UnsupportedMediaType, 'El archivo está dañado o no se puede decodificar.', 415);
            }
        }

        $maxDuration = (int) config('mediaforge.limits.max_duration_seconds');
        if ($maxDuration > 0 && $media->durationSeconds !== null && $media->durationSeconds > $maxDuration) {
            throw new ApiException(ApiErrorCode::MediaLimitExceeded, 'La duración del archivo supera el máximo permitido.', 422, [
                'max_duration_seconds' => $maxDuration,
            ]);
        }

        $maxPixels = (int) config('mediaforge.limits.max_input_pixels');
        if ($media->hasVideo && $maxPixels > 0 && (int) $media->width * (int) $media->height > $maxPixels) {
            throw new ApiException(ApiErrorCode::MediaLimitExceeded, 'La resolución del vídeo supera el máximo permitido.', 422, [
                'max_input_pixels' => $maxPixels,
            ]);
        }

        return $media;
    }

    private function ensureQuota(ApiClient $client, int $size): void
    {
        $used = (int) $client->mediaAssets()->sum('size_bytes');

        if ($used + $size > $client->storageQuotaBytes()) {
            throw new ApiException(ApiErrorCode::StorageQuotaExceeded, 'Se ha superado la cuota de almacenamiento del cliente.', 403, [
                'quota_bytes' => $client->storageQuotaBytes(),
                'used_bytes' => $used,
            ]);
        }
    }

    private function ensureFreeSpace(int $size): void
    {
        $root = config('filesystems.disks.'.config('mediaforge.storage.disk').'.root');
        $free = is_string($root) && is_dir($root) ? @disk_free_space($root) : false;

        // Si no se puede determinar de forma fiable, no se bloquea la subida
        if ($free !== false && $free - $size < (int) config('mediaforge.storage.min_free_bytes')) {
            throw new ApiException(ApiErrorCode::InsufficientStorage, 'El servicio no dispone de espacio suficiente en este momento.', 507);
        }
    }

    /** Nombre solo informativo: nunca se usa para construir rutas. */
    private function displayName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return Str::limit($name !== '' ? $name : 'upload', 255, '');
    }
}
