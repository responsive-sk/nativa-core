<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Application\Services;

use Modules\Clickhouse\Domain\SqliteAttachment;
use Modules\Clickhouse\Domain\SqliteTypeMap;

/**
 * TypedTablePlanner — the explicit `CREATE TABLE` for a table whose file
 * already says more than inference can hear.
 *
 * Inference is the documented default and stays the default: a SQLite
 * declaration containing `INT` becomes `Int64` and everything else becomes
 * `String`. A table is planned explicitly only when at least one column's
 * declaration names a type the doc's second table parses from `TEXT` —
 * `DATETIME`, `UUID`, `DECIMAL(10, 2)`, `FixedString(36)` and friends. Then
 * every column is written out, explicit where the declaration said so and
 * inferred otherwise, because a ClickHouse table definition is all-or-nothing.
 *
 * This is a guess about the data, not a fact about the column: a `DATETIME`
 * column holding `''` is rejected at read time (`CANNOT_PARSE_DATETIME`,
 * measured on 26.11.1.973), not silently coerced. Every such column therefore
 * earns one warning line naming it, and `attach` prints them — an operator who
 * typed nothing never sees them.
 *
 * **Where the table is created is measured, not assumed.** The doc does not
 * show the syntax, and the obvious one — `CREATE TABLE <attached_db>.<table>`
 * — is refused on 26.11.1.973: the SQLite database engine exposes every table
 * of the file implicitly, so the explicit definition collides with
 * `TABLE_ALREADY_EXISTS` and `DROP TABLE` is not implemented for it either.
 * An explicit definition *is* accepted in a normal database, so the planner
 * writes it into the server's default database under the name
 * `<prefix><name>__<table>` (unqualified, so it lands wherever the connection's
 * default is) and the operator reads that name. The attached `nativa_*`
 * database keeps the inferred types; the explicit table is the typed read path
 * beside it.
 */
final readonly class TypedTablePlanner
{
    /**
     * @return array{statements: list<string>, warnings: list<string>}
     *
     * @throws \RuntimeException when the attachment's file cannot be read
     */
    public function plan(SqliteAttachment $attachment): array
    {
        $reader = new SqliteSchemaReader($attachment->path);
        $statements = [];
        $warnings = [];

        foreach ($reader->tables() as $table) {
            $columns = [];
            $typedColumns = 0;

            foreach ($reader->columns($table) as $column) {
                $explicit = SqliteTypeMap::explicitFor($column['declared']);
                $type = $explicit ?? SqliteTypeMap::inferred($column['declared']);

                if (null !== $explicit) {
                    ++$typedColumns;
                    $warnings[] = sprintf(
                        '[CLICKHOUSE] typed %s.%s.%s as %s from TEXT — a value that does not parse is rejected when read (CANNOT_PARSE_DATETIME), not silently fixed',
                        $attachment->databaseName,
                        $table,
                        $column['name'],
                        $explicit,
                    );
                }

                $columns[] = self::identifier($column['name']) . ' ' . $type;
            }

            if (0 === $typedColumns || [] === $columns) {
                continue;
            }

            // A normal database, not the attached one: an explicit table
            // inside a SQLite-engine database collides with the implicit
            // table the engine already exposes (measured, see the class
            // docblock). The name carries the database so two attached files
            // with the same table name cannot collide.
            $explicitTable = $attachment->databaseName . '__' . $table;
            $statement = sprintf(
                'CREATE TABLE IF NOT EXISTS %s (%s) ENGINE = SQLite(%s, %s)',
                self::identifier($explicitTable),
                implode(', ', $columns),
                self::literal($attachment->path),
                self::literal($table),
            );

            error_log('[CLICKHOUSE] typed table ' . $explicitTable . ' (from ' . $attachment->databaseName . '.' . $table . ')');
            $statements[] = $statement;
        }

        if ([] !== $warnings) {
            error_log(sprintf(
                '[CLICKHOUSE] typed %d columns across %d tables',
                count($warnings),
                count($statements),
            ));
        }

        return ['statements' => $statements, 'warnings' => $warnings];
    }

    /**
     * Bare when ClickHouse would read the name without quoting — the shape of
     * every kernel table — and backticked when it would not. A backtick inside
     * the name is doubled, which is ClickHouse's own escape.
     */
    private static function identifier(string $name): string
    {
        if (1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            return $name;
        }

        return '`' . str_replace('`', '``', $name) . '`';
    }

    private static function literal(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}
