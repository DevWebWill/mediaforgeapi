<?php

namespace App\Media\Operations;

use App\Media\Operations\Concerns\EncodesVideo;

final class ConvertOperation extends AbstractOperation
{
    use EncodesVideo;

    public function name(): string
    {
        return 'convert';
    }

    public function description(): string
    {
        return 'Convierte el vídeo a otro contenedor/códec admitido. Implica recodificación (no es una conversión sin pérdida).';
    }

    public function parameters(): array
    {
        return $this->encodingParameters(formatRequired: true);
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
