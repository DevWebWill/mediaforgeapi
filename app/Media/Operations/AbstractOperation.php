<?php

namespace App\Media\Operations;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

abstract class AbstractOperation implements MediaOperation
{
    public function inputTypes(): array
    {
        return ['video'];
    }

    /** @return list<string> */
    public function constraints(): array
    {
        return [];
    }

    public function resolveParameters(array $input): array
    {
        $definitions = [];
        foreach ($this->parameters() as $parameter) {
            $definitions[$parameter->name] = $parameter;
        }

        $errors = [];

        foreach (array_keys($input) as $key) {
            if (! is_string($key) || ! isset($definitions[$key])) {
                $errors[(string) $key] = ['Parámetro no admitido por la operación "'.$this->name().'".'];
            }
        }

        $rules = array_map(fn (Parameter $p): array => $p->rules(), $definitions);
        $validator = Validator::make(array_intersect_key($input, $definitions), $rules);

        $errors = array_merge($errors, $validator->errors()->toArray());

        if ($errors !== []) {
            throw $this->validationError($errors);
        }

        $values = [];
        foreach ($definitions as $name => $parameter) {
            if (array_key_exists($name, $input)) {
                $values[$name] = $parameter->cast($input[$name]);
            } elseif ($parameter->default !== null) {
                $values[$name] = $parameter->default;
            }
        }

        $values = $this->normalize($values);
        $crossErrors = $this->crossValidate($values);

        if ($crossErrors !== []) {
            throw $this->validationError($crossErrors);
        }

        ksort($values);

        return $values;
    }

    public function describe(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'input_types' => $this->inputTypes(),
            'output_formats' => $this->outputFormats(),
            'parameters' => array_map(fn (Parameter $p): array => $p->toArray(), $this->parameters()),
            'constraints' => $this->constraints(),
        ];
    }

    /**
     * Completa valores que dependen de otros (p. ej. códec por defecto según formato).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function normalize(array $values): array
    {
        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, list<string>>
     */
    protected function crossValidate(array $values): array
    {
        return [];
    }

    /** @param  array<string, list<string>|string>  $errors */
    private function validationError(array $errors): ValidationException
    {
        $prefixed = [];
        foreach ($errors as $key => $messages) {
            $prefixed['parameters.'.$key] = (array) $messages;
        }

        return ValidationException::withMessages($prefixed);
    }
}
