<?php

namespace App\Media\Ffmpeg;

/**
 * Metadatos normalizados de un archivo multimedia. Los valores desconocidos son null
 * (nunca 0), para no confundir "no disponible" con un valor real.
 * width/height son las dimensiones de visualización (ya aplicada la rotación).
 */
final readonly class MediaInfo
{
    public function __construct(
        public string $demuxer,
        public ?float $durationSeconds,
        public ?int $sizeBytes,
        public ?int $bitrate,
        public bool $hasVideo,
        public bool $hasAudio,
        public ?int $width,
        public ?int $height,
        public ?float $fps,
        public ?string $videoCodec,
        public ?string $audioCodec,
        public ?int $audioChannels,
        public ?int $audioSampleRate,
        public int $rotation,
        public int $streamCount,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'container' => $this->demuxer,
            'duration_seconds' => $this->durationSeconds !== null ? round($this->durationSeconds, 3) : null,
            'size_bytes' => $this->sizeBytes,
            'bitrate' => $this->bitrate,
            'has_video' => $this->hasVideo,
            'has_audio' => $this->hasAudio,
            'width' => $this->width,
            'height' => $this->height,
            'fps' => $this->fps,
            'video_codec' => $this->videoCodec,
            'audio_codec' => $this->audioCodec,
            'audio_channels' => $this->audioChannels,
            'audio_sample_rate' => $this->audioSampleRate,
            'rotation' => $this->rotation,
            'streams' => $this->streamCount,
        ];
    }
}
