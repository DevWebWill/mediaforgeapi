<?php

namespace App\Media\Processing;

use App\Enums\JobErrorCode;
use App\Media\Ffmpeg\CommandBuilder;
use App\Media\Ffmpeg\Exceptions\FfmpegFailedException;
use App\Media\Ffmpeg\Exceptions\ProbeFailedException;
use App\Media\Ffmpeg\FfmpegRunner;
use App\Media\Ffmpeg\Ffprobe;
use App\Media\Ffmpeg\MediaInfo;
use App\Media\Operations\OperationRegistry;
use App\Media\Processing\Exceptions\PermanentProcessingException;
use App\Media\Profiles\ProfileRegistry;
use App\Models\MediaAsset;
use App\Models\MediaJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Motor real: analiza la entrada con ffprobe, valida que la operación sea aplicable,
 * ejecuta FFmpeg con progreso/cancelación y registra las salidas.
 *
 * Clasificación de errores:
 * - Entrada inexistente, corrupta, sin el stream necesario o fuera de límites => permanente.
 * - Timeout de FFmpeg => permanente (repetirlo tardaría lo mismo).
 * - Errores de FFmpeg atribuibles a la entrada => permanente; el resto => transitorio.
 */
final class FfmpegMediaProcessor implements MediaProcessor
{
    /** Contenedores de vídeo admitidos y sus códecs (mismo mapa que las operaciones) */
    private const array CONTAINERS = [
        'mp4' => ['video' => ['h264', 'h265'], 'audio' => ['aac']],
        'mov' => ['video' => ['h264', 'h265'], 'audio' => ['aac']],
        'mkv' => ['video' => ['h264', 'h265', 'vp9'], 'audio' => ['aac', 'opus']],
        'webm' => ['video' => ['vp9'], 'audio' => ['opus']],
    ];

    /** Margen para comparar instantes con la duración (redondeos de contenedor) */
    private const float TIME_EPSILON = 0.05;

    public function __construct(
        private readonly Ffprobe $ffprobe,
        private readonly CommandBuilder $builder,
        private readonly FfmpegRunner $runner,
        private readonly OutputStore $outputs,
        private readonly OperationRegistry $operations,
        private readonly ProfileRegistry $profiles,
    ) {}

    public function process(MediaJob $job, ProcessingContext $context): array
    {
        $info = $this->probeInput($job);
        $this->validate($job, $info);

        if ($job->operation === 'analyze') {
            return ['operation' => 'analyze', 'media' => $info->toArray(), 'outputs' => []];
        }

        $context->heartbeat(0);
        $workspace = $this->outputs->createWorkspace($job);

        try {
            $input = $this->inputPath($job->inputAsset);
            $plan = $this->builder->build($job->operation, $job->parameters, $info, $input);

            try {
                $this->runner->run(
                    $plan->arguments,
                    $workspace,
                    $plan->expectedDurationSeconds,
                    onHeartbeat: fn (?int $progress) => $context->heartbeat($progress),
                    shouldCancel: fn (): bool => $context->isCancellationRequested(),
                );
            } catch (ProcessTimedOutException $e) {
                throw new PermanentProcessingException(JobErrorCode::ProcessingTimeout, 'El procesamiento superó el tiempo máximo permitido.', $e);
            } catch (FfmpegFailedException $e) {
                Log::warning('media_job.ffmpeg_failed', [
                    'job_id' => $job->public_id,
                    'exit_code' => $e->exitCode,
                    'stderr' => $e->stderrTail,
                ]);

                if ($e->isInputError()) {
                    throw new PermanentProcessingException(JobErrorCode::InputInvalid, 'El archivo de entrada no se pudo decodificar.', $e);
                }

                throw $e;
            }

            $outputFile = $workspace.'/'.$plan->output->filename;
            $metadata = $this->validateOutput($outputFile, $plan->output->kind->value);
            $asset = $this->outputs->register($job, $context, $outputFile, $plan->output, $metadata);
        } finally {
            $this->outputs->deleteWorkspace($job);
        }

        return [
            'operation' => $job->operation,
            'input' => $info->toArray(),
            'outputs' => [[
                'file_id' => $asset->public_id,
                'kind' => $asset->kind->value,
                'format' => $asset->extension,
                'mime_type' => $asset->mime_type,
                'size_bytes' => $asset->size_bytes,
                'metadata' => $asset->metadata,
            ]],
        ];
    }

    private function probeInput(MediaJob $job): MediaInfo
    {
        $asset = $job->inputAsset()->withTrashed()->first();

        if ($asset === null || $asset->trashed() || ! $this->outputs->disk()->exists($asset->path)) {
            throw new PermanentProcessingException(JobErrorCode::InputNotFound, 'El archivo de entrada ya no está disponible.');
        }

        try {
            return $this->ffprobe->analyze($this->inputPath($asset));
        } catch (ProbeFailedException $e) {
            throw new PermanentProcessingException(JobErrorCode::InputInvalid, 'El archivo de entrada está dañado o no es un formato multimedia admitido.', $e);
        }
    }

    private function inputPath(MediaAsset $asset): string
    {
        return Storage::disk($asset->disk)->path($asset->path);
    }

