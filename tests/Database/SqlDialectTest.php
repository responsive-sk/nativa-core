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
 * test pins the answer for every driver. Nothing here opens a Postgres or
 * MariaDB server: these are the strings the driver will send, which is what a
 * mistake would change first.
 */
final class SqlDialectTest extends TestCase
{
    #[Test]
    public function quotingEscapesTheQuoteCharacter(): void
    {
        self::assertSame('"users"', SqlDialect::forDriver(Driver::Sqlite)->quoteIdentifier('users'));
        self::assertSame('"a""b"', SqlDialect::forDriver(Driver::Pgsql)->quoteIdentifier('a"b'));
        self::assertSame('`a``b`', SqlDialect::forDriver(Driver::MariaDb)->quoteIdentifier('a`b'));
    }

    #[Test]
    public function theFormsDifferWhereTheEnginesDo(): void
    {
        $sqlite = SqlDialect::forDriver(Driver::Sqlite);
        $pgsql = SqlDialect::forDriver(Driver::Pgsql);
        $mariadb = SqlDialect::forDriver(Driver::MariaDb);

        self::assertSame(
            'INSERT OR IGNORE INTO t VALUES (1)',
            $sqlite->insertOrIgnore('INSERT INTO t VALUES (1)'),
        );
        self::assertSame(
            'INSERT INTO t SELECT * FROM s ON CONFLICT DO NOTHING',
            $pgsql->insertOrIgnore('INSERT INTO t SELECT * FROM s'),
        );
        self::assertSame(
            'INSERT IGNORE INTO t SELECT * FROM s',
            $mariadb->insertOrIgnore('INSERT INTO t SELECT * FROM s'),
        );
        self::assertSame('id INTEGER PRIMARY KEY AUTOINCREMENT', $sqlite->autoIncrementColumn());
        self::assertSame(
            'id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY',
            $pgsql->autoIncrementColumn('id'),
        );
        self::assertSame('id BIGINT AUTO_INCREMENT PRIMARY KEY', $mariadb->autoIncrementColumn('id'));
        self::assertSame("datetime('now')", $sqlite->currentTimestamp());
        self::assertSame('now()', $pgsql->currentTimestamp());
        self::assertSame('CURRENT_TIMESTAMP', $mariadb->currentTimestamp());
        self::assertSame('email COLLATE NOCASE', $sqlite->noCase('email'));
        self::assertSame('LOWER(email)', $pgsql->noCase('email'));
        self::assertSame('LOWER(email)', $mariadb->noCase('email'));
        self::assertSame('email = :email COLLATE NOCASE', $sqlite->noCaseEquals('email', ':email'));
        self::assertSame('LOWER(email) = LOWER(:email)', $pgsql->noCaseEquals('email', ':email'));
        self::assertSame('LOWER(email) = LOWER(:email)', $mariadb->noCaseEquals('email', ':email'));
        self::assertSame('1', $sqlite->booleanLiteral(true));
        self::assertSame('0', $sqlite->booleanLiteral(false));
        self::assertSame('TRUE', $pgsql->booleanLiteral(true));
        self::assertSame('FALSE', $pgsql->booleanLiteral(false));
        self::assertSame('1', $mariadb->booleanLiteral(true));
        self::assertSame('0', $mariadb->booleanLiteral(false));
        self::assertStringContainsString('sqlite_master', $sqlite->tableExists());
        self::assertStringContainsString('information_schema.tables', $pgsql->tableExists());
        self::assertStringContainsString('information_schema.tables', $mariadb->tableExists());
        self::assertStringContainsString('DATABASE()', $mariadb->tableExists());
        self::assertSame(' RETURNING id', $sqlite->returning('id'));
        self::assertSame('', $mariadb->returning('id'));
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
        $mariadb = SqlDialect::forDriver(Driver::MariaDb);

        self::assertSame('BEGIN IMMEDIATE', $sqlite->beginWrite());
        self::assertSame('BEGIN', $pgsql->beginWrite());
        self::assertSame('START TRANSACTION', $mariadb->beginWrite());
        self::assertSame('BEGIN IMMEDIATE', $sqlite->beginSerializable());
        self::assertSame('BEGIN ISOLATION LEVEL SERIALIZABLE', $pgsql->beginSerializable());
        self::assertSame('START TRANSACTION', $mariadb->beginSerializable());
        self::assertSame('', $sqlite->skipLocked());
        self::assertSame(' FOR UPDATE SKIP LOCKED', $pgsql->skipLocked());
        self::assertSame(' FOR UPDATE SKIP LOCKED', $mariadb->skipLocked());
        self::assertSame('rowid', $sqlite->insertionOrder());
        self::assertSame('id', $pgsql->insertionOrder());
        self::assertSame('id', $mariadb->insertionOrder());
        self::assertSame("datetime('now', '-7 days')", $sqlite->shiftedTimestamp("'-7 days'"));
        self::assertSame("now() + ('-7 days')::interval", $pgsql->shiftedTimestamp("'-7 days'"));
        self::assertSame(
            "DATE_ADD(NOW(), INTERVAL CAST(SUBSTRING_INDEX('-7 days', ' ', 1) AS SIGNED) DAY)",
            $mariadb->shiftedTimestamp("'-7 days'"),
        );
        self::assertSame(
            "DATE_ADD(NOW(), INTERVAL CAST(SUBSTRING_INDEX(:interval, ' ', 1) AS SIGNED) DAY)",
            $mariadb->shiftedTimestamp(':interval'),
        );
        self::assertSame(
            "DATE_ADD(NOW(), INTERVAL CAST(SUBSTRING_INDEX(:ttl, ' ', 1) AS SIGNED) HOUR)",
            $mariadb->shiftedTimestamp(':ttl', 'HOUR'),
        );
        self::assertSame(
            "DATE_ADD(NOW(), INTERVAL CAST(SUBSTRING_INDEX(CONCAT('-', ?), ' ', 1) AS SIGNED) DAY)",
            $mariadb->shiftedTimestamp("'-' || ?"),
        );
    }

