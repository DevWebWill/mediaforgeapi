<?php

namespace App\Media\Processing;

use App\Media\Processing\Exceptions\PermanentProcessingException;
use App\Media\Processing\Exceptions\ProcessingCancelledException;
use App\Models\MediaJob;
use Throwable;

interface MediaProcessor
{
    /**
     * Ejecuta la operación del trabajo. Debe ser idempotente respecto a sus salidas:
     * un reintento no puede dejar resultados duplicados.
     *
     * @return array<string, mixed> resultado público normalizado
     *
     * @throws PermanentProcessingException error determinista: no se reintenta
     * @throws ProcessingCancelledException el cliente canceló durante la ejecución
     * @throws Throwable cualquier otro error se considera transitorio
     */
    public function process(MediaJob $job, ProcessingContext $context): array;
}
