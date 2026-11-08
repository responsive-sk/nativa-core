<?php

declare(strict_types = 1);

namespace Modules\Security\Infrastructure\Persistence;

/**
 * Statement — a prepared statement or an exception, never `false`.
 *
 * `PDO::prepare()` and `PDO::query()` are declared as returning
 * `PDOStatement|false`, because PDO can be configured to fail quietly. These
 * connections are not: they run with `PDO::ERRMODE_EXCEPTION`, so the `false`
 * branch is unreachable in practice and every caller that wrote `->execute()`
 * straight onto the result was right about the runtime and wrong about the
 * type. Unreachable is not typed away, though — so the check lives here, once
 * for this module, and raises instead of handing back a value every caller has
 * to branch on. A statement that cannot be prepared is a programming error, not
 * a state a request can recover from.
 *
 * Module-local rather than shared: the catalogue's modules are independent
 * slices with no module-to-module imports, so each carries its own copy.
 */
final class Statement
{
    /**
     * @throws \RuntimeException when PDO refuses to prepare the statement
     */
    public static function prepare(\PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->prepare($sql);

        if (false === $statement) {
            throw new \RuntimeException('Failed to prepare statement: ' . $sql);
        }

        return $statement;
    }

    /**
     * @throws \RuntimeException when PDO refuses to run the query
     */
    public static function query(\PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->query($sql);

        if (false === $statement) {
            throw new \RuntimeException('Failed to run query: ' . $sql);
        }

        return $statement;
    }
}
