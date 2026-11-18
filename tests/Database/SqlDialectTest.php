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
            'INSERT OR IGNORE INTO t VALUES (1)',
            $sqlite->insertOrIgnore('INSERT INTO t VALUES (1)'),
        );
        self::assertSame(
            'INSERT INTO t SELECT * FROM s ON CONFLICT DO NOTHING',
            $pgsql->insertOrIgnore('INSERT INTO t SELECT * FROM s'),
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
    public function theIgnoreClauseSurvivesAnInsertSelect(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE source (path TEXT PRIMARY KEY)');
        $pdo->exec('CREATE TABLE target (path TEXT PRIMARY KEY)');
        $pdo->exec("INSERT INTO source VALUES ('a'), ('b')");

        $sql = SqlDialect::forPdo($pdo)->insertOrIgnore(<<<'SQL'
                INSERT INTO target (path)
                SELECT path FROM source
            SQL);

        $pdo->exec($sql);
        $pdo->exec($sql);

        $count = $pdo->query('SELECT COUNT(*) FROM target');
        self::assertNotFalse($count);
        self::assertSame('2', (string) $count->fetchColumn());
    }

    #[Test]
    public function theClaimIsSpelledForTheEngine(): void
    {
        $sqlite = SqlDialect::forDriver(Driver::Sqlite);
        $pgsql = SqlDialect::forDriver(Driver::Pgsql);

        self::assertSame('BEGIN IMMEDIATE', $sqlite->beginWrite());
        self::assertSame('BEGIN', $pgsql->beginWrite());
        self::assertSame('BEGIN IMMEDIATE', $sqlite->beginSerializable());
        self::assertSame('BEGIN ISOLATION LEVEL SERIALIZABLE', $pgsql->beginSerializable());
        self::assertSame('', $sqlite->skipLocked());
        self::assertSame(' FOR UPDATE SKIP LOCKED', $pgsql->skipLocked());
    }

    #[Test]
    public function theDdlTypesFollowTheDriver(): void
    {
        $sqlite = SqlDialect::forDriver(Driver::Sqlite);
        $pgsql = SqlDialect::forDriver(Driver::Pgsql);

        self::assertSame('DATETIME', $sqlite->timestampType());
        self::assertSame('TIMESTAMPTZ', $pgsql->timestampType());
        self::assertSame('1', $sqlite->booleanDefault(true));
        self::assertSame('FALSE', $pgsql->booleanDefault(false));
    }

    #[Test]
    public function theDialectIsReadFromTheHandle(): void
    {
        self::assertTrue(SqlDialect::forPdo(new \PDO('sqlite::memory:'))->isSqlite());
    }

    #[Test]
    public function columnsComeFromTheLiveSchema(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE probe (id INTEGER PRIMARY KEY, name TEXT)');

        self::assertSame(['id', 'name'], SqlDialect::forPdo($pdo)->columnNames($pdo, 'probe'));
        self::assertSame([], SqlDialect::forPdo($pdo)->columnNames($pdo, 'absent'));
    }

    #[Test]
    public function theAppendOnlyStatementsAreDriverSyntax(): void
    {
        $sqlite = SqlDialect::forDriver(Driver::Sqlite)->immutableTable('audit_log', 'no writes');
        $pgsql = SqlDialect::forDriver(Driver::Pgsql)->immutableTable('audit_log', 'no writes');

        self::assertStringContainsString('RAISE(ABORT', $sqlite[0]);
        self::assertStringContainsString('EXECUTE FUNCTION', implode("\n", $pgsql));
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
