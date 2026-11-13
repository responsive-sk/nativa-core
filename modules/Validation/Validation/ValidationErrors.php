<?php

declare(strict_types = 1);

namespace Modules\Validation\Validation;

/**
 * Validation errors keyed by concrete field path (`photos.1` for wildcards).
 */
final class ValidationErrors
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    public function add(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /**
     * Every message, in field-then-rule order — the shape form renderers and
     * `AuthService::register()` return.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $flat = [];

        foreach ($this->errors as $messages) {
            foreach ($messages as $message) {
                $flat[] = $message;
            }
        }

        return $flat;
    }

    /**
     * @return list<string>
     */
    public function forField(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->errors;
    }

    public function isEmpty(): bool
    {
        return [] === $this->errors;
    }

    public function isNotEmpty(): bool
    {
        return [] !== $this->errors;
    }

    public function count(): int
    {
        return count($this->all());
    }
}
