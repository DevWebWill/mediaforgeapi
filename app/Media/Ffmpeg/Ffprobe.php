<?php

namespace App\Media\Ffmpeg;

use App\Media\Ffmpeg\Exceptions\ProbeFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Inspecciona archivos con ffprobe. Solo se permite el protocolo "file" y una lista
 * cerrada de demuxers, para que un archivo manipulado no pueda abrir recursos de red
 * ni activar demuxers con superficie de ataque innecesaria (concat, hls, etc.).
 */
class Ffprobe
{
    public function analyze(string $path): MediaInfo
    {
        $demuxers = implode(',', (array) config('mediaforge.ffmpeg.allowed_demuxers'));

        $process = new Process([
            (string) config('mediaforge.binaries.ffprobe'),
            '-v', 'error',
            '-protocol_whitelist', 'file',
            '-format_whitelist', $demuxers,
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $path,
        ], timeout: (float) config('mediaforge.ffmpeg.probe_timeout'));

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new ProbeFailedException('ffprobe superó el tiempo máximo.', previous: $e);
        }

        if (! $process->isSuccessful()) {
            throw new ProbeFailedException('ffprobe no pudo leer el archivo: '.mb_substr(trim($process->getErrorOutput()), 0, 500));
        }

        // El demuxer MOV detecta muestras que apuntan más allá del final (archivo truncado)
        if (stripos($process->getErrorOutput(), 'partial file') !== false) {
            throw new ProbeFailedException('El archivo está truncado.');
        }

        $data = json_decode($process->getOutput(), true);

        if (! is_array($data) || ! isset($data['format']) || ! is_array($data['format'])) {
            throw new ProbeFailedException('ffprobe devolvió una respuesta no válida.');
        }

        return $this->normalize($data);
    }

    /**
     * Decodifica los primeros segundos con -xerror para detectar archivos cuya cabecera
     * es válida pero cuyos datos están dañados. Es barato y evita encolar trabajos
     * que fallarían seguro.
     */
    public function verifyDecodable(string $path, MediaInfo $info, float $seconds): void
    {
        $process = new Process([
            (string) config('mediaforge.binaries.ffmpeg'),
            '-nostdin', '-hide_banner', '-v', 'error', '-xerror',
            '-protocol_whitelist', 'file',
            '-f', $info->demuxer,
            '-i', $path,
            ...($info->hasVideo ? ['-map', '0:v:0'] : []),
            ...($info->hasAudio ? ['-map', '0:a:0'] : []),
            '-t', (string) $seconds,
            '-f', 'null', '-',
        ], timeout: (float) config('mediaforge.ffmpeg.probe_timeout'));

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new ProbeFailedException('La verificación de decodificación superó el tiempo máximo.', previous: $e);
        }

        if (! $process->isSuccessful()) {
            throw new ProbeFailedException('El archivo no se puede decodificar: '.mb_substr(trim($process->getErrorOutput()), 0, 500));
        }
    }

    /** @param  array<string, mixed>  $data */
    private function normalize(array $data): MediaInfo
    {
        $format = $data['format'];
        $streams = is_array($data['streams'] ?? null) ? $data['streams'] : [];

        $video = $this->firstStream($streams, 'video');
        $audio = $this->firstStream($streams, 'audio');

        $rotation = $video !== null ? $this->rotation($video) : 0;
        $width = $this->positiveInt($video['width'] ?? null);
        $height = $this->positiveInt($video['height'] ?? null);

        if (in_array(abs($rotation), [90, 270], true)) {
            [$width, $height] = [$height, $width];
        }

        $duration = $this->positiveFloat($format['duration'] ?? null)
            ?? $this->positiveFloat($video['duration'] ?? null)
            ?? $this->positiveFloat($audio['duration'] ?? null);

        return new MediaInfo(
            demuxer: explode(',', (string) ($format['format_name'] ?? 'unknown'))[0],
            durationSeconds: $duration,
            sizeBytes: $this->positiveInt($format['size'] ?? null),
            bitrate: $this->positiveInt($format['bit_rate'] ?? null),
            hasVideo: $video !== null,
            hasAudio: $audio !== null,
            width: $width,
            height: $height,
            fps: $video !== null ? $this->frameRate($video) : null,
            videoCodec: $video['codec_name'] ?? null,
            audioCodec: $audio['codec_name'] ?? null,
            audioChannels: $this->positiveInt($audio['channels'] ?? null),
            audioSampleRate: $this->positiveInt($audio['sample_rate'] ?? null),
            rotation: $rotation,
            streamCount: count($streams),
            inspection: [
                'format' => array_intersect_key($format, array_flip(['duration', 'format_name'])),
                'streams' => array_map(static function (array $stream): array {
                    $fields = array_intersect_key($stream, array_flip([
                        'codec_type', 'codec_name', 'profile', 'width', 'height', 'avg_frame_rate',
                        'pix_fmt', 'field_order', 'sample_aspect_ratio', 'bit_rate', 'sample_rate', 'channels',
                    ]));
                    $fields['side_data_list'] = array_map(
                        static fn (array $sideData): array => array_intersect_key($sideData, ['rotation' => true]),
                        $stream['side_data_list'] ?? [],
                    );

                    return $fields;
                }, $streams),
            ],
        );
    }

    /**
     * Primer stream del tipo indicado. Las carátulas (attached_pic) de un MP3 o M4A
     * no cuentan como vídeo.
     *
     * @param  list<array<string, mixed>>  $streams
     * @return array<string, mixed>|null
     */
    private function firstStream(array $streams, string $type): ?array
    {
        foreach ($streams as $stream) {
            if (($stream['codec_type'] ?? null) !== $type) {
                continue;
            }

            if ($type === 'video' && (int) ($stream['disposition']['attached_pic'] ?? 0) === 1) {
                continue;
            }

            return $stream;
        }

        return null;
    }

    /** @param  array<string, mixed>  $stream */
    private function rotation(array $stream): int
    {
        foreach ((array) ($stream['side_data_list'] ?? []) as $sideData) {
            if (is_array($sideData) && isset($sideData['rotation']) && is_numeric($sideData['rotation'])) {
                return (int) $sideData['rotation'];
            }
        }

        $tag = $stream['tags']['rotate'] ?? null;

        return is_numeric($tag) ? (int) $tag : 0;
    }

    /** @param  array<string, mixed>  $stream */
    private function frameRate(array $stream): ?float
    {
        foreach (['avg_frame_rate', 'r_frame_rate'] as $key) {
            $value = (string) ($stream[$key] ?? '');
            if (preg_match('#^(\d+)/(\d+)$#', $value, $m) && (int) $m[2] > 0 && (int) $m[1] > 0) {
                return round((int) $m[1] / (int) $m[2], 3);
            }
        }

        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function positiveFloat(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }
}
