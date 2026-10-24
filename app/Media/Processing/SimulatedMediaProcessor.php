<?php

namespace App\Media\Processing;

use App\Media\Processing\Exceptions\ProcessingCancelledException;
use App\Models\MediaJob;

/**
 * Motor simulado: recorre el ciclo de vida completo del trabajo sin ejecutar FFmpeg.
 * Se usa en la Fase 1 y en las pruebas; el resultado lo indica con "simulated": true.
 */
final class SimulatedMediaProcessor implements MediaProcessor
{
    public function process(MediaJob $job, ProcessingContext $context): array
    {
        $context->heartbeat(50);

        if ($context->isCancellationRequested()) {
            throw new ProcessingCancelledException;
        }

        return [
            'simulated' => true,
            'operation' => $job->operation,
            'outputs' => [],
        ];
    }
}
