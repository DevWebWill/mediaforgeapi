<?php

namespace App\Media\Operations;

use App\Media\Operations\Concerns\EncodesVideo;

final class ResizeOperation extends AbstractOperation
{
    use EncodesVideo;

    public const array STRATEGIES = ['fit', 'crop', 'pad'];

    public function name(): string
    {
        return 'resize';
    }

    public function description(): string
    {
        return 'Adapta el vídeo a unas dimensiones sin deformarlo: fit (cabe dentro, conserva aspecto), crop (rellena y recorta), pad (cabe dentro y añade bandas).';
    }

    public function parameters(): array
    {
        return [
            new Parameter('width', 'integer', 'Ancho de destino en píxeles (par).', required: true, min: 16, max: 3840),
            new Parameter('height', 'integer', 'Alto de destino en píxeles (par).', required: true, min: 16, max: 3840),
            new Parameter('strategy', 'string', 'Estrategia de ajuste.', default: 'fit', enum: self::STRATEGIES),
            new Parameter('pad_color', 'string', 'Color de las bandas con strategy=pad (#RRGGBB).', default: '#000000', pattern: '/^#[0-9A-Fa-f]{6}$/'),
            ...$this->encodingParameters(),
        ];
    }

    public function outputFormats(): array
    {
        return array_keys(self::CONTAINERS);
    }

    public function constraints(): array
    {
        return [
            'width y height deben ser pares (requisito de yuv420p).',
            'Con strategy=fit el resultado puede ser menor que width x height en una dimensión.',
            ...$this->encodingConstraints(),
        ];
    }

    protected function normalize(array $values): array
    {
        return $this->normalizeEncoding($values);
    }

    protected function crossValidate(array $values): array
    {
        $errors = $this->validateEncoding($values);

        foreach (['width', 'height'] as $dimension) {
            if ($values[$dimension] % 2 !== 0) {
                $errors[$dimension][] = 'Debe ser un número par.';
            }
        }

        return $errors;
    }
}
