<?php

declare(strict_types = 1);

namespace Tests\Database;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Database\Infrastructure\Persistence\Driver;
use Modules\Database\Infrastructure\Persistence\DsnBuilder;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DriverSeamTest — the configuration seam, not a running PostgreSQL.
 *
 * Step 1 of the driver plan is the DSN and the session: `DB_DRIVER` chooses
 * the engine, and `pgsql` needs the shared `DB_DSN` (or a test/legacy
 * `DB_<NAME>` override). No server is reachable here, so this file pins what
 * can be pinned without one: the DSN
 * text, the refused driver name, the refusal that names the missing variable,
 * and the contract's own answers on a real SQLite handle.
 */
final class DriverSeamTest extends TestCase
{
    private string|false $previousDriver = false;

    private string|false $previousDsn = false;

    protected function setUp(): void
    {
        // The class proves the default by removing the variable, but the
        // ambient engine belongs to the run: putting the old value back is
        // what keeps a PostgreSQL run from falling into SQLite halfway
        // through, with DB_DSN still holding a DSN. The DSN is removed too, so
        // a PostgreSQL job cannot satisfy the missing-target refusal by
        // accident.
        $this->previousDriver = getenv('DB_DRIVER');
        $this->previousDsn = getenv('DB_DSN');
        putenv('DB_DRIVER');
        putenv('DB_DSN');
        putenv('DB_PROBE');
        DatabaseConnectionManager::resetInstance();
    }

    protected function tearDown(): void
    {
        if (false === $this->previousDriver) {
            putenv('DB_DRIVER');
        } else {
            putenv('DB_DRIVER=' . $this->previousDriver);
        }

        if (false === $this->previousDsn) {
            putenv('DB_DSN');
        } else {
            putenv('DB_DSN=' . $this->previousDsn);
        }

        putenv('DB_PROBE');
        DatabaseConnectionManager::resetInstance();
    }

    #[Test]
    public function sqliteIsTheDefaultAndItsTargetIsAFile(): void
    {
        self::assertSame(Driver::Sqlite->value, DatabaseConnectionManager::instance()->driverName());
        self::assertSame('sqlite:/tmp/probe.db', DsnBuilder::build(Driver::Sqlite, '/tmp/probe.db'));
    }

    #[Test]
    public function aPostgresTargetBecomesADsnBody(): void
    {
        self::assertSame(
            'pgsql:host=db;port=5432;dbname=probe',
            DsnBuilder::build(Driver::Pgsql, 'host=db;port=5432;dbname=probe'),
        );
    }

    #[Test]
    public function anUnknownDriverIsRefusedBeforeAnyConnection(): void
    {
        putenv('DB_DRIVER=mysql');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sqlite, pgsql');

        DatabaseConnectionManager::instance()->driverName();
    }

    #[Test]
    public function aPostgresModuleWithoutATargetNamesTheMissingVariable(): void
    {
        putenv('DB_DRIVER=pgsql');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB_PROBE');

        DatabaseConnectionManager::instance()->connection('probe');
    }

    #[Test]
    public function theContractAnswersFromTheHandleItOwns(): void
    {
        $connection = new PdoConnection(new \PDO('sqlite::memory:'), 'probe');

        self::assertSame('sqlite', $connection->driverName());
        self::assertSame('"users"', $connection->quoteIdentifier('users'));
        self::assertTrue($connection->supportsReturning());
    }
}
