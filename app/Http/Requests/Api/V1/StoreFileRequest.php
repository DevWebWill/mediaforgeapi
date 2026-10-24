<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreFileRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Tamaño, extensión, firma real y cuota se validan en StoreUploadedFile con los límites del cliente
        return [
            'file' => ['required', 'file'],
        ];
    }
}
