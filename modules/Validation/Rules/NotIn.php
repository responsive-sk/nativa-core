<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * Strict absence from the denied list. Lenient on empty.
 */
final class NotIn implements RuleInterface
{
    /** @var list<string> */
    private readonly array $denied;

    public function __construct(string ...$denied)
    {
        $this->denied = array_values($denied);
    }

    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }

        return !in_array($value, $this->denied, true);
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field contains a forbidden value.";
    }
}
