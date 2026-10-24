<?php

namespace App\Media\Profiles;

use App\Media\Operations\MediaOperation;
use App\Media\Operations\Parameter;

/**
 * Conjunto versionado de parámetros por defecto para una o varias operaciones.
 */
final readonly class Profile
{
    /**
     * @param  list<string>  $operations
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $id,
        public int $version,
        public string $description,
        public array $operations,
        public array $parameters,
        public ?int $maxDurationSeconds = null,
    ) {}

    public function supports(string $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }

    /**
     * Parámetros del perfil aplicables a la operación (descarta los que no admite).
     *
     * @return array<string, mixed>
     */
    public function parametersFor(MediaOperation $operation): array
    {
        $accepted = array_map(fn (Parameter $p): string => $p->name, $operation->parameters());

        return array_intersect_key($this->parameters, array_flip($accepted));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'description' => $this->description,
            'operations' => $this->operations,
            'parameters' => $this->parameters,
            'max_duration_seconds' => $this->maxDurationSeconds,
        ];
    }
}
