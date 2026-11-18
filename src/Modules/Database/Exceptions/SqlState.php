<?php

declare(strict_types = 1);

namespace Modules\Database\Exceptions;

/**
 * SqlState — the one reader of a driver failure's SQLSTATE and driver code.
 *
 * Two classifiers need the same two fields (`TransactionConflictException` and
 * the constraint exceptions), and a second copy of the parsing is a second
 * place for the fallback to drift. A `\PDOException` built outside a live
 * driver (a rethrow, a test double) carries the SQLSTATE only in its message,
 * where PDO puts it as `SQLSTATE[40001]: ...`, so that form is read too.
 */
final class SqlState
{
    /**
     * @return array{sqlstate: string, driver: int|null}|null null when the
     *                                                        failure did not come from a driver
     */
    public static function of(\Throwable $failure): ?array
    {
        if (!$failure instanceof \PDOException) {
            return null;
        }

        $sqlState = $failure->errorInfo[0] ?? null;
        $driver = $failure->errorInfo[1] ?? null;

        if (null === $sqlState) {
            $sqlState = $failure->getCode();
        }

        if ('0' === (string) $sqlState
            && 1 === preg_match('/SQLSTATE\[([0-9A-Z]{5})]/', $failure->getMessage(), $matches)
        ) {
            $sqlState = $matches[1];
        }

        return [
            'sqlstate' => (string) $sqlState,
            'driver'   => is_int($driver) ? $driver : null,
        ];
    }
}
