<?php

namespace App\Media\Operations;

final class ExtractAudioOperation extends AbstractOperation
{
    public function name(): string
    {
        return 'extract_audio';
    }

    public function description(): string
    {
        return 'Extrae la pista de audio a MP3 o AAC (M4A). Falla si el archivo no contiene audio.';
    }

    public function parameters(): array
    {
        return [
            new Parameter('format', 'string', 'Formato de salida.', default: 'mp3', enum: ['mp3', 'm4a']),
            new Parameter('bitrate_kbps', 'integer', 'Bitrate de audio en kbps.', default: 192, enum: [64, 96, 128, 160, 192, 256, 320]),
        ];
    }

    public function inputTypes(): array
    {
        return ['video', 'audio'];
    }

    public function outputFormats(): array
    {
        return ['mp3', 'm4a'];
    }
}
