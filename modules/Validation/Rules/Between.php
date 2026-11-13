<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * Inclusive range, string length by default, numeric value in numeric mode.
 */
final class Between implements RuleInterface
{
    private bool $numeric = false;

    public function __construct(
        private readonly int|float $min,
        private readonly int|float $max,
    ) {
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
            return $value >= $this->min && $value <= $this->max;
        }

        if (is_string($value)) {
            $length = mb_strlen($value);

            return $length >= $this->min && $length <= $this->max;
        }

        if (is_array($value)) {
            $count = count($value);

            return $count >= $this->min && $count <= $this->max;
        }

        return false;
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field must be between {$this->min} and {$this->max}.";
    }
}
