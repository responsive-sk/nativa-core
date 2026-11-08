<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;

/**
 * Strict membership in the allowed list. Lenient on empty.
 */
final class In implements RuleInterface
{
    /** @var list<string> */
    private readonly array $allowed;

    public function __construct(string ...$allowed)
    {
        $this->allowed = array_values($allowed);
    }

    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }

        return in_array($value, $this->allowed, true);
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field must be one of: " . implode(', ', $this->allowed) . '.';
    }
}
