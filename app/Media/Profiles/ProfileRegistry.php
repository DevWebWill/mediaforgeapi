<?php

namespace App\Media\Profiles;

use App\Media\Operations\OperationRegistry;
use InvalidArgumentException;

final class ProfileRegistry
{
    /** @var array<string, Profile> */
    private array $profiles = [];

    /** @param  array<string, array<string, mixed>>  $definitions */
    public function __construct(OperationRegistry $operations, array $definitions)
    {
        foreach ($definitions as $id => $definition) {
            foreach ($definition['operations'] as $operation) {
                if (! $operations->has($operation)) {
                    throw new InvalidArgumentException("El perfil {$id} referencia una operación inexistente: {$operation}");
                }
            }

            $this->profiles[$id] = new Profile(
                id: $id,
                version: (int) $definition['version'],
                description: (string) $definition['description'],
                operations: array_values($definition['operations']),
                parameters: $definition['parameters'] ?? [],
                maxDurationSeconds: $definition['max_duration_seconds'] ?? null,
            );
        }
    }

    public function has(string $id): bool
    {
        return isset($this->profiles[$id]);
    }

    public function get(string $id): Profile
    {
        return $this->profiles[$id] ?? throw new InvalidArgumentException("Perfil desconocido: {$id}");
    }

    /** @return array<string, Profile> */
    public function all(): array
    {
        return $this->profiles;
    }
}
