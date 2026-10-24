<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\JobPriority;
use App\Exceptions\ApiException;
use App\Media\Operations\OperationRegistry;
use App\Media\Profiles\ProfileRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file_id' => ['required', 'string', 'max:64', 'regex:/^file_[0-9a-z]{26}$/'],
            'operation' => ['required', 'string', Rule::in(app(OperationRegistry::class)->names())],
            'profile' => ['nullable', 'string', Rule::in(array_keys(app(ProfileRegistry::class)->all()))],
            'parameters' => ['sometimes', 'array'],
            'priority' => ['sometimes', 'string', Rule::in(JobPriority::values())],
            'reference' => ['nullable', 'string', 'max:191'],
            'scheduled_at' => ['nullable', 'date'],
            'processing_deadline' => ['nullable', 'date', 'after:now'],
            // Debe coincidir con un webhook activo registrado por el cliente
            'webhook_url' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /** Clave de idempotencia opcional, recibida en la cabecera estándar "Idempotency-Key". */
    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        if ($key === null || $key === '') {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key)) {
            throw new ApiException(
                ApiErrorCode::InvalidIdempotencyKey,
                'Idempotency-Key debe tener entre 8 y 128 caracteres [A-Za-z0-9._:-].',
                400,
            );
        }

        return $key;
    }
}
