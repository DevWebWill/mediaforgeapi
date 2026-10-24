<?php

namespace App\Media\Operations;

final class AnalyzeOperation extends AbstractOperation
{
    public function name(): string
    {
        return 'analyze';
    }

    public function description(): string
    {
        return 'Analiza el archivo con FFprobe y devuelve metadatos normalizados (duración, resolución, códecs, streams...). No genera archivos.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function inputTypes(): array
    {
        return ['video', 'audio'];
    }

    public function outputFormats(): array
    {
        return [];
    }
}
