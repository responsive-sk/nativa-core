<?php

declare(strict_types = 1);

namespace Modules\Database\Exceptions;

/**
 * ConstraintFailure — translate a driver failure into a domain one, or return
 * it unchanged.
 *
 * The connection handles call this in their `catch (\PDOException)` blocks, so
 * a repository can tell "the row already exists" from "the database is gone"
 * without parsing SQLSTATE itself. Anything the classifiers do not recognise
 * comes back as the original exception: a translator that guesses is worse
 * than no translator.
 */
final class ConstraintFailure
{
    public static function translate(string $database, \PDOException $failure): \PDOException
    {
        return UniqueViolationException::detect($database, $failure)
            ?? ForeignKeyViolationException::detect($database, $failure)
            ?? $failure;
    }
}
