<?php

declare(strict_types = 1);

namespace Tests\Database;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Database\Infrastructure\Persistence\ReadWriteConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MysqlConnectionTest — the driver seam against a real server.
 *
 * Skipped unless `NATIVA_MYSQL_DSN` names a database this process can open, so
 * the suite still runs on a machine that only has SQLite. The replica case
 * needs a second, independent database in `NATIVA_MYSQL_REPLICA_DSN`; two
 * databases without replication are exactly what a stale read looks like.
 *
 * Credentials, when the server needs them, come from `DB_<NAME>_USER` and
 * `DB_<NAME>_PASSWORD`, the same env seam the manager uses.
 */
final class MysqlConnectionTest extends TestCase
{
    private string $primary;

    private string $table;

    protected function setUp(): void
    {
        $primary = getenv('NATIVA_MYSQL_DSN');

        if (false === $primary || '' === $primary) {
            self::markTestSkipped('Set NATIVA_MYSQL_DSN to run the MariaDB/MySQL driver tests.');
        }

        if (!\in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_mysql is not loaded; run PHP with -d extension=pdo_mysql.');
        }

        $this->primary = $primary;
        $this->table = 'driver_probe_' . bin2hex(random_bytes(4));
        DatabaseConnectionManager::resetInstance();
    }

    protected function tearDown(): void
    {
        foreach ([
            'DB_NATIVAPROBE_DSN',
            'DB_NATIVAPROBE_READ_DSN',
            'DB_NATIVAROUTE_DSN',
            'DB_NATIVAROUTE_READ_DSN',
            'DB_NATIVAPRIMARY_DSN',
            'DB_NATIVAREPLICA_DSN',
        ] as $key) {
            putenv($key);
        }

        DatabaseConnectionManager::resetInstance();
    }

    #[Test]
    public function theManagerOpensAModuleDatabaseFromItsDsn(): void
    {
        putenv('DB_NATIVAPROBE_DSN=' . $this->primary);

        $db = DatabaseConnectionManager::instance()->connect('nativaprobe');

        self::assertSame('mysql', $db->driverName());
        self::assertSame('nativaprobe', $db->name());

        $table = $this->table;

        try {
            $db->execute("CREATE TABLE {$table} (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(32) NOT NULL)");
            $db->execute("INSERT INTO {$table} (v) VALUES ('first')");

            self::assertGreaterThan(0, (int) $db->lastInsertId());
            self::assertSame('first', $db->query("SELECT v FROM {$table}")->fetchColumn());

            try {
                $db->transaction(static function ($inner) use ($table): void {
                    $inner->execute("INSERT INTO {$table} (v) VALUES ('rolled-back')");

                    throw new \RuntimeException('stop the transaction');
                });
            } catch (\RuntimeException $e) {
                self::assertSame('stop the transaction', $e->getMessage());
            }

            self::assertEquals(1, $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn());
        } finally {
            $db->execute("DROP TABLE IF EXISTS {$table}");
        }
    }

    #[Test]
    public function aReplicaDsnServesReadsUntilTheFirstWrite(): void
    {
        $replica = getenv('NATIVA_MYSQL_REPLICA_DSN');

        if (false === $replica || '' === $replica) {
            self::markTestSkipped('Set NATIVA_MYSQL_REPLICA_DSN (a second database) to run the routing test.');
        }

        $manager = DatabaseConnectionManager::instance();
        $table = $this->table;

        putenv('DB_NATIVAPRIMARY_DSN=' . $this->primary);
        putenv('DB_NATIVAREPLICA_DSN=' . $replica);
        putenv('DB_NATIVAROUTE_DSN=' . $this->primary);
        putenv('DB_NATIVAROUTE_READ_DSN=' . $replica);

        $primary = $manager->connect('nativaprimary');
        $replicaDb = $manager->connect('nativareplica');

        $primary->execute("CREATE TABLE {$table} (v VARCHAR(32) NOT NULL)");
        $primary->execute("INSERT INTO {$table} (v) VALUES ('primary')");
        $replicaDb->execute("CREATE TABLE {$table} (v VARCHAR(32) NOT NULL)");
        $replicaDb->execute("INSERT INTO {$table} (v) VALUES ('replica')");

        try {
            $routed = $manager->connect('nativaroute');

            self::assertInstanceOf(ReadWriteConnection::class, $routed);
            self::assertSame('mysql', $routed->driverName());
            self::assertSame('replica', $routed->query("SELECT v FROM {$table}")->fetchColumn());

            $routed->execute("INSERT INTO {$table} (v) VALUES ('second')");

            self::assertSame(
                ['primary', 'second'],
                $routed->query("SELECT v FROM {$table} ORDER BY v")->fetchAll(\PDO::FETCH_COLUMN),
            );

            $manager->resetRouting();

            self::assertSame('replica', $routed->query("SELECT v FROM {$table}")->fetchColumn());
        } finally {
            $primary->execute("DROP TABLE IF EXISTS {$table}");
            $replicaDb->execute("DROP TABLE IF EXISTS {$table}");
        }
    }
}
