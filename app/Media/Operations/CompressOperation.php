<?php

namespace App\Media\Operations;

use App\Media\Operations\Concerns\EncodesVideo;

final class CompressOperation extends AbstractOperation
{
    use EncodesVideo;

    public function name(): string
    {
        return 'compress';
    }

    public function description(): string
    {
        return 'Recodifica el vídeo para reducir su tamaño. Solo reduce la resolución si supera max_width/max_height, conservando la relación de aspecto.';
    }

    public function parameters(): array
    {
        return [
            ...$this->encodingParameters(),
            new Parameter('max_width', 'integer', 'Ancho máximo; solo se reduce si el original es mayor.', min: 144, max: 3840),
            new Parameter('max_height', 'integer', 'Alto máximo; solo se reduce si el original es mayor.', min: 144, max: 3840),
        ];
    }

    public function outputFormats(): array
    {
        return array_keys(self::CONTAINERS);
    }

    public function constraints(): array
    {
        return $this->encodingConstraints();
    }

    protected function normalize(array $values): array
    {
        return $this->normalizeEncoding($values);
    }

    protected function crossValidate(array $values): array
    {
        return $this->validateEncoding($values);
    }
}
