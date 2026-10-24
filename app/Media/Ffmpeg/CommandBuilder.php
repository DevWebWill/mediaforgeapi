<?php

namespace App\Media\Ffmpeg;

use App\Enums\AssetKind;
use InvalidArgumentException;

/**
 * Traduce operación + parámetros validados a argumentos de FFmpeg.
 * Toda la lógica de FFmpeg vive aquí: los perfiles solo aportan parámetros,
 * así que no se duplica por plataforma.
 */
class CommandBuilder
{
    private const array MUXERS = [
        'mp4' => ['muxer' => 'mp4', 'mime' => 'video/mp4'],
        'mov' => ['muxer' => 'mov', 'mime' => 'video/quicktime'],
        'mkv' => ['muxer' => 'matroska', 'mime' => 'video/x-matroska'],
        'webm' => ['muxer' => 'webm', 'mime' => 'video/webm'],
    ];

    private const array IMAGE_FORMATS = [
        'jpg' => ['codec' => ['-c:v', 'mjpeg', '-q:v', '3'], 'mime' => 'image/jpeg'],
        'png' => ['codec' => ['-c:v', 'png'], 'mime' => 'image/png'],
        'webp' => ['codec' => ['-c:v', 'libwebp', '-quality', '85'], 'mime' => 'image/webp'],
    ];

    private const array VP9_CPU_USED = ['veryfast' => 5, 'faster' => 4, 'fast' => 3, 'medium' => 2, 'slow' => 1];

    /** Códec de FFmpeg (ffprobe) => nombre usado en la API */
    private const array CODEC_ALIASES = ['hevc' => 'h265', 'h264' => 'h264', 'vp9' => 'vp9', 'aac' => 'aac', 'opus' => 'opus'];

    /** @param  array<string, mixed>  $p  parámetros ya validados por la operación */
    public function build(string $operation, array $p, MediaInfo $info, string $input): FfmpegPlan
    {
        return match ($operation) {
            'compress' => $this->encode($p, $info, $input, $this->compressFilters($p)),
            'convert' => $this->encode($p, $info, $input, []),
            'resize' => $this->encode($p, $info, $input, $this->fitFilters($p['width'], $p['height'], $p['strategy'], $p['pad_color'] ?? '#000000')),
            'trim' => $this->trim($p, $info, $input),
            'extract_audio' => $this->extractAudio($p, $info, $input),
            'thumbnail' => $this->thumbnail($p, $info, $input),
            default => throw new InvalidArgumentException("La operación {$operation} no genera archivos con FFmpeg."),
        };
    }

