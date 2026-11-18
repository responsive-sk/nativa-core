<?php

declare(strict_types = 1);

namespace Modules\Database\Exceptions;

/**
 * UniqueViolationException — a write refused because a unique key already
 * exists.
 *
 * PostgreSQL says 23505. SQLite says 23000 with driver code 19 and
 * `UNIQUE constraint failed` in the message, which is the same driver code it
 * uses for a foreign key, so the message is what separates the two there.
 * MySQL's 1062 is read as well because it costs one comparison.
 *
 * Extends `\PDOException` on purpose: every existing `catch (\PDOException)`
 * keeps working, and the driver's `errorInfo` travels with the exception so a
 * caller that inspects it sees what PDO reported.
 */
final class UniqueViolationException extends \PDOException
{
    public static function detect(string $database, \Throwable $failure): ?self
    {
        $state = SqlState::of($failure);

        if (null === $state || !$failure instanceof \PDOException) {
            return null;
        }

        $unique = '23505' === $state['sqlstate']
            || 1062 === $state['driver']
            || ('23000' === $state['sqlstate']
                && 19 === $state['driver']
                && str_contains($failure->getMessage(), 'UNIQUE constraint failed'))
            || str_contains($failure->getMessage(), 'duplicate key value violates unique constraint');

        if (!$unique) {
            return null;
        }

        $exception = new self(
            sprintf('Unique violation on "%s": %s', $database, $failure->getMessage()),
            (int) $failure->getCode(),
            $failure,
        );
        $exception->errorInfo = $failure->errorInfo;

        return $exception;
    }
}
