<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * Present and non-blank (null, `''`, whitespace-only and `[]` fail).
 */
final class Required implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || [] === $value) {
            return false;
        }

        if (is_string($value) && '' === trim($value)) {
            return false;
        }

        return true;
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field is required.";
    }
}