    /** Códec del archivo en la nomenclatura de la API, o null si no tiene equivalente. */
    public static function apiCodec(?string $ffmpegCodec): ?string
    {
        return $ffmpegCodec !== null ? (self::CODEC_ALIASES[$ffmpegCodec] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  list<string>  $filters
     * @param  list<string>  $inputOptions
     * @param  list<string>  $outputOptions
     */
    private function encode(array $p, MediaInfo $info, string $input, array $filters, array $inputOptions = [], array $outputOptions = [], ?float $expectedDuration = null): FfmpegPlan
    {
        if (isset($p['fps'])) {
            $filters[] = 'fps='.$this->number((float) $p['fps']);
        }

        $filters[] = 'setsar=1';
        $filters[] = 'format=yuv420p';

        $format = (string) $p['format'];
        $output = new OutputSpec(AssetKind::Result, 'output.'.$format, $format, self::MUXERS[$format]['mime']);

        $arguments = [
            ...$this->input($info, $input, $inputOptions),
            '-map', '0:v:0',
            ...($info->hasAudio ? ['-map', '0:a:0'] : []),
            '-map_metadata', '-1',
            ...$outputOptions,
            '-vf', implode(',', $filters),
            ...$this->videoCodec($p, $format),
            ...($info->hasAudio ? $this->audioCodec((string) $p['audio_codec'], (int) $p['audio_bitrate_kbps']) : ['-an']),
            ...$this->muxer($format),
            $output->filename,
        ];

        return new FfmpegPlan($arguments, $output, $expectedDuration ?? $info->durationSeconds);
    }

    /** @param  array<string, mixed>  $p */
    private function trim(array $p, MediaInfo $info, string $input): FfmpegPlan
    {
        $start = (float) $p['start'];
        $duration = isset($p['duration']) ? (float) $p['duration'] : (float) $p['end'] - $start;

        if ($info->durationSeconds !== null) {
            $duration = min($duration, $info->durationSeconds - $start);
        }

        // -ss antes de -i: búsqueda rápida; al recodificar FFmpeg descarta hasta el instante exacto
        $seek = ['-ss', $this->number($start)];
        $limit = ['-t', $this->number($duration)];

        if ($p['mode'] === 'accurate') {
            return $this->encode($p, $info, $input, [], $seek, $limit, $duration);
        }

        $format = (string) $p['format'];
        $output = new OutputSpec(AssetKind::Result, 'output.'.$format, $format, self::MUXERS[$format]['mime']);

        return new FfmpegPlan([
            ...$this->input($info, $input, $seek),
            ...$limit,
            '-map', '0:v:0',
            ...($info->hasAudio ? ['-map', '0:a:0'] : []),
            '-map_metadata', '-1',
            '-c', 'copy',
            '-avoid_negative_ts', 'make_zero',
            ...$this->muxer($format),
            $output->filename,
        ], $output, $duration);
    }

    /** @param  array<string, mixed>  $p */
    private function extractAudio(array $p, MediaInfo $info, string $input): FfmpegPlan
    {
        $format = (string) $p['format'];
        $bitrate = (int) $p['bitrate_kbps'].'k';

        [$codec, $muxer, $mime] = $format === 'mp3'
            ? [['-c:a', 'libmp3lame', '-b:a', $bitrate], ['-f', 'mp3'], 'audio/mpeg']
            : [['-c:a', 'aac', '-b:a', $bitrate], ['-f', 'ipod', '-movflags', '+faststart'], 'audio/mp4'];

        $output = new OutputSpec(AssetKind::Result, 'output.'.$format, $format, $mime);

        return new FfmpegPlan([
            ...$this->input($info, $input),
            '-map', '0:a:0',
            '-vn',
            '-map_metadata', '-1',
            ...$codec,
            ...$muxer,
            $output->filename,
        ], $output, $info->durationSeconds);
    }

    /** @param  array<string, mixed>  $p */
    private function thumbnail(array $p, MediaInfo $info, string $input): FfmpegPlan
    {
        $filters = [];

        if ($p['mode'] === 'at_time') {
            $seek = (float) $p['time'];
        } else {
            // Se evita el inicio (suele ser negro o un rótulo) y el filtro "thumbnail"
            // elige el fotograma más representativo de un lote
            $seek = $info->durationSeconds !== null ? min($info->durationSeconds * 0.1, 30.0) : 0.0;
            $filters[] = 'thumbnail=n=60';
        }

        $width = $p['width'] ?? null;
        $height = $p['height'] ?? null;

        if ($width !== null && $height !== null) {
            $filters = [...$filters, ...$this->fitFilters((int) $width, (int) $height, (string) $p['strategy'], '#000000')];
        } elseif ($width !== null || $height !== null) {
            $filters[] = sprintf('scale=%s:%s', $width ?? -2, $height ?? -2);
        }

        $filters[] = 'setsar=1';

        $format = (string) $p['format'];
        $output = new OutputSpec(AssetKind::Thumbnail, 'thumbnail.'.$format, $format, self::IMAGE_FORMATS[$format]['mime']);

        return new FfmpegPlan([
            ...$this->input($info, $input, $seek > 0 ? ['-ss', $this->number($seek)] : []),
            '-map', '0:v:0',
            '-an',
            '-map_metadata', '-1',
            '-vf', implode(',', $filters),
            '-frames:v', '1',
            ...self::IMAGE_FORMATS[$format]['codec'],
            '-f', 'image2',
            '-update', '1',
            $output->filename,
        ], $output, null);
    }

    /**
     * Entrada con demuxer forzado (el detectado por ffprobe) y solo protocolo "file".
     *
     * @param  list<string>  $options
     * @return list<string>
     */
    private function input(MediaInfo $info, string $input, array $options = []): array
    {
        return [
            '-protocol_whitelist', 'file',
            '-f', $info->demuxer,
            ...$options,
            '-i', $input,
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<string>
     */
    private function compressFilters(array $p): array
    {
        $maxWidth = isset($p['max_width']) ? 'min(iw\,'.(int) $p['max_width'].')' : 'iw';
        $maxHeight = isset($p['max_height']) ? 'min(ih\,'.(int) $p['max_height'].')' : 'ih';

        // Solo reduce: si el original cabe en el máximo se conserva su tamaño (redondeado a par)
        return ["scale=w='{$maxWidth}':h='{$maxHeight}':force_original_aspect_ratio=decrease:force_divisible_by=2"];
    }

    /** @return list<string> */
    private function fitFilters(int $width, int $height, string $strategy, string $padColor): array
    {
        return match ($strategy) {
            'crop' => [
                "scale={$width}:{$height}:force_original_aspect_ratio=increase",
                "crop={$width}:{$height}",
            ],
            'pad' => [
                "scale={$width}:{$height}:force_original_aspect_ratio=decrease:force_divisible_by=2",
                sprintf('pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=0x%s', $width, $height, ltrim($padColor, '#')),
            ],
            default => ["scale={$width}:{$height}:force_original_aspect_ratio=decrease:force_divisible_by=2"],
        };
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<string>
     */
    private function videoCodec(array $p, string $format): array
    {
        $crf = (string) (int) $p['crf'];
        $preset = (string) ($p['preset'] ?? 'medium');

        return match ($p['video_codec']) {
            'h265' => [
                '-c:v', 'libx265', '-preset', $preset, '-crf', $crf,
                '-x265-params', 'log-level=error',
                // Etiqueta necesaria para que Apple/navegadores reproduzcan HEVC en MP4/MOV
                ...(in_array($format, ['mp4', 'mov'], true) ? ['-tag:v', 'hvc1'] : []),
            ],
            'vp9' => [
                '-c:v', 'libvpx-vp9', '-crf', $crf, '-b:v', '0',
                '-row-mt', '1', '-deadline', 'good', '-cpu-used', (string) self::VP9_CPU_USED[$preset],
            ],
            default => ['-c:v', 'libx264', '-preset', $preset, '-crf', $crf, '-profile:v', 'high'],
        };
    }

    /** @return list<string> */
    private function audioCodec(string $codec, int $bitrateKbps): array
    {
        return [
            '-c:a', $codec === 'opus' ? 'libopus' : 'aac',
            '-b:a', $bitrateKbps.'k',
        ];
    }

    /** @return list<string> */
    private function muxer(string $format): array
    {
        return [
            '-max_muxing_queue_size', '1024',
            ...(in_array($format, ['mp4', 'mov'], true) ? ['-movflags', '+faststart'] : []),
            '-f', self::MUXERS[$format]['muxer'],
        ];
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
