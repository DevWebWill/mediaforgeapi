<?php

namespace App\Media\Operations\Concerns;

use App\Media\Operations\Parameter;

/**
 * Parámetros de codificación compartidos por las operaciones que recodifican vídeo,
 * para no duplicar reglas ni combinaciones de códecs/contenedores.
 */
trait EncodesVideo
{
    /** Contenedor => códecs de vídeo y audio compatibles. El primero es el valor por defecto. */
    protected const array CONTAINERS = [
        'mp4' => ['video' => ['h264', 'h265'], 'audio' => ['aac']],
        'mov' => ['video' => ['h264', 'h265'], 'audio' => ['aac']],
        'mkv' => ['video' => ['h264', 'h265', 'vp9'], 'audio' => ['aac', 'opus']],
        'webm' => ['video' => ['vp9'], 'audio' => ['opus']],
    ];

    /** Rango de CRF admitido y valor por defecto por códec. */
    protected const array CRF = [
        'h264' => ['min' => 18, 'max' => 35, 'default' => 23],
        'h265' => ['min' => 20, 'max' => 38, 'default' => 28],
        'vp9' => ['min' => 15, 'max' => 50, 'default' => 32],
    ];

    /** @return list<Parameter> */
    protected function encodingParameters(bool $formatRequired = false): array
    {
        return [
            new Parameter('format', 'string', 'Contenedor de salida.', required: $formatRequired, default: $formatRequired ? null : 'mp4', enum: array_keys(self::CONTAINERS)),
            new Parameter('video_codec', 'string', 'Códec de vídeo. Por defecto, el recomendado para el formato.', enum: ['h264', 'h265', 'vp9']),
            new Parameter('audio_codec', 'string', 'Códec de audio. Por defecto, el recomendado para el formato.', enum: ['aac', 'opus']),
            new Parameter('crf', 'integer', 'Calidad constante (menor = más calidad y tamaño). El rango depende del códec.', min: 15, max: 50),
            new Parameter('preset', 'string', 'Velocidad de codificación frente a eficiencia (H.264/H.265).', default: 'medium', enum: ['veryfast', 'faster', 'fast', 'medium', 'slow']),
            new Parameter('fps', 'number', 'FPS de salida. Si se omite se conservan los originales.', min: 1, max: 60),
            new Parameter('audio_bitrate_kbps', 'integer', 'Bitrate de audio en kbps.', default: 128, enum: [64, 96, 128, 160, 192, 256, 320]),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function normalizeEncoding(array $values): array
    {
        $container = self::CONTAINERS[$values['format']];
        $values['video_codec'] ??= $container['video'][0];
        $values['audio_codec'] ??= $container['audio'][0];

        if (isset(self::CRF[$values['video_codec']])) {
            $values['crf'] ??= self::CRF[$values['video_codec']]['default'];
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, list<string>>
     */
    protected function validateEncoding(array $values): array
    {
        $errors = [];
        $container = self::CONTAINERS[$values['format']];

        if (! in_array($values['video_codec'], $container['video'], true)) {
            $errors['video_codec'][] = sprintf('El formato %s admite los códecs de vídeo: %s.', $values['format'], implode(', ', $container['video']));
        }

        if (! in_array($values['audio_codec'], $container['audio'], true)) {
            $errors['audio_codec'][] = sprintf('El formato %s admite los códecs de audio: %s.', $values['format'], implode(', ', $container['audio']));
        }

        $range = self::CRF[$values['video_codec']] ?? null;
        if ($range !== null && ($values['crf'] < $range['min'] || $values['crf'] > $range['max'])) {
            $errors['crf'][] = sprintf('Para %s el CRF debe estar entre %d y %d.', $values['video_codec'], $range['min'], $range['max']);
        }

        return $errors;
    }

    /** @return list<string> */
    protected function encodingConstraints(): array
    {
        return [
            'Combinaciones admitidas: mp4/mov (h264|h265 + aac), mkv (h264|h265|vp9 + aac|opus), webm (vp9 + opus).',
            'CRF: h264 18-35 (23), h265 20-38 (28), vp9 15-50 (32).',
            'La salida se recodifica: la conversión implica pérdida de calidad.',
        ];
    }
}
