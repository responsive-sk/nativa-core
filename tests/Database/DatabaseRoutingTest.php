<?php

declare(strict_types = 1);

namespace Tests\Database;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use Modules\Database\Infrastructure\Persistence\ReadWriteConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DatabaseRoutingTest — the manager's side of the contract, over real files.
 *
 * The behaviour is proved in ReadWriteConnectionTest; what this file proves is
 * the configuration seam: `DB_<NAME>` is the primary, `DB_<NAME>_READ` is the
 * replica list, unset means no routing, and `resetRouting()` is what keeps a
 * long-lived worker from carrying yesterday's sticky write.
 */
final class DatabaseRoutingTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/nativa-routing-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);
        DatabaseConnectionManager::resetInstance();
    }

    protected function tearDown(): void
    {
        putenv('DB_PROBE');
        putenv('DB_PROBE_READ');
        DatabaseConnectionManager::resetInstance();

        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tmpDir);
    }

    #[Test]
    public function withoutAReplicaTheContractWrapsTheSameHandle(): void
    {
        $this->seed($this->tmpDir . '/probe.db', ['primary']);
        putenv('DB_PROBE=' . $this->tmpDir . '/probe.db');

        $connection = DatabaseConnectionManager::instance()->connect('probe');

        self::assertSame(PdoConnection::class, $connection::class);
        self::assertSame('primary', $this->value($connection));
    }

    #[Test]
    public function aConfiguredReplicaServesReadsUntilTheFirstWrite(): void
    {
        $this->seed($this->tmpDir . '/probe.db', ['primary']);
        $this->seed($this->tmpDir . '/probe-read.db', ['replica']);
        putenv('DB_PROBE=' . $this->tmpDir . '/probe.db');
        putenv('DB_PROBE_READ=' . $this->tmpDir . '/probe-read.db');

        $connection = DatabaseConnectionManager::instance()->connect('probe');

        self::assertInstanceOf(ReadWriteConnection::class, $connection);
        self::assertSame('replica', $this->value($connection));

        $connection->execute("INSERT INTO t (v) VALUES ('second')");

        self::assertSame(['primary', 'second'], $this->values($connection));

        DatabaseConnectionManager::instance()->resetRouting();

        self::assertSame('replica', $this->value($connection));
    }

    /**
     * @param list<string> $values
     */
    private function seed(string $file, array $values): void
    {
        $pdo = new \PDO('sqlite:' . $file);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (v TEXT)');

        foreach ($values as $value) {
            $pdo->prepare('INSERT INTO t (v) VALUES (?)')->execute([$value]);
        }
    }

    private function value(ConnectionInterface $connection): mixed
    {
        return $connection->query('SELECT v FROM t ORDER BY v LIMIT 1')->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function values(ConnectionInterface $connection): array
    {
        /** @var list<string> $values */
        $values = $connection->query('SELECT v FROM t ORDER BY v')->fetchAll(\PDO::FETCH_COLUMN);

        return $values;
    }
}
