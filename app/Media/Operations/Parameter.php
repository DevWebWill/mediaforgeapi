<?php

namespace App\Media\Operations;

use Illuminate\Validation\Rule;

/**
 * Definición de un parámetro admitido por una operación. Es la única fuente de
 * verdad para la validación y para el catálogo público (GET /api/v1/operations).
 */
final readonly class Parameter
{
    /**
     * @param  'integer'|'number'|'string'|'boolean'  $type
     * @param  list<int|string>|null  $enum
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $description,
        public bool $required = false,
        public int|float|string|bool|null $default = null,
        public ?array $enum = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public ?string $pattern = null,
    ) {}

    /** @return list<mixed> */
    public function rules(): array
    {
        $rules = [$this->required ? 'required' : 'sometimes'];

        $rules[] = match ($this->type) {
            'integer' => 'integer',
            'number' => 'numeric',
            'boolean' => 'boolean',
            'string' => 'string',
        };

        if ($this->enum !== null) {
            $rules[] = Rule::in($this->enum);
        }

        if ($this->min !== null) {
            $rules[] = 'min:'.$this->min;
        }

        if ($this->max !== null) {
            $rules[] = 'max:'.$this->max;
        }

        if ($this->type === 'string' && $this->max === null) {
            $rules[] = 'max:255';
        }

        if ($this->pattern !== null) {
            $rules[] = 'regex:'.$this->pattern;
        }

        return $rules;
    }

    public function cast(mixed $value): mixed
    {
        return match ($this->type) {
            'integer' => (int) $value,
            'number' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
            'description' => $this->description,
            'default' => $this->default,
            'enum' => $this->enum,
            'min' => $this->min,
            'max' => $this->max,
        ], fn (mixed $value): bool => $value !== null);
    }
}
