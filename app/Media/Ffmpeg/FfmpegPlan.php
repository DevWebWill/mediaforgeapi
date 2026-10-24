<?php

namespace App\Media\Ffmpeg;

/** Comando FFmpeg listo para ejecutar y la salida que generará. */
final readonly class FfmpegPlan
{
    /** @param  list<string>  $arguments */
    public function __construct(
        public array $arguments,
        public OutputSpec $output,
        // Duración esperada de la salida, para calcular el progreso
        public ?float $expectedDurationSeconds,
    ) {}
}