    #[Test]
    public function theDdlTypesFollowTheDriver(): void
    {
        $sqlite = SqlDialect::forDriver(Driver::Sqlite);
        $pgsql = SqlDialect::forDriver(Driver::Pgsql);
        $mariadb = SqlDialect::forDriver(Driver::MariaDb);

        self::assertSame('DATETIME', $sqlite->timestampType());
        self::assertSame('TIMESTAMPTZ', $pgsql->timestampType());
        self::assertSame('DATETIME', $mariadb->timestampType());
        self::assertSame('1', $sqlite->booleanDefault(true));
        self::assertSame('FALSE', $pgsql->booleanDefault(false));
        self::assertSame('1', $mariadb->booleanDefault(true));
        self::assertSame('0', $mariadb->booleanDefault(false));
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
        $mariadb = SqlDialect::forDriver(Driver::MariaDb)->immutableTable('audit_log', 'no writes');

        self::assertStringContainsString('RAISE(ABORT', $sqlite[0]);
        self::assertStringContainsString('EXECUTE FUNCTION', implode("\n", $pgsql));
        self::assertStringContainsString('SIGNAL SQLSTATE', implode("\n", $mariadb));
    }

    #[Test]
    public function theDriverNameIsReportedBack(): void
    {
        self::assertSame('pgsql', SqlDialect::forDriver(Driver::Pgsql)->driver());
        self::assertSame('mariadb', SqlDialect::forDriver(Driver::MariaDb)->driver());
        self::assertTrue(SqlDialect::forDriver(Driver::MariaDb)->isMariaDb());
    }

    #[Test]
    public function anUnknownDriverIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('oracle');

        Driver::fromName('oracle');
    }

    #[Test]
    public function theUpsertAliasFollowsTheEngine(): void
    {
        $sqlite = SqlDialect::forDriver(Driver::Sqlite);
        $pgsql = SqlDialect::forDriver(Driver::Pgsql);
        $mariadb = SqlDialect::forDriver(Driver::MariaDb);
        $insert = 'INSERT INTO t (id, name) VALUES (1, \'a\')';
        $updates = 'name = excluded.name';

        self::assertSame($insert . ' ON CONFLICT(id) DO UPDATE SET ' . $updates, $sqlite->upsert($insert, 'id', $updates));
        self::assertSame($insert . ' ON CONFLICT(id) DO UPDATE SET ' . $updates, $pgsql->upsert($insert, 'id', $updates));
        self::assertSame($insert . ' ON DUPLICATE KEY UPDATE name = VALUES(name)', $mariadb->upsert($insert, 'id', $updates));
    }

    #[Test]
    public function concatenationIsPortable(): void
    {
        self::assertSame("'a' || 'b'", SqlDialect::forDriver(Driver::Sqlite)->concat("'a'", "'b'"));
        self::assertSame("'a' || 'b'", SqlDialect::forDriver(Driver::Pgsql)->concat("'a'", "'b'"));
        self::assertSame("CONCAT('a', 'b')", SqlDialect::forDriver(Driver::MariaDb)->concat("'a'", "'b'"));
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
