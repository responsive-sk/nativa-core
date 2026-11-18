<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

/**
 * SqlDialect — the seven SQL forms where SQLite and PostgreSQL differ.
 *
 * Measured before this class existed: 43 lines in 43 SQL-bearing files, and
 * the rest of the SQL (`SELECT`, `INSERT`, `UPDATE`, `DELETE`, parameters,
 * `LIMIT`) is portable. Each method returns the fragment for the driver it was
 * built with, so a call site asks the dialect instead of guessing.
 *
 * `insertOrIgnore` is the one pair that collapsed: SQLite has understood
 * `ON CONFLICT DO NOTHING` since 3.24, so the portable form is also the
 * PostgreSQL form.
 */
final readonly class SqlDialect
{
    private function __construct(private Driver $driver)
    {
    }

    public static function forDriver(Driver $driver): self
    {
        return new self($driver);
    }

    public function driver(): string
    {
        return $this->driver->value;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Append the conflict clause that makes a duplicate a no-op.
     */
    public function insertOrIgnore(string $insert): string
    {
        return $insert . ' ON CONFLICT DO NOTHING';
    }

    /**
     * The whole column definition, because the two engines spell the primary
     * key differently on both sides of the type.
     */
    public function autoIncrementColumn(string $name = 'id'): string
    {
        return Driver::Sqlite === $this->driver
            ? $name . ' INTEGER PRIMARY KEY AUTOINCREMENT'
            : $name . ' BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY';
    }

    public function currentTimestamp(): string
    {
        return Driver::Sqlite === $this->driver ? "datetime('now')" : 'now()';
    }

    /**
     * `COLLATE NOCASE` is SQLite-only; PostgreSQL needs the expression lowered
     * (and an expression index if the comparison is hot).
     */
    public function noCase(string $expression): string
    {
        return Driver::Sqlite === $this->driver
            ? $expression . ' COLLATE NOCASE'
            : 'LOWER(' . $expression . ')';
    }

    /**
     * The statement that answers "does this table exist?", with one `?`.
     */
    public function tableExists(): string
    {
        return Driver::Sqlite === $this->driver
            ? "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?"
            : 'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?';
    }

    /**
     * `RETURNING` is always available on PostgreSQL; on SQLite it depends on
     * the server version, which `supportsReturning()` answers.
     */
    public function returning(string $columns = '*'): string
    {
        return ' RETURNING ' . $columns;
    }

    public function supportsReturning(\PDO $pdo): bool
    {
        if (Driver::Sqlite !== $this->driver) {
            return true;
        }

        $version = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);

        if (!is_string($version)) {
            // An engine that will not name its version cannot be promised a
            // syntax that arrived in 3.35.
            return false;
        }

        return version_compare($version, '3.35', '>=');
    }
}
