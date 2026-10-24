<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\WebhookEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WebhookEndpointRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'url' => [$required, 'string', 'max:2048'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'events' => [$required, 'array', 'min:1'],
            'events.*' => ['string', 'distinct', Rule::in(WebhookEventType::subscribable())],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