    /** Comprobaciones que dependen del contenido real del archivo. */
    private function validate(MediaJob $job, MediaInfo $info): void
    {
        $unsupported = fn (string $message) => new PermanentProcessingException(JobErrorCode::InputUnsupported, $message);
        $invalid = fn (string $message) => new PermanentProcessingException(JobErrorCode::InputInvalid, $message);

        if (! in_array($info->demuxer, (array) config('mediaforge.ffmpeg.allowed_demuxers'), true)) {
            throw $unsupported('El contenedor del archivo no está admitido.');
        }

        $inputTypes = $this->operations->get($job->operation)->inputTypes();
        $isVideo = $info->hasVideo;

        if (! $isVideo && ! in_array('audio', $inputTypes, true)) {
            throw $unsupported('La operación requiere un archivo con pista de vídeo.');
        }

        if (! $isVideo && ! $info->hasAudio) {
            throw $unsupported('El archivo no contiene pistas de audio ni de vídeo.');
        }

        if ($job->operation === 'extract_audio' && ! $info->hasAudio) {
            throw $unsupported('El archivo no contiene pista de audio.');
        }

        $maxDuration = (int) config('mediaforge.limits.max_duration_seconds');
        if ($maxDuration > 0 && $info->durationSeconds !== null && $info->durationSeconds > $maxDuration) {
            throw $unsupported("La duración del archivo supera el máximo permitido ({$maxDuration} s).");
        }

        $profileMax = $job->profile !== null && $this->profiles->has($job->profile)
            ? $this->profiles->get($job->profile)->maxDurationSeconds
            : null;
        if ($profileMax !== null && $info->durationSeconds !== null && $info->durationSeconds > $profileMax) {
            throw $unsupported("El perfil {$job->profile} admite como máximo {$profileMax} s de duración.");
        }

        $maxPixels = (int) config('mediaforge.limits.max_input_pixels');
        if ($isVideo && $maxPixels > 0 && (int) $info->width * (int) $info->height > $maxPixels) {
            throw $unsupported('La resolución del vídeo supera el máximo permitido.');
        }

        $p = $job->parameters;
        $duration = $info->durationSeconds;

        if ($job->operation === 'trim' && $duration !== null) {
            if ((float) $p['start'] >= $duration) {
                throw $invalid(sprintf('start (%s s) está fuera de la duración del archivo (%.3f s).', $p['start'], $duration));
            }

            if (isset($p['end']) && (float) $p['end'] > $duration + self::TIME_EPSILON) {
                throw $invalid(sprintf('end (%s s) supera la duración del archivo (%.3f s).', $p['end'], $duration));
            }

            if (isset($p['duration']) && (float) $p['start'] + (float) $p['duration'] > $duration + self::TIME_EPSILON) {
                throw $invalid(sprintf('start + duration supera la duración del archivo (%.3f s).', $duration));
            }

            if ($p['mode'] === 'fast') {
                $this->ensureStreamCopyCompatible((string) $p['format'], $info);
            }
        }

        if ($job->operation === 'thumbnail' && $p['mode'] === 'at_time' && $duration !== null && (float) $p['time'] >= $duration) {
            throw $invalid(sprintf('time (%s s) está fuera de la duración del vídeo (%.3f s).', $p['time'], $duration));
        }
    }

    /** En mode=fast no se recodifica: los códecs originales deben caber en el contenedor pedido. */
    private function ensureStreamCopyCompatible(string $format, MediaInfo $info): void
    {
        $allowed = self::CONTAINERS[$format];
        $video = CommandBuilder::apiCodec($info->videoCodec);
        $audio = $info->hasAudio ? CommandBuilder::apiCodec($info->audioCodec) : null;

        if (! in_array($video, $allowed['video'], true) || ($info->hasAudio && ! in_array($audio, $allowed['audio'], true))) {
            throw new PermanentProcessingException(
                JobErrorCode::InputUnsupported,
                sprintf('Los códecs del archivo (%s/%s) no se pueden copiar a %s sin recodificar; usa mode=accurate u otro formato.', $info->videoCodec, $info->audioCodec ?? 'sin audio', $format),
            );
        }
    }

    /**
     * @return array<string, mixed> metadatos de la salida
     */
    private function validateOutput(string $file, string $kind): array
    {
        clearstatcache(true, $file);

        if (! is_file($file) || filesize($file) === 0) {
            throw new RuntimeException('FFmpeg no generó el archivo de salida.');
        }

        if (filesize($file) >= (int) config('mediaforge.ffmpeg.max_output_bytes')) {
            throw new PermanentProcessingException(JobErrorCode::ProcessingFailed, 'La salida supera el tamaño máximo permitido.');
        }

        if ($kind === 'thumbnail') {
            $size = @getimagesize($file);

            return $size !== false ? ['width' => $size[0], 'height' => $size[1]] : [];
        }

        try {
            $info = $this->ffprobe->analyze($file);
        } catch (ProbeFailedException $e) {
            throw new RuntimeException('La salida generada no es válida.', previous: $e);
        }

        return array_filter(
            $info->toArray(),
            fn ($value, $key) => $value !== null && ! in_array($key, ['size_bytes', 'rotation', 'streams'], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
