<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * `is_numeric()` values. Also switches Min/Max/Between into numeric mode.
 */
final class Numeric implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }

        return is_numeric($value);
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field must be a number.";
    }
}
