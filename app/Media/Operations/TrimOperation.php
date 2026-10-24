<?php

namespace App\Media\Operations;

use App\Media\Operations\Concerns\EncodesVideo;

final class TrimOperation extends AbstractOperation
{
    use EncodesVideo;

    public function name(): string
    {
        return 'trim';
    }

    public function description(): string
    {
        return 'Extrae un intervalo temporal. mode=accurate recodifica y corta en el instante exacto; mode=fast copia los streams y corta en el fotograma clave más cercano (más rápido, menos preciso).';
    }

    public function parameters(): array
    {
        return [
            new Parameter('start', 'number', 'Inicio en segundos.', required: true, min: 0),
            new Parameter('end', 'number', 'Fin en segundos (excluyente con duration).', min: 0),
            new Parameter('duration', 'number', 'Duración en segundos (excluyente con end).', min: 0.1),
            new Parameter('mode', 'string', 'Precisión del corte.', default: 'accurate', enum: ['accurate', 'fast']),
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
            'Indica exactamente uno de end o duration.',
            'El intervalo no puede superar la duración del archivo (se valida al procesar).',
            'En mode=fast se ignoran los parámetros de codificación y se conserva el códec original.',
        ];
    }

    protected function normalize(array $values): array
    {
        return $this->normalizeEncoding($values);
    }

    protected function crossValidate(array $values): array
    {
        $errors = $this->validateEncoding($values);

        $hasEnd = array_key_exists('end', $values);
        $hasDuration = array_key_exists('duration', $values);

        if ($hasEnd === $hasDuration) {
            $errors['end'][] = 'Indica exactamente uno de end o duration.';
        }

        if ($hasEnd && $values['end'] <= $values['start']) {
            $errors['end'][] = 'Debe ser mayor que start.';
        }

        return $errors;
    }
}
