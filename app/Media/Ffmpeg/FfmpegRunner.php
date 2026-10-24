<?php

namespace App\Media\Ffmpeg;

use App\Media\Ffmpeg\Exceptions\FfmpegFailedException;
use App\Media\Processing\Exceptions\ProcessingCancelledException;
use Closure;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Ejecuta FFmpeg como proceso independiente (array de argumentos, sin shell).
 *
 * - Lee el progreso de "-progress pipe:1" y lo notifica como porcentaje.
 * - Comprueba periódicamente si se solicitó la cancelación y, en ese caso, detiene
 *   únicamente su propio proceso (SIGTERM y, si no termina, SIGKILL).
 * - Aplica un timeout total y limita el tamaño máximo del archivo de salida.
 */
class FfmpegRunner
{
    private const int STDERR_TAIL_BYTES = 4000;

    /**
     * @param  list<string>  $arguments  argumentos de entrada/salida (sin el binario)
     * @param  string  $workingDirectory  directorio de trabajo; las salidas relativas se escriben aquí
     * @param  (Closure(?int): void)|null  $onHeartbeat  recibe el progreso 0-99 o null si se desconoce
     * @param  (Closure(): bool)|null  $shouldCancel
     *
     * @throws FfmpegFailedException
     * @throws ProcessTimedOutException
     * @throws ProcessingCancelledException
     */
    public function run(array $arguments, string $workingDirectory, ?float $durationSeconds = null, ?Closure $onHeartbeat = null, ?Closure $shouldCancel = null): void
    {
        $process = new Process($this->command($arguments), $workingDirectory, timeout: (float) config('mediaforge.ffmpeg.timeout'));
        $process->start();

        $heartbeatEvery = (float) config('mediaforge.ffmpeg.heartbeat_seconds', 5);
        $lastHeartbeat = 0.0;
        $progress = null;
        $stdoutBuffer = '';
        $stderrTail = '';

        try {
            while ($process->isRunning()) {
                $process->checkTimeout();

                $stdoutBuffer .= $process->getIncrementalOutput();
                $stderrTail = $this->tail($stderrTail.$process->getIncrementalErrorOutput());
                [$stdoutBuffer, $parsed] = $this->parseProgress($stdoutBuffer, $durationSeconds);
                $progress = $parsed ?? $progress;

                if ($shouldCancel !== null && $shouldCancel()) {
                    $process->stop(5);

                    throw new ProcessingCancelledException;
                }

                $now = microtime(true);
                if ($onHeartbeat !== null && $now - $lastHeartbeat >= $heartbeatEvery) {
                    $lastHeartbeat = $now;
                    $onHeartbeat($progress);
                }

                usleep(200_000);
            }
        } catch (ProcessTimedOutException $e) {
            $process->stop(5);

            throw $e;
        }

        $stderrTail = $this->tail($stderrTail.$process->getIncrementalErrorOutput());

        if (! $process->isSuccessful()) {
            throw new FfmpegFailedException(
                sprintf('FFmpeg terminó con código %s: %s', $process->getExitCode() ?? 'desconocido', $stderrTail),
                $process->getExitCode(),
                $stderrTail,
            );
        }
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function command(array $arguments): array
    {
        $prefix = config('mediaforge.ffmpeg.nice') && is_executable('/usr/bin/nice') ? ['/usr/bin/nice', '-n', '10'] : [];

        $output = array_pop($arguments);
        $threads = (int) config('mediaforge.ffmpeg.threads');

        return [
            ...$prefix,
            (string) config('mediaforge.binaries.ffmpeg'),
            '-nostdin',
            '-hide_banner',
            '-nostats',
            '-loglevel', 'error',
            '-y',
            '-progress', 'pipe:1',
            ...$arguments,
            ...($threads > 0 ? ['-threads', (string) $threads] : []),
            '-fs', (string) config('mediaforge.ffmpeg.max_output_bytes'),
            (string) $output,
        ];
    }

    /**
     * Procesa las líneas completas "clave=valor" de -progress.
     *
     * @return array{string, ?int} buffer restante y progreso detectado
     */
    private function parseProgress(string $buffer, ?float $durationSeconds): array
    {
        $progress = null;
        $lastNewline = strrpos($buffer, "\n");

        if ($lastNewline === false) {
            return [$buffer, null];
        }

        foreach (explode("\n", substr($buffer, 0, $lastNewline)) as $line) {
            if ($durationSeconds !== null && $durationSeconds > 0 && preg_match('/^out_time_us=(\d+)/', trim($line), $m)) {
                $progress = (int) min(99, floor(((int) $m[1] / 1_000_000) / $durationSeconds * 100));
            }
        }

        return [substr($buffer, $lastNewline + 1), $progress];
    }

    private function tail(string $text): string
    {
        return strlen($text) > self::STDERR_TAIL_BYTES ? substr($text, -self::STDERR_TAIL_BYTES) : $text;
    }
}
