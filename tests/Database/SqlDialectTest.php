<?php

declare(strict_types = 1);

namespace Tests\Database;

use Modules\Database\Infrastructure\Persistence\Driver;
use Modules\Database\Infrastructure\Persistence\SqlDialect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SqlDialectTest — the seven forms, asserted as text.
 *
 * The point of the dialect is that a call site asks instead of guessing, so the
 * test pins the answer for both drivers. Nothing here opens a Postgres server:
 * these are the strings the driver will send, which is what a mistake would
 * change first.
 */
final class SqlDialectTest extends TestCase
{
    #[Test]
    public function quotingEscapesTheQuoteCharacter(): void
    {
        self::assertSame('"users"', SqlDialect::forDriver(Driver::Sqlite)->quoteIdentifier('users'));
        self::assertSame('"a""b"', SqlDialect::forDriver(Driver::Pgsql)->quoteIdentifier('a"b'));
    }

    #[Test]
    public function theFormsDifferWhereTheEnginesDo(): void
    {
        $sqlite = SqlDialect::forDriver(Driver::Sqlite);
        $pgsql = SqlDialect::forDriver(Driver::Pgsql);

        self::assertSame(
            'INSERT INTO t VALUES (1) ON CONFLICT DO NOTHING',
            $sqlite->insertOrIgnore('INSERT INTO t VALUES (1)'),
        );
        self::assertSame('id INTEGER PRIMARY KEY AUTOINCREMENT', $sqlite->autoIncrementColumn());
        self::assertSame(
            'id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY',
            $pgsql->autoIncrementColumn('id'),
        );
        self::assertSame("datetime('now')", $sqlite->currentTimestamp());
        self::assertSame('now()', $pgsql->currentTimestamp());
        self::assertSame('email COLLATE NOCASE', $sqlite->noCase('email'));
        self::assertSame('LOWER(email)', $pgsql->noCase('email'));
        self::assertStringContainsString('sqlite_master', $sqlite->tableExists());
        self::assertStringContainsString('information_schema.tables', $pgsql->tableExists());
        self::assertSame(' RETURNING id', $sqlite->returning('id'));
    }

    #[Test]
    public function theDriverNameIsReportedBack(): void
    {
        self::assertSame('pgsql', SqlDialect::forDriver(Driver::Pgsql)->driver());
    }

    #[Test]
    public function anUnknownDriverIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mysql');

        Driver::fromName('mysql');
    }

    #[Test]
    public function returningIsDecidedFromTheActualEngine(): void
    {
        $pdo = new \PDO('sqlite::memory:');

        self::assertTrue(
            SqlDialect::forDriver(Driver::Sqlite)->supportsReturning($pdo),
            'the test engine is newer than 3.35',
        );
    }
}
