<?php

declare(strict_types = 1);

namespace Modules\Audit\Infrastructure\Persistence;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Contracts\StatementInterface;

/**
 * Statement — the statement contract, with no `false` branch to check.
 *
 * The connection already raises when it cannot prepare or run SQL, so this
 * helper is only the module-local alias the repositories share. It takes the
 * contract rather than `\PDO` so the same repository works on a plain SQLite
 * file and on a read/write connection without knowing which one it holds.
 *
 * Module-local rather than shared: the catalogue's modules are independent
 * slices with no module-to-module imports, so each carries its own copy.
 */
final class Statement
{
    public static function prepare(ConnectionInterface $connection, string $sql): StatementInterface
    {
        return $connection->prepare($sql);
    }

    public static function query(ConnectionInterface $connection, string $sql): StatementInterface
    {
        return $connection->query($sql);
    }
}
