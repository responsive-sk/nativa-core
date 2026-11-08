<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * Minimum: string length (`mb_strlen`) by default, numeric value once the
 * field is declared `numeric`/`integer` (`RuleParser` flips the mode, so
 * `string|min:8` never accepts the password `"9"` by value).
 */
final class Min implements RuleInterface
{
    private bool $numeric = false;

    public function __construct(private readonly int|float $min)
    {
    }

    public function asNumeric(): self
    {
        $clone = clone $this;
        $clone->numeric = true;

        return $clone;
    }

    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }

        if ($this->numeric && is_numeric($value)) {
            return $value >= $this->min;
        }

        if (is_string($value)) {
            return mb_strlen($value) >= $this->min;
        }

        if (is_array($value)) {
            return count($value) >= $this->min;
        }

        return false;
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field must be at least {$this->min}.";
    }
}
