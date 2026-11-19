<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

/**
 * SqlDialect — the SQL forms where SQLite, PostgreSQL and MariaDB differ.
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

    public function isMariaDb(): bool
    {
        return Driver::MariaDb === $this->driver;
    }

    public function quoteIdentifier(string $identifier): string
    {
        if ($this->isMariaDb()) {
            return '`' . str_replace('`', '``', $identifier) . '`';
        }

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

        if ($this->isMariaDb()) {
            return (string) preg_replace('/^(\s*)INSERT\s+/i', '$1INSERT IGNORE ', $insert, 1);
        }

        return $insert . ' ON CONFLICT DO NOTHING';
    }

    /**
     * An insert that updates the existing row on a conflict.
     *
     * SQLite and PostgreSQL use `ON CONFLICT (column) DO UPDATE SET` and the
     * `excluded` alias. MariaDB uses `ON DUPLICATE KEY UPDATE` and `VALUES()`;
     * the conflict column is not part of its syntax. The caller keeps one
     * update list, and this method rewrites only the MariaDB alias.
     */
    public function upsert(string $insert, string $conflict, string $updates): string
    {
        if ($this->isMariaDb()) {
            $updates = (string) preg_replace(
                '/\bexcluded\.([a-z_][a-z0-9_]*)/i',
                'VALUES($1)',
                $updates,
            );

            return $insert . ' ON DUPLICATE KEY UPDATE ' . $updates;
        }

        return $insert . ' ON CONFLICT(' . $conflict . ') DO UPDATE SET ' . $updates;
    }

    /**
     * The whole column definition, because the two engines spell the primary
     * key differently on both sides of the type.
     */
    public function autoIncrementColumn(string $name = 'id'): string
    {
        return match ($this->driver) {
            Driver::Sqlite => $name . ' INTEGER PRIMARY KEY AUTOINCREMENT',
            Driver::Pgsql => $name . ' BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY',
            Driver::MariaDb => $name . ' BIGINT AUTO_INCREMENT PRIMARY KEY',
        };
    }

    public function currentTimestamp(): string
    {
        return match ($this->driver) {
            Driver::Sqlite => "datetime('now')",
            Driver::Pgsql => 'now()',
            Driver::MariaDb => 'CURRENT_TIMESTAMP',
        };
    }

    /**
     * Open a transaction that will write, before it reads what it writes.
     *
     * SQLite needs the write lock up front (`BEGIN IMMEDIATE`); PDO's deferred
     * begin would let two claimants read the same row before either update.
     * PostgreSQL and MariaDB take row locks instead, which is what
     * `skipLocked()` is for, so a plain begin is the right shape there.
     */
    public function beginWrite(): string
    {
        return match ($this->driver) {
            Driver::Sqlite => 'BEGIN IMMEDIATE',
            Driver::Pgsql => 'BEGIN',
            Driver::MariaDb => 'START TRANSACTION',
        };
    }

    /**
     * The strongest transaction the engine offers, for a drain that must not
     * run the same row twice.
     *
     * SQLite serialises writers by taking the write lock, so its usual begin
     * already provides the guarantee; PostgreSQL has to be asked. MariaDB's
     * `START TRANSACTION` has no isolation clause, so the session was already
     * set to SERIALIZABLE in `SessionSettings` and the plain begin inherits it.
     */
    public function beginSerializable(): string
    {
        return match ($this->driver) {
            Driver::Sqlite => 'BEGIN IMMEDIATE',
            Driver::Pgsql => 'BEGIN ISOLATION LEVEL SERIALIZABLE',
            Driver::MariaDb => 'START TRANSACTION',
        };
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
     * PostgreSQL adds a cast interval to `now()`; MariaDB needs a numeric
     * amount and a unit keyword, so the amount is read from the interval
     * string and the unit is supplied by the caller (or inferred from a
     * literal).
     */
    public function shiftedTimestamp(string $parameter, ?string $unit = null): string
    {
        if ($this->isMariaDb()) {
            $expression = $this->mariaDbIntervalExpression($parameter);
            $unit = $this->mariaDbIntervalUnit($parameter, $unit);

            return 'DATE_ADD(NOW(), INTERVAL CAST(SUBSTRING_INDEX(' . $expression . ", ' ', 1) AS SIGNED) {$unit})";
        }

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
        return match ($this->driver) {
            Driver::Sqlite, Driver::MariaDb => 'DATETIME',
            Driver::Pgsql => 'TIMESTAMPTZ',
        };
    }

    /**
     * The literal for a boolean, in a column default or in a comparison.
     *
     * SQLite stores `1`/`0`; PostgreSQL refuses an integer against a
     * `BOOLEAN` column and needs the keyword.
     */
    public function booleanLiteral(bool $value): string
    {
        if ($this->isSqlite() || $this->isMariaDb()) {
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
     * byte-identical to the SQL it replaced; PostgreSQL and MariaDB lower both
     * sides, because their default collations are not a portable guarantee.
     */
    public function noCaseEquals(string $column, string $parameter): string
    {
        return $this->isSqlite()
            ? $column . ' = ' . $parameter . ' COLLATE NOCASE'
            : 'LOWER(' . $column . ') = LOWER(' . $parameter . ')';
    }

    /**
     * The portable concatenation: `||` on SQLite and PostgreSQL, CONCAT on
     * MariaDB where `||` is logical OR unless PIPES_AS_CONCAT is enabled.
     */
    public function concat(string ...$parts): string
    {
        return $this->isMariaDb()
            ? 'CONCAT(' . implode(', ', $parts) . ')'
            : implode(' || ', $parts);
    }

    /**
     * The statement that answers "does this table exist?", with one `?`.
     */
    public function tableExists(): string
    {
        if ($this->isSqlite()) {
            return "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?";
        }

        if ($this->isMariaDb()) {
            return 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?';
        }

        return 'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?';
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
        } elseif ($this->isMariaDb()) {
            $statement = $pdo->prepare(
                'SELECT column_name AS name FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
            );

            if (false !== $statement) {
                $statement->execute([$table]);
            }
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

        if ($this->isMariaDb()) {
            return [
                "DROP TRIGGER IF EXISTS {$table}_no_update",
                "CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$quoted} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
                "DROP TRIGGER IF EXISTS {$table}_no_delete",
                "CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$quoted} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
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
        return $this->isMariaDb() ? '' : ' RETURNING ' . $columns;
    }

    public function supportsReturning(\PDO $pdo): bool
    {
        if ($this->isMariaDb()) {
            return false;
        }

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

    /**
     * The tables a snapshot must carry, in a stable order.
     *
     * SQLite answers from `sqlite_master`; PostgreSQL from `pg_catalog`. The
     * internal SQLite tables are excluded because they are engine state, not
     * module data.
     *
     * @return list<string>
     */
    public function tableNames(\PDO $pdo): array
    {
        if ($this->isSqlite()) {
            $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY rowid");
        } elseif ($this->isMariaDb()) {
            $statement = $pdo->query("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name");
        } else {
            $statement = $pdo->query('SELECT tablename AS name FROM pg_catalog.pg_tables WHERE schemaname = current_schema() ORDER BY tablename');
        }

        if (false === $statement) {
            throw new \RuntimeException('Cannot list the tables of this database.');
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
     * Strong column types, by column name and declaration order.
     *
     * The snapshot uses these only to explain its data and to convert the two
     * boolean spellings; a column type never decides whether a value is
     * exported.
     *
     * @return array<string, string>
     */
    public function columnTypes(\PDO $pdo, string $table): array
    {
        if ($this->isSqlite()) {
            $statement = $pdo->query('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')');
        } elseif ($this->isMariaDb()) {
            $statement = $pdo->prepare(
                'SELECT column_name AS name, data_type AS type, column_type AS full_type
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
            );

            if (false !== $statement) {
                $statement->execute([$table]);
            }
        } else {
            $statement = $pdo->prepare(
                'SELECT column_name AS name, data_type AS type FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position',
            );

            if (false !== $statement) {
                $statement->execute([$table]);
            }
        }

        if (false === $statement) {
            return [];
        }

        $types = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null)) {
                continue;
            }

            $type = is_string($row['type'] ?? null) ? $row['type'] : '';
            $fullType = strtolower(is_string($row['full_type'] ?? null) ? $row['full_type'] : '');

            // MariaDB reports BOOLEAN as tinyint(1), not as a boolean type.
            if ($this->isMariaDb() && 'tinyint' === strtolower($type) && str_starts_with($fullType, 'tinyint(1)')) {
                $type = 'boolean';
            }

            $types[$row['name']] = $type;
        }

        return $types;
    }

    /**
     * Foreign-key edges, child table => referenced tables.
     *
     * A self-reference is left out: it is a row-order question inside one
     * table, not a table-order question, and treating it as a dependency would
     * make the topological sort stall on a valid schema.
     *
     * @return array<string, list<string>>
     */
    public function foreignKeys(\PDO $pdo): array
    {
        /** @var array<string, array<string, true>> $edges */
        $edges = [];

        if ($this->isSqlite()) {
            foreach ($this->tableNames($pdo) as $table) {
                $statement = $pdo->query('PRAGMA foreign_key_list(' . $this->quoteIdentifier($table) . ')');

                if (false === $statement) {
                    continue;
                }

                foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $parent = is_array($row) ? ($row['table'] ?? null) : null;

                    if (is_string($parent) && $parent !== $table) {
                        $edges[$table][$parent] = true;
                    }
                }
            }
        } elseif ($this->isMariaDb()) {
            $statement = $pdo->query(<<<'SQL'
                SELECT kcu.TABLE_NAME AS child, kcu.REFERENCED_TABLE_NAME AS parent
                FROM information_schema.KEY_COLUMN_USAGE AS kcu
                WHERE kcu.TABLE_SCHEMA = DATABASE()
                  AND kcu.REFERENCED_TABLE_SCHEMA = DATABASE()
                  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                  AND kcu.REFERENCED_TABLE_NAME <> kcu.TABLE_NAME
                GROUP BY kcu.TABLE_NAME, kcu.REFERENCED_TABLE_NAME
                ORDER BY kcu.TABLE_NAME, kcu.REFERENCED_TABLE_NAME
                SQL);

            if (false !== $statement) {
                foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $child = is_array($row) ? ($row['child'] ?? null) : null;
                    $parent = is_array($row) ? ($row['parent'] ?? null) : null;

                    if (is_string($child) && is_string($parent)) {
                        $edges[$child][$parent] = true;
                    }
                }
            }
        } else {
            $statement = $pdo->query(<<<'SQL'
                SELECT tc.table_name AS child, ccu.table_name AS parent
                FROM information_schema.table_constraints AS tc
                JOIN information_schema.constraint_column_usage AS ccu
                  ON ccu.constraint_name = tc.constraint_name
                 AND ccu.constraint_schema = tc.constraint_schema
                WHERE tc.constraint_type = 'FOREIGN KEY'
                  AND tc.table_schema = current_schema()
                  AND ccu.table_schema = current_schema()
                  AND ccu.table_name <> tc.table_name
                GROUP BY tc.table_name, ccu.table_name
                ORDER BY tc.table_name, ccu.table_name
                SQL);

            if (false !== $statement) {
                foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $child = is_array($row) ? ($row['child'] ?? null) : null;
                    $parent = is_array($row) ? ($row['parent'] ?? null) : null;

                    if (is_string($child) && is_string($parent)) {
                        $edges[$child][$parent] = true;
                    }
                }
            }
        }

        $result = [];

        foreach ($edges as $table => $parents) {
            $result[$table] = array_keys($parents);
            sort($result[$table]);
        }

        return $result;
    }

    /**
     * The identity columns of a table, in declaration order.
     *
     * SQLite updates `sqlite_sequence` itself when an explicit id is inserted,
     * so it has nothing to reset. PostgreSQL keeps its sequence separate, and
     * an import that inserts explicit ids has to move it past the highest row.
     *
     * @return list<string>
     */
    public function identityColumns(\PDO $pdo, string $table): array
    {
        if ($this->isSqlite() || $this->isMariaDb()) {
            return [];
        }

        $statement = $pdo->prepare(
            "SELECT column_name AS name FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ?
               AND (is_identity = 'YES' OR column_default LIKE 'nextval(%')
             ORDER BY ordinal_position",
        );

        if (false === $statement) {
            return [];
        }

        $statement->execute([$table]);

        $names = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }

    /**
     * Move a PostgreSQL identity sequence past the imported maximum.
     *
     * `setval` is given the maximum only when the table has rows: an empty
     * table must leave `is_called` false so the next insert gets 1, not 2.
     */
    public function resetIdentity(\PDO $pdo, string $table, string $column): void
    {
        if ($this->isSqlite() || $this->isMariaDb()) {
            return;
        }

        $tableLiteral = $pdo->quote($table);
        $columnLiteral = $pdo->quote($column);

        if (false === $tableLiteral || false === $columnLiteral) {
            throw new \RuntimeException(sprintf('Cannot quote the identity column %s.%s.', $table, $column));
        }

        $quotedTable = $this->quoteIdentifier($table);
        $quotedColumn = $this->quoteIdentifier($column);

        $pdo->exec(sprintf(
            'SELECT setval(pg_get_serial_sequence(%s, %s), COALESCE((SELECT MAX(%s) FROM %s), 1), EXISTS (SELECT 1 FROM %s))',
            $tableLiteral,
            $columnLiteral,
            $quotedColumn,
            $quotedTable,
            $quotedTable,
        ));
    }

    /**
     * The PostgreSQL clause that lets an import write an explicit identity id.
     *
     * `GENERATED ALWAYS` refuses a caller-supplied value without it. SQLite
     * has no such restriction, so its fragment is empty.
     */
    public function overridingIdentity(): string
    {
        return $this->isSqlite() || $this->isMariaDb() ? '' : ' OVERRIDING SYSTEM VALUE';
    }

    /**
     * MariaDB has no `||` concatenation without PIPES_AS_CONCAT, and the
     * retention queries build a signed interval from a bound amount. One
     * explicit CONCAT keeps both drivers on the same call site.
     */
    private function mariaDbIntervalExpression(string $parameter): string
    {
        if (!str_contains($parameter, '||')) {
            return $parameter;
        }

        $parts = array_map(trim(...), explode('||', $parameter));

        return 'CONCAT(' . implode(', ', $parts) . ')';
    }

    private function mariaDbIntervalUnit(string $parameter, ?string $unit): string
    {
        if (null !== $unit) {
            return $this->normaliseMariaDbIntervalUnit($unit);
        }

        if (1 === preg_match("/^'[+-]?\d+\s+(seconds?|minutes?|hours?|days?|weeks?|months?|years?)'$/i", trim($parameter), $matches)) {
            return $this->normaliseMariaDbIntervalUnit($matches[1]);
        }

        return 'DAY';
    }

    private function normaliseMariaDbIntervalUnit(string $unit): string
    {
        $unit = strtoupper(rtrim($unit, 's'));

        if (!in_array($unit, ['SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported MariaDB interval unit "%s".', $unit));
        }

        return $unit;
    }
}
