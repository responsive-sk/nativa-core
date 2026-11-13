<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * Maximum: string length (`mb_strlen`) by default, numeric value in numeric
 * mode (see Min).
 */
final class Max implements RuleInterface
{
    private bool $numeric = false;

    public function __construct(private readonly int|float $max)
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
            return $value <= $this->max;
        }

        if (is_string($value)) {
            return mb_strlen($value) <= $this->max;
        }

        if (is_array($value)) {
            return count($value) <= $this->max;
        }

        return false;
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field must be at most {$this->max}.";
    }
}
