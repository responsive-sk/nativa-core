<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Tests;

use Modules\Clickhouse\Domain\SqliteTypeMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SqliteTypeMapTest — one data row per row of both ClickHouse doc tables.
 *
 * The type map is the reason the bridge can be trusted at all: inference is
 * what a plain `CREATE DATABASE … ENGINE = SQLite(...)` gives an operator, and
 * the explicit table path is an opt-in that must parse exactly the types the
 * doc says it parses. A test that only checked the happy rows would let the
 * two traps through:
 *
 * - `POINT` and `INTERVAL` contain `INT`, so the doc's literal rule reads them
 *   as `Int64`. That is documented behaviour (asserted here with its reason),
 *   not a bug to fix silently.
 * - `TEXT` read as an integer returns `0` for a value that does not parse, so
 *   the planner must never infer a numeric type for a `TEXT` column.
 *
 * A module test: it skips on a kernel-only checkout, guarding setUp() and
 * tearDown() because PHPUnit runs tearDown() even after a skipped setUp().
 */
final class SqliteTypeMapTest extends TestCase
{
    private const MODULE = 'Clickhouse';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the type map lives in that module.');
        }
    }

    protected function tearDown(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            return;
        }
    }

    /**
     * The doc's "Data types support" rows 1 and 2, plus the declarations a
     * kernel table actually uses.
     *
     * @return array<string, array{string, string}>
     */
    public static function inferredTypes(): array
    {
        return [
            'empty declaration'          => ['', 'String'],
            'TEXT'                       => ['TEXT', 'String'],
            'BLOB'                       => ['BLOB', 'String'],
            'DATETIME'                   => ['DATETIME', 'String'],
            'BOOLEAN'                    => ['BOOLEAN', 'String'],
            'VARCHAR(200)'               => ['VARCHAR(200)', 'String'],
            'INTEGER'                    => ['INTEGER', 'Int64'],
            'BIGINT'                     => ['BIGINT', 'Int64'],
            'lowercase int'              => ['integer', 'Int64'],
            'POINT contains INT'         => ['POINT', 'Int64'],
            'INTERVAL contains INT'      => ['INTERVAL', 'Int64'],
            'REAL'                       => ['REAL', 'Float64'],
            'FLOAT'                      => ['FLOAT', 'Float64'],
            'DOUBLE'                     => ['DOUBLE', 'Float64'],
            'DOUBLE PRECISION'           => ['DOUBLE PRECISION', 'Float64'],
            'NUMERIC is not a doc type'  => ['NUMERIC', 'String'],
        ];
    }

    #[Test]
    #[DataProvider('inferredTypes')]
    public function inferenceFollowsTheDocLiterally(string $declared, string $expected): void
    {
        self::assertSame($expected, SqliteTypeMap::inferred($declared));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function baseTypes(): array
    {
        return [
            'with a length'        => ['VARCHAR(255)', 'VARCHAR'],
            'with two arguments'   => ['DECIMAL(10, 2)', 'DECIMAL'],
            'already bare'         => ['datetime', 'DATETIME'],
            'surrounding spaces'   => ['  integer  ', 'INTEGER'],
            'empty'                => ['', ''],
        ];
    }

    #[Test]
    #[DataProvider('baseTypes')]
    public function theBaseTypeDropsTheArgumentList(string $declared, string $expected): void
    {
        self::assertSame($expected, SqliteTypeMap::baseType($declared));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function parsableTypes(): array
    {
        return [
            'Date'            => ['Date'],
            'Date32'          => ['Date32'],
            'DateTime'        => ['DateTime'],
            'DATETIME declaration' => ['DATETIME'],
            'DateTime64(3)'   => ['DateTime64(3)'],
            'UUID'            => ['UUID'],
            'Enum8 with args' => ["Enum8('a' = 1, 'b' = 2)"],
            'Enum16 with args' => ["Enum16('a' = 1)"],
            'Decimal32(4)'    => ['Decimal32(4)'],
            'Decimal64(4)'    => ['Decimal64(4)'],
            'Decimal128(4)'   => ['Decimal128(4)'],
            'Decimal256(4)'   => ['Decimal256(4)'],
            'Decimal(10, 2)'  => ['Decimal(10, 2)'],
            'FixedString(36)' => ['FixedString(36)'],
            'Int8'            => ['Int8'],
            'Int256'          => ['Int256'],
            'UInt16'          => ['UInt16'],
            'UInt256'         => ['UInt256'],
            'Float32'         => ['Float32'],
            'Float64'         => ['Float64'],
        ];
    }

    #[Test]
    #[DataProvider('parsableTypes')]
    public function theDocRowTwoTypesParseFromText(string $clickHouseType): void
    {
        self::assertTrue(SqliteTypeMap::parsableFromText($clickHouseType));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notParsableTypes(): array
    {
        return [
            'String'         => ['String'],
            'TEXT'           => ['TEXT'],
            'BLOB'           => ['BLOB'],
            'INTEGER'        => ['INTEGER'],
            'DOUBLE'         => ['DOUBLE'],
            'POINT'          => ['POINT'],
            'VARCHAR(255)'   => ['VARCHAR(255)'],
            'empty'          => [''],
        ];
    }

    #[Test]
    #[DataProvider('notParsableTypes')]
    public function everythingElseIsNotParsedFromText(string $clickHouseType): void
    {
        self::assertFalse(SqliteTypeMap::parsableFromText($clickHouseType));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function declaredTypes(): array
    {
        return [
            'String'            => ['String', 'TEXT'],
            'Int64'             => ['Int64', 'INTEGER'],
            'UInt8'             => ['UInt8', 'INTEGER'],
            'Float64'           => ['Float64', 'REAL'],
            'Float32'           => ['Float32', 'REAL'],
            'Date'              => ['Date', 'DATE'],
            'DateTime'          => ['DateTime', 'DATETIME'],
            'DateTime64(3)'     => ['DateTime64(3)', 'DATETIME64(3)'],
            'UUID'              => ['UUID', 'UUID'],
            'Decimal(10, 2)'    => ['Decimal(10, 2)', 'DECIMAL(10, 2)'],
            'FixedString(36)'   => ['FixedString(36)', 'FIXEDSTRING(36)'],
            'Enum8 with args'   => ["Enum8('a' = 1)", "ENUM8('a' = 1)"],
            'empty'             => ['', 'TEXT'],
        ];
    }

    #[Test]
    #[DataProvider('declaredTypes')]
    public function theDeclaredSqliteTypeIsTheOneAFixtureWrites(string $clickHouseType, string $expected): void
    {
        self::assertSame($expected, SqliteTypeMap::sqliteDeclaredFor($clickHouseType));
    }

    /**
     * The planner's premise, stated as a test: the declaration this map hands
     * a fixture is exactly the declaration the planner has to read back.
     */
    #[Test]
    public function anExplicitTypeIsReadableBackFromTheDeclaration(): void
    {
        foreach (['Date', 'Date32', 'DateTime', 'DateTime64(3)', 'UUID', 'Decimal(10, 2)', 'FixedString(36)'] as $type) {
            self::assertTrue(
                SqliteTypeMap::parsableFromText(SqliteTypeMap::sqliteDeclaredFor($type)),
                $type . ' must be recognisable in the declaration its own fixture writes.',
            );
        }
    }
}
