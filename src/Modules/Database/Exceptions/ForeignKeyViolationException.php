<?php

declare(strict_types = 1);

namespace Modules\Database\Exceptions;

/**
 * ForeignKeyViolationException — a write refused because a referenced row does
 * not exist (or is still referenced by one).
 *
 * PostgreSQL says 23503. SQLite says 23000 with driver code 19 and
 * `FOREIGN KEY constraint failed`, the same driver code as a unique violation,
 * which is why the message decides there.
 *
 * Extends `\PDOException` for the same reason as its unique sibling: existing
 * catches keep working and the driver's `errorInfo` survives.
 */
final class ForeignKeyViolationException extends \PDOException
{
    public static function detect(string $database, \Throwable $failure): ?self
    {
        $state = SqlState::of($failure);

        if (null === $state || !$failure instanceof \PDOException) {
            return null;
        }

        $foreignKey = '23503' === $state['sqlstate']
            || ('23000' === $state['sqlstate']
                && 19 === $state['driver']
                && str_contains($failure->getMessage(), 'FOREIGN KEY constraint failed'))
            || str_contains($failure->getMessage(), 'violates foreign key constraint');

        if (!$foreignKey) {
            return null;
        }

        $exception = new self(
            sprintf('Foreign key violation on "%s": %s', $database, $failure->getMessage()),
            (int) $failure->getCode(),
            $failure,
        );
        $exception->errorInfo = $failure->errorInfo;

        return $exception;
    }
}
