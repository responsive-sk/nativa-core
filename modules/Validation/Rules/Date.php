<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * Anything `strtotime()` parses (dates, datetimes, relative phrases like
 * `tomorrow`). Lenient on empty — see Email.
 */
final class Date implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }

        if (!is_string($value) && !is_int($value)) {
            return false;
        }

        return false !== strtotime((string) $value);
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field must be a valid date.";
    }
}
