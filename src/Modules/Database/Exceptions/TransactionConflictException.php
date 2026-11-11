<?php

declare(strict_types = 1);

namespace Modules\Database\Exceptions;

/**
 * TransactionConflictException — the database aborted the transaction because
 * it collided with a concurrent one.
 *
 * A deadlock (MySQL 1213, PostgreSQL 40P01) or a serialization failure under
 * REPEATABLE READ or SERIALIZABLE (SQLSTATE 40001, which SQLite raises as
 * `database is locked`) leaves nothing to keep: the engine has already rolled
 * the transaction back, and no part of it survives. Running the whole
 * transaction again from the start is therefore the expected outcome of the
 * collision rather than a bug, and that is why this class is the one failure
 * `transaction()` retries.
 *
 * A lock wait timeout is deliberately not one: the engine gave up while the
 * transaction was still alive, so the work may have half-run.
 *
 * Not mapped to an HTTP status. A conflict that still fails after the attempts
 * were spent is a server-side error, and the caller of `transaction()` gets it
 * as this exception with the driver's message as its previous.
 */
final class TransactionConflictException extends \RuntimeException
{
    /**
     * Turn a driver failure into this exception when the driver said the
     * transaction was aborted by a conflict, and into `null` when it did not.
     *
     * Only a `\PDOException` carries the SQLSTATE and the driver code, so
     * anything else is a failure this class cannot classify and the caller
     * gets it unchanged.
     */
    public static function detect(string $database, \Throwable $failure): ?self
    {
        if (!$failure instanceof \PDOException) {
            return null;
        }

        $sqlState = $failure->errorInfo[0] ?? (string) $failure->getCode();
        $driverCode = $failure->errorInfo[1] ?? null;

        // A `\PDOException` built outside a live driver (a rethrow, a test, a
        // wrapper that lost errorInfo) carries the SQLSTATE only in its
        // message, where PDO puts it as `SQLSTATE[40001]: ...`.
        if ('0' === $sqlState && 1 === preg_match('/SQLSTATE\[([0-9A-Z]{5})]/', $failure->getMessage(), $matches)) {
            $sqlState = $matches[1];
        }

        // 40001: serialization failure (PostgreSQL, MySQL, SQL Server).
        // 40P01: deadlock detected. 1213 is MySQL's deadlock without one.
        $conflict = \in_array($sqlState, ['40001', '40P01'], true)
            || 1213 === $driverCode
            || str_contains($failure->getMessage(), 'database is locked')
            || str_contains($failure->getMessage(), 'database table is locked')
            || str_contains($failure->getMessage(), 'deadlock detected');

        if (!$conflict) {
            return null;
        }

        return new self(
            sprintf(
                'The transaction on "%s" was aborted by a conflict with a concurrent one: %s',
                $database,
                $failure->getMessage(),
            ),
            0,
            $failure,
        );
    }
}
