<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

/**
 * DatabaseTargetProbe — "is this target already carrying our tables?".
 *
 * Used by the Settings panel before it saves a new engine: switching to an
 * empty server is how an operator ends up staring at a panel that booted
 * against a database with no tables, believing the switch worked and the data
 * vanished. The probe answers one question — how many tables this tree would
 * find there — and opens its own short-lived handle, so a probe failure can
 * never be confused with the panel's live connection.
 *
 * Only the server engines are probed. SQLite reads files under
 * `storage/data/`; a file that is not there is created by the migration, so
 * there is nothing to warn about.
 */
final class DatabaseTargetProbe
{
    /**
     * @throws \PDOException             when the target cannot be reached
     * @throws \InvalidArgumentException for SQLite, which has no server target
     *
     * @return int number of tables already present in the target
     */
    public static function tables(Driver $driver, string $dsn, string $prefix): int
    {
        return match ($driver) {
            Driver::Pgsql => self::postgresTables($dsn),
            Driver::MariaDb => self::mariaDbTables($dsn, $prefix),
            Driver::Sqlite => throw new \InvalidArgumentException(
                'SQLite reads a file per module; there is no server target to probe.',
            ),
        };
    }

    private static function postgresTables(string $dsn): int
    {
        $pdo = new \PDO(DsnBuilder::build(Driver::Pgsql, $dsn), null, null, self::options());

        $statement = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables\n"
            . " WHERE table_schema NOT IN ('pg_catalog', 'information_schema')",
        );

        if (false === $statement) {
            return 0;
        }

        $count = $statement->fetchColumn();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * One database per module, all named `<prefix><module>`, so the question
     * is how many tables live in any database under the prefix.
     */
    private static function mariaDbTables(string $dsn, string $prefix): int
    {
        $pdo = new \PDO(
            DsnBuilder::build(Driver::MariaDb, DsnBuilder::withCharset(DsnBuilder::withoutDatabase($dsn))),
            null,
            null,
            self::options(),
        );

        $statement = $pdo->query('SELECT table_schema FROM information_schema.tables');

        if (false === $statement) {
            return 0;
        }

        $tables = 0;

        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $schema) {
            if (is_string($schema) && str_starts_with($schema, $prefix)) {
                $tables++;
            }
        }

        return $tables;
    }

    /**
     * @return array<int, mixed>
     */
    private static function options(): array
    {
        return [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            // A probe that hangs on an unreachable host would hang the save.
            \PDO::ATTR_TIMEOUT => 5,
        ];
    }
}
