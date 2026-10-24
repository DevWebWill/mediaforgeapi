<?php

namespace App\Media\Operations;

final class ThumbnailOperation extends AbstractOperation
{
    public function name(): string
    {
        return 'thumbnail';
    }

    public function description(): string
    {
        return 'Genera una imagen del vídeo: en un instante concreto (at_time) o un fotograma representativo (representative). Admite dimensiones de portada.';
    }

    public function parameters(): array
    {
        return [
            new Parameter('mode', 'string', 'Selección del fotograma.', default: 'representative', enum: ['representative', 'at_time']),
            new Parameter('time', 'number', 'Instante en segundos (obligatorio con mode=at_time).', min: 0),
            new Parameter('width', 'integer', 'Ancho de la imagen (opcional).', min: 16, max: 3840),
            new Parameter('height', 'integer', 'Alto de la imagen (opcional).', min: 16, max: 3840),
            new Parameter('strategy', 'string', 'Ajuste cuando se indican width y height.', default: 'fit', enum: ResizeOperation::STRATEGIES),
            new Parameter('format', 'string', 'Formato de imagen.', default: 'jpg', enum: ['jpg', 'png', 'webp']),
        ];
    }

    public function outputFormats(): array
    {
        return ['jpg', 'png', 'webp'];
    }

    public function constraints(): array
    {
        return [
            'time debe estar dentro de la duración del vídeo (se valida al procesar).',
            'Si solo se indica width o height, la otra dimensión se calcula conservando el aspecto.',
        ];
    }

    protected function crossValidate(array $values): array
    {
        $errors = [];

        if ($values['mode'] === 'at_time' && ! array_key_exists('time', $values)) {
            $errors['time'][] = 'Es obligatorio con mode=at_time.';
        }

        if ($values['mode'] === 'representative' && array_key_exists('time', $values)) {
            $errors['time'][] = 'Solo se admite con mode=at_time.';
        }

        return $errors;
    }
}
