<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * Absolute URL (scheme required). Lenient on empty — see Email.
 */
final class Url implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        return false !== filter_var($value, FILTER_VALIDATE_URL);
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field must be a valid URL.";
    }
}
