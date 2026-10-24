<?php

namespace App\Enums;

/**
 * Ciclo de vida de un trabajo de procesamiento.
 *
 * - pending:    persistido en MySQL, todavía no confirmado en la cola.
 * - queued:     encolado en Redis, a la espera de un worker.
 * - processing: un worker lo ha reclamado y lo está ejecutando.
 * - retrying:   falló por un error transitorio y espera su siguiente intento (backoff).
 * - completed / failed / cancelled: estados finales.
 */
enum JobStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Retrying = 'retrying';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Queued, self::Processing, self::Cancelled, self::Failed],
            self::Queued => [self::Processing, self::Cancelled, self::Failed],
            self::Processing => [self::Completed, self::Failed, self::Cancelled, self::Retrying],
            self::Retrying => [self::Processing, self::Cancelled, self::Failed],
            self::Completed, self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    /** Estados en los que un worker puede reclamar el trabajo. */
    public function isClaimable(): bool
    {
        return in_array($this, [self::Pending, self::Queued, self::Retrying], true);
    }

    /** @return list<self> */
    public static function active(): array
    {
        return [self::Pending, self::Queued, self::Processing, self::Retrying];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
