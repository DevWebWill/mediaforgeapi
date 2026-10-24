<?php

namespace App\Media\Operations;

use Illuminate\Validation\ValidationException;

interface MediaOperation
{
    /** Identificador estable usado en la API (p. ej. "compress"). */
    public function name(): string;

    public function description(): string;

    /** @return list<Parameter> */
    public function parameters(): array;

    /** Tipos de entrada admitidos: "video" y/o "audio". @return list<string> */
    public function inputTypes(): array;

    /** Formatos de salida posibles; vacío si la operación solo devuelve metadatos. @return list<string> */
    public function outputFormats(): array;

    /**
     * Valida y normaliza los parámetros: rechaza claves desconocidas, aplica
     * valores por defecto y comprueba combinaciones incompatibles.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function resolveParameters(array $input): array;

    /** @return array<string, mixed> */
    public function describe(): array;
}
