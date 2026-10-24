<?php

namespace App\Media\Ffmpeg;

use App\Enums\AssetKind;

/** Archivo que producirá FFmpeg dentro del directorio de trabajo. */
final readonly class OutputSpec
{
    public function __construct(
        public AssetKind $kind,
        public string $filename,
        public string $extension,
        public string $mimeType,
    ) {}
}
