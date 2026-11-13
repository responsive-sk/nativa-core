<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * DSL token `string`. Lenient on empty — see Email.
 */
final class StringType implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }

        return is_string($value);
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field must be a string.";
    }
}
