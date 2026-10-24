<?php

namespace App\Enums;

/**
 * Códigos normalizados de fallo de un trabajo ya aceptado (no son errores HTTP).
 */
enum JobErrorCode: string
{
    case InputNotFound = 'input_not_found';
    case InputInvalid = 'input_invalid';
    case InputUnsupported = 'input_unsupported';
    case ProcessingFailed = 'processing_failed';
    case ProcessingTimeout = 'processing_timeout';
    case InternalError = 'internal_error';

    public function isTransient(): bool
    {
        return in_array($this, [self::ProcessingFailed, self::ProcessingTimeout, self::InternalError], true);
    }
}
