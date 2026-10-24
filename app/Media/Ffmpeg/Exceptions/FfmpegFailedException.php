<?php

namespace App\Media\Ffmpeg\Exceptions;

use RuntimeException;

/**
 * FFmpeg terminó con error. El mensaje incluye el final de stderr y es solo para
 * logs internos: nunca se expone al cliente.
 */
class FfmpegFailedException extends RuntimeException
{
    private const array INPUT_ERROR_PATTERNS = [
        'Invalid data found when processing input',
        'moov atom not found',
        'could not find codec parameters',
        'does not contain any stream',
        'Error while decoding stream',
        'Could not find tag for codec',
        'Unsupported codec',
    ];

    public function __construct(
        string $message,
        public readonly ?int $exitCode,
        public readonly string $stderrTail,
    ) {
        parent::__construct($message);
    }

    /** El error depende de la entrada: reintentarlo daría el mismo resultado. */
    public function isInputError(): bool
    {
        foreach (self::INPUT_ERROR_PATTERNS as $pattern) {
            if (stripos($this->stderrTail, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
