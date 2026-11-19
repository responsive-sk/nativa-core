<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

/**
 * SqlDialect — the SQL forms where SQLite and PostgreSQL differ.
 *
 * Measured before this class existed: 43 lines in 43 SQL-bearing files, and
 * the rest of the SQL (`SELECT`, `INSERT`, `UPDATE`, `DELETE`, parameters,
 * `LIMIT`) is portable. Each method returns the fragment for the driver it was
 * built with, so a call site asks the dialect instead of guessing.
 *
 * `insertOrIgnore` does not collapse: SQLite rejects `ON CONFLICT DO NOTHING`
 * after an `INSERT ... SELECT` (measured on 3.54, syntax error at `DO`), and
 * three of the call sites are backfills of exactly that shape. So SQLite keeps
 * its own `INSERT OR IGNORE` prefix and PostgreSQL gets the clause.
 *
 * The DDL vocabulary (`timestampType`, `booleanDefault`, `autoIncrementColumn`,
 * `immutableTable`, `columnNames`) is what the module migrators ask for, because
 * a schema is where the two engines differ most: PostgreSQL has no `DATETIME`,
 * refuses an integer default on a boolean, and enforces append-only with a
 * trigger function rather than a trigger body.
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

    /**
     * The dialect of an open handle, read from the driver PHP connected with.
     *
     * A migrator is handed a `\PDO` and nothing else, so the handle has to name
     * its own driver; guessing from the DSN would be a second source of truth.
     */
    public static function forPdo(\PDO $pdo): self
    {
        $name = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if (!is_string($name)) {
            throw new \InvalidArgumentException('The connection does not name its driver.');
        }

        return self::forDriver(Driver::fromName($name));
    }

    public function driver(): string
    {
        return $this->driver->value;
    }

    public function isSqlite(): bool
    {
        return Driver::Sqlite === $this->driver;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Make a duplicate a no-op, in the syntax the driver accepts.
     *
     * SQLite's `ON CONFLICT` clause is rejected when the insert selects its
     * rows ("near DO: syntax error"), so its prefix form is used and the
     * indentation in front of `INSERT` is preserved.
     */
    public function insertOrIgnore(string $insert): string
    {
        if ($this->isSqlite()) {
            return (string) preg_replace('/^(\s*)INSERT\s+/i', '$1INSERT OR IGNORE ', $insert, 1);
        }

        return $insert . ' ON CONFLICT DO NOTHING';
    }

    /**
     * The whole column definition, because the two engines spell the primary
     * key differently on both sides of the type.
     */
    public function autoIncrementColumn(string $name = 'id'): string
    {
        return $this->isSqlite()
            ? $name . ' INTEGER PRIMARY KEY AUTOINCREMENT'
            : $name . ' BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY';
    }

    public function currentTimestamp(): string
    {
        return $this->isSqlite() ? "datetime('now')" : 'now()';
    }

    /**
     * Open a transaction that will write, before it reads what it writes.
     *
     * SQLite needs the write lock up front (`BEGIN IMMEDIATE`); PDO's deferred
     * begin would let two claimants read the same row before either update.
     * PostgreSQL takes row locks instead, which is what `skipLocked()` is for,
     * so a plain `BEGIN` is the right shape there.
     */
    public function beginWrite(): string
    {
        return $this->isSqlite() ? 'BEGIN IMMEDIATE' : 'BEGIN';
    }

    /**
     * The strongest transaction the engine offers, for a drain that must not
     * run the same row twice.
     *
     * SQLite serialises writers by taking the write lock, so its usual begin
     * already provides the guarantee; PostgreSQL has to be asked.
     */
    public function beginSerializable(): string
    {
        return $this->isSqlite() ? 'BEGIN IMMEDIATE' : 'BEGIN ISOLATION LEVEL SERIALIZABLE';
    }

    /**
     * The clause that makes a locked row skip instead of waiting.
     */
    public function skipLocked(): string
    {
        return $this->isSqlite() ? '' : ' FOR UPDATE SKIP LOCKED';
    }

    /**
     * The column that breaks a tie between rows written in the same second.
     *
     * SQLite's `rowid` is monotonic insert order and is what the admin lists
     * already sort on. PostgreSQL has no such column, so the primary key is
     * the portable tiebreak (deterministic, if not insertion-ordered).
     */
    public function insertionOrder(): string
    {
        return $this->isSqlite() ? 'rowid' : 'id';
    }

    /**
     * A timestamp shifted by an interval bound as a parameter, such as
     * `'-7 days'` or `'-1 hour'`.
     *
     * SQLite takes the interval as a second argument to `datetime()`;
     * PostgreSQL adds a cast interval to `now()`.
     */
    public function shiftedTimestamp(string $parameter): string
    {
        return $this->isSqlite()
            ? "datetime('now', {$parameter})"
            : "now() + ({$parameter})::interval";
    }

    /**
     * The column type for a timestamp the application reads and writes as text.
     *
     * PostgreSQL's `TIMESTAMPTZ` is the closest match to SQLite's `DATETIME`
     * and, like it, compares as text with `CURRENT_TIMESTAMP` defaults.
     */
    public function timestampType(): string
    {
        return $this->isSqlite() ? 'DATETIME' : 'TIMESTAMPTZ';
    }

    /**
     * The literal for a boolean, in a column default or in a comparison.
     *
     * SQLite stores `1`/`0`; PostgreSQL refuses an integer against a
     * `BOOLEAN` column and needs the keyword.
     */
    public function booleanLiteral(bool $value): string
    {
        if ($this->isSqlite()) {
            return $value ? '1' : '0';
        }

        return $value ? 'TRUE' : 'FALSE';
    }

    public function booleanDefault(bool $value): string
    {
        return $this->booleanLiteral($value);
    }

    /**
     * `COLLATE NOCASE` is SQLite-only; PostgreSQL needs the expression lowered
     * (and an expression index if the comparison is hot).
     */
    public function noCase(string $expression): string
    {
        return $this->isSqlite()
            ? $expression . ' COLLATE NOCASE'
            : 'LOWER(' . $expression . ')';
    }

    /**
     * A case-insensitive equality, with the parameter named by the caller.
     *
     * SQLite keeps its collation on the parameter side, so the column side is
     * byte-identical to the SQL it replaced; PostgreSQL lowers both sides,
     * because its collation is not case-folding.
     */
    public function noCaseEquals(string $column, string $parameter): string
    {
        return $this->isSqlite()
            ? $column . ' = ' . $parameter . ' COLLATE NOCASE'
            : 'LOWER(' . $column . ') = LOWER(' . $parameter . ')';
    }

    /**
     * The statement that answers "does this table exist?", with one `?`.
     */
    public function tableExists(): string
    {
        return $this->isSqlite()
            ? "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?"
            : 'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?';
    }

    /**
     * The columns of a table, in declaration order.
     *
     * The migrators ask before they `ALTER TABLE`, because `CREATE TABLE IF NOT
     * EXISTS` does not add a column to a table that already exists.
     *
     * @return list<string>
     */
    public function columnNames(\PDO $pdo, string $table): array
    {
        if ($this->isSqlite()) {
            $statement = $pdo->query('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')');
        } else {
            $statement = $pdo->prepare(
                'SELECT column_name AS name FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = ?',
            );

            if (false !== $statement) {
                $statement->execute([$table]);
            }
        }

        if (false === $statement) {
            return [];
        }

        $names = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }

    /**
     * The statements that make a table append-only.
     *
     * SQLite refuses the write inside a trigger body; PostgreSQL has no body,
     * so it raises from a plpgsql function that the two triggers call.
     *
     * @return list<string>
     */
    public function immutableTable(string $table, string $message): array
    {
        $quoted = $this->quoteIdentifier($table);
        $message = str_replace("'", "''", $message);

        if ($this->isSqlite()) {
            return [
                "CREATE TRIGGER IF NOT EXISTS {$table}_no_update BEFORE UPDATE ON {$quoted} BEGIN SELECT RAISE(ABORT, '{$message}'); END",
                "CREATE TRIGGER IF NOT EXISTS {$table}_no_delete BEFORE DELETE ON {$quoted} BEGIN SELECT RAISE(ABORT, '{$message}'); END",
            ];
        }

        $function = 'nativa_refuse_' . $table . '_write';

        return [
            "CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS \$body\$ BEGIN RAISE EXCEPTION '%', '{$message}'; END; \$body\$",
            "DROP TRIGGER IF EXISTS {$table}_no_update ON {$quoted}",
            "CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$quoted} FOR EACH ROW EXECUTE FUNCTION {$function}()",
            "DROP TRIGGER IF EXISTS {$table}_no_delete ON {$quoted}",
            "CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$quoted} FOR EACH ROW EXECUTE FUNCTION {$function}()",
        ];
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
        if (!$this->isSqlite()) {
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
