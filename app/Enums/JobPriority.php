<?php

namespace App\Enums;

/**
 * Laravel Queue sobre Redis no ordena mensajes por prioridad dentro de una cola,
 * así que cada prioridad usa su propia cola y el worker las consume en orden.
 */
enum JobPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    public function weight(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Normal => 1,
            self::High => 2,
        };
    }

    public function exceeds(self $max): bool
    {
        return $this->weight() > $max->weight();
    }

    public function queue(): string
    {
        return (string) config('mediaforge.jobs.queues.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
