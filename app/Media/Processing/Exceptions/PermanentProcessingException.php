<?php

namespace App\Media\Processing\Exceptions;

use App\Enums\JobErrorCode;
use RuntimeException;
use Throwable;

/**
 * Error determinista (entrada inválida, parámetros incompatibles...).
 * Reintentarlo produciría el mismo resultado, así que el trabajo falla directamente.
 * El mensaje es público: no debe contener rutas ni la salida de FFmpeg.
 */
class PermanentProcessingException extends RuntimeException
{
    public function __construct(
        public readonly JobErrorCode $errorCode,
        string $publicMessage,
        ?Throwable $previous = null,
    ) {
        parent::__construct($publicMessage, previous: $previous);
    }
}
