<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * DSL token `array`. Lenient on empty — see Email.
 */
final class ArrayType implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }

        return is_array($value);
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field must be an array.";
    }
}
