<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Domain;

/**
 * SqliteTypeMap — the documented type rules of ClickHouse's SQLite engine.
 *
 * One owner for the four questions a table plan asks, taken row by row from
 * <https://clickhouse.com/docs/reference/engines/database-engines/sqlite>:
 *
 * 1. `inferred()` — what the engine reads a *declared* SQLite type as when the
 *    table definition is inferred: a declaration containing `INT` becomes
 *    `Int64`, `REAL|FLOAT|DOUBLE` become `Float64`, everything else
 *    (including `TEXT` and `BLOB`) becomes `String`.
 * 2. `baseType()` — the declaration without its `(…)`, so `VARCHAR(255)` and
 *    `DECIMAL(10, 2)` can be compared as families.
 * 3. `parsableFromText()` — the doc's second table only: explicit table
 *    definitions parse `TEXT` into `Date`, `Date32`, `DateTime`,
 *    `DateTime64`, `UUID`, `Enum8|16`, `Decimal32…256`, `FixedString`, the
 *    integer widths and `Float32|64`.
 * 4. `sqliteDeclaredFor()` — the SQLite declaration a file carries for a
 *    ClickHouse type. The explicit-table planner reads a real file's
 *    declarations and matches them against this table, and the tests write
 *    their fixtures through it, so the planner and the test cannot disagree
 *    about what a `DATETIME` column is called.
 *
 * The `str_contains($type, 'INT')` rule is the doc's, literally: it also makes
 * `POINT` and `INTERVAL` integers. That is documented behaviour, not a
 * preference, and `SqliteTypeMapTest` asserts it with the reason beside it.
 */
final class SqliteTypeMap
{
    /** The inference order matters: `INT` wins over everything else the doc names. */
    public static function inferred(string $declared): string
    {
        $type = self::baseType($declared);

        if ('' === $type) {
            return 'String';
        }

        if (str_contains($type, 'INT')) {
            return 'Int64';
        }

        if (in_array($type, ['REAL', 'FLOAT', 'DOUBLE', 'DOUBLE PRECISION'], true)) {
            return 'Float64';
        }

        return 'String';
    }

    /**
     * The declared type without its argument list, uppercased.
     *
     * `VARCHAR(255)` → `VARCHAR`, `Decimal(10, 2)` → `DECIMAL`,
     * `  datetime ` → `DATETIME`.
     */
    public static function baseType(string $declared): string
    {
        $type = strtoupper(trim($declared));
        $parenthesis = strpos($type, '(');

        if (false !== $parenthesis) {
            $type = substr($type, 0, $parenthesis);
        }

        return trim($type);
    }

    /**
     * Whether an explicitly declared column of this ClickHouse type parses its
     * `TEXT` values, per the doc's second table.
     *
     * Case-insensitive, and the argument list is ignored: `Decimal128(4)` and
     * `FixedString(36)` are the same rows as `Decimal` and `FixedString`.
     */
    public static function parsableFromText(string $clickHouseType): bool
    {
        $type = self::baseType($clickHouseType);

        return in_array($type, [
            'DATE',
            'DATE32',
            'DATETIME',
            'DATETIME64',
            'UUID',
            'ENUM8',
            'ENUM16',
            'DECIMAL',
            'DECIMAL32',
            'DECIMAL64',
            'DECIMAL128',
            'DECIMAL256',
            'FIXEDSTRING',
            'INT8',
            'INT16',
            'INT32',
            'INT64',
            'INT128',
            'INT256',
            'UINT8',
            'UINT16',
            'UINT32',
            'UINT64',
            'UINT128',
            'UINT256',
            'FLOAT32',
            'FLOAT64',
        ], true);
    }

    /**
     * The ClickHouse type an explicit `CREATE TABLE` writes for a column whose
     * SQLite declaration names a row-2 type, or null for everything else.
     *
     * This is the inverse of `sqliteDeclaredFor()` for the types inference
     * cannot produce on its own — the planner applies it per column, and a
     * table earns an explicit definition only when at least one column has an
     * answer here. Quotes, spaces and commas inside the argument list are kept
     * as the file declared them: `DECIMAL(10, 2)` becomes `Decimal(10, 2)` and
     * `ENUM8('a' = 1)` becomes `Enum8('a' = 1)`.
     */
    public static function explicitFor(string $declared): ?string
    {
        $base = self::baseType($declared);
        $arguments = self::arguments($declared);

        $plain = [
            'DATE'      => 'Date',
            'DATE32'    => 'Date32',
            'DATETIME'  => 'DateTime',
            'UUID'      => 'UUID',
            'INT8'      => 'Int8',
            'INT16'     => 'Int16',
            'INT32'     => 'Int32',
            'INT64'     => 'Int64',
            'INT128'    => 'Int128',
            'INT256'    => 'Int256',
            'UINT8'     => 'UInt8',
            'UINT16'    => 'UInt16',
            'UINT32'    => 'UInt32',
            'UINT64'    => 'UInt64',
            'UINT128'   => 'UInt128',
            'UINT256'   => 'UInt256',
            'FLOAT32'   => 'Float32',
            'FLOAT64'   => 'Float64',
        ];

        if (isset($plain[$base])) {
            return $plain[$base];
        }

        $parameterised = [
            'DATETIME64'  => 'DateTime64',
            'DECIMAL'     => 'Decimal',
            'DECIMAL32'   => 'Decimal32',
            'DECIMAL64'   => 'Decimal64',
            'DECIMAL128'  => 'Decimal128',
            'DECIMAL256'  => 'Decimal256',
            'FIXEDSTRING' => 'FixedString',
            'ENUM8'       => 'Enum8',
            'ENUM16'      => 'Enum16',
        ];

        if (isset($parameterised[$base])) {
            return $parameterised[$base] . $arguments;
        }

        return null;
    }

    /**
     * The SQLite declaration a file carries for a ClickHouse type.
     *
     * Inference's three answers get their real SQLite spellings (`TEXT`,
     * `INTEGER`, `REAL`); every explicit type keeps its own name, uppercased,
     * because SQLite accepts an arbitrary declared type and an explicit table
     * definition needs to be able to find it again.
     */
    public static function sqliteDeclaredFor(string $clickHouseType): string
    {
        $type = trim($clickHouseType);

        if ('' === $type) {
            return 'TEXT';
        }

        $base = self::baseType($type);

        if ('STRING' === $base) {
            return 'TEXT';
        }

        if (str_starts_with($base, 'INT') || str_starts_with($base, 'UINT')) {
            return 'INTEGER';
        }

        if (in_array($base, ['FLOAT', 'FLOAT32', 'FLOAT64', 'REAL', 'DOUBLE'], true)) {
            return 'REAL';
        }

        $parenthesis = strpos($type, '(');

        return $base . (false === $parenthesis ? '' : substr($type, $parenthesis));
    }

    private static function arguments(string $type): string
    {
        $parenthesis = strpos($type, '(');

        return false === $parenthesis ? '' : substr($type, $parenthesis);
    }
}
