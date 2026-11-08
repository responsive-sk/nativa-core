<?php

declare(strict_types = 1);

namespace Modules\Validation\Validation;

use RuntimeException;

/**
 * Thrown by `Validator::validateOrFail()` — carries the errors so the
 * caller renders them instead of parsing an exception message.
 */
final class ValidationException extends RuntimeException
{
    public function __construct(private readonly ValidationErrors $errors)
    {
        parent::__construct('Validation failed: ' . implode('; ', $errors->all()));
    }

    public static function withErrors(ValidationErrors $errors): self
    {
        return new self($errors);
    }

    public function errors(): ValidationErrors
    {
        return $this->errors;
    }
}
