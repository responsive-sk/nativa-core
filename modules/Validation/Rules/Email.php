<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * Valid email address. Lenient on empty (null/`''` pass) — emptiness is
 * Required's job, and the Validator skips non-required empty values anyway.
 */
final class Email implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        return false !== filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field must be a valid email address.";
    }
}
