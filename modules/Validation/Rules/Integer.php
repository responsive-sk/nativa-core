<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * Whole numbers: ints and integer strings (`'42'`, `'-3'`). Also switches
 * Min/Max/Between into numeric mode for the field.
 */
final class Integer implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }

        if (is_int($value)) {
            return true;
        }

        return is_string($value) && false !== filter_var($value, FILTER_VALIDATE_INT);
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field must be an integer.";
    }
}
