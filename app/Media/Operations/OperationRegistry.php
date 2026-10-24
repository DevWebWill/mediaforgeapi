<?php

namespace App\Media\Operations;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Catálogo de operaciones disponibles, definido en config/mediaforge.php.
 * Añadir una operación nueva = crear su clase y registrarla en la configuración.
 */
final class OperationRegistry
{
    /** @var array<string, MediaOperation> */
    private array $operations = [];

    /** @param  list<class-string<MediaOperation>>  $classes */
    public function __construct(Container $container, array $classes)
    {
        foreach ($classes as $class) {
            $operation = $container->make($class);

            if (! $operation instanceof MediaOperation) {
                throw new InvalidArgumentException("{$class} debe implementar ".MediaOperation::class);
            }

            $this->operations[$operation->name()] = $operation;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->operations[$name]);
    }

    public function get(string $name): MediaOperation
    {
        return $this->operations[$name] ?? throw new InvalidArgumentException("Operación desconocida: {$name}");
    }

    /** @return array<string, MediaOperation> */
    public function all(): array
    {
        return $this->operations;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->operations);
    }
}
