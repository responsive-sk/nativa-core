<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * Real booleans plus the form encodings (`0`/`1`, `'0'`/`'1'`). Lenient on
 * empty — see Email.
 */
final class Boolean implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }

        return true === $value || false === $value || 1 === $value || 0 === $value || '1' === $value || '0' === $value;
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field must be true or false.";
    }
}
