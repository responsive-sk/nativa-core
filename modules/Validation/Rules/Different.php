<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;
use Modules\Validation\Validation\DataPath;

/**
 * Strictly different from another (dot-notation) field. Lenient on empty.
 */
final class Different implements RuleInterface
{
    public function __construct(private readonly string $other)
    {
    }

    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }

        return $value !== DataPath::get($data, $this->other);
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field must be different from {$this->other}.";
    }
}
