<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use InvalidArgumentException;
use Modules\Validation\Contracts\RuleInterface;

/**
 * `preg_match()` against the given pattern (delimiters included,
 * e.g. `/^[a-z]{2}_[A-Z]{2}$/`). An invalid pattern is a programming error
 * and fails LOUD at construction, not as a validation error. Lenient on
 * empty — see Email.
 */
final class Regex implements RuleInterface
{
    public function __construct(private readonly string $pattern)
    {
        if (false === @preg_match($this->pattern, '')) {
            throw new InvalidArgumentException("Invalid regex pattern: {$this->pattern}");
        }
    }

    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        return 1 === preg_match($this->pattern, $value);
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} field has an invalid format.";
    }
}
