<?php

declare(strict_types=1);

namespace MidexCMS\Core;

final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, array<int, string>> $rules
     * @return array<string, array<int, string>>
     */
    public function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);
                $message = $this->validateRule($name, $parameter, $field, $value);

                if ($message !== null) {
                    $errors[$field][] = $message;
                }
            }
        }

        return $errors;
    }

    private function validateRule(string $rule, ?string $parameter, string $field, mixed $value): ?string
    {
        return match ($rule) {
            'required' => $this->required($field, $value),
            'string' => $this->string($field, $value),
            'email' => $this->email($field, $value),
            'min' => $this->min($field, $value, $parameter),
            'max' => $this->max($field, $value, $parameter),
            'in' => $this->in($field, $value, $parameter),
            default => null,
        };
    }

    private function required(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return sprintf('The %s field is required.', $field);
        }

        if (is_string($value) && trim($value) === '') {
            return sprintf('The %s field is required.', $field);
        }

        return null;
    }

    private function string(string $field, mixed $value): ?string
    {
        if ($value === null || is_string($value)) {
            return null;
        }

        return sprintf('The %s field must be a string.', $field);
    }

    private function email(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return null;
        }

        return sprintf('The %s field must be a valid email address.', $field);
    }

    private function min(string $field, mixed $value, ?string $parameter): ?string
    {
        if (!is_string($value) || $parameter === null) {
            return null;
        }

        if (mb_strlen($value) >= (int) $parameter) {
            return null;
        }

        return sprintf('The %s field must be at least %d characters.', $field, (int) $parameter);
    }

    private function max(string $field, mixed $value, ?string $parameter): ?string
    {
        if (!is_string($value) || $parameter === null) {
            return null;
        }

        if (mb_strlen($value) <= (int) $parameter) {
            return null;
        }

        return sprintf('The %s field must not exceed %d characters.', $field, (int) $parameter);
    }

    private function in(string $field, mixed $value, ?string $parameter): ?string
    {
        if ($parameter === null) {
            return null;
        }

        $allowed = explode(',', $parameter);

        if (is_string($value) && in_array($value, $allowed, true)) {
            return null;
        }

        return sprintf('The %s field must contain an allowed value.', $field);
    }
}
