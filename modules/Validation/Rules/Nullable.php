<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * Meta rule: when the value is empty, the Validator skips every other rule
 * for the field (including Required). Never fails, never reports.
 */
final class Nullable implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        return true;
    }

    public function message(string $field, mixed $value): string
    {
        return '';
    }
}
