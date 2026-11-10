<?php

declare(strict_types = 1);

namespace Tests\Database;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Contracts\StatementInterface;
use Modules\Database\Exceptions\ReadException;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use Modules\Database\Infrastructure\Persistence\ReadWriteConnection;
use Modules\Database\Infrastructure\Persistence\ReplicaSelector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ReadWriteConnectionTest — which side answers a read, and when.
 *
 * Two in-memory databases stand in for a primary and a replica: each holds its
 * own row, so the answer names the connection that served it. No file copying
 * and no replication: the question is routing, not SQLite.
 */
final class ReadWriteConnectionTest extends TestCase
{
    #[Test]
    public function aReadGoesToTheReplica(): void
    {
        $connection = $this->connection(['primary'], ['replica']);

        self::assertSame('replica', $this->value($connection));
    }

    #[Test]
    public function aWriteMarksTheConnectionAndLaterReadsStayOnThePrimary(): void
    {
        $connection = $this->connection(['primary'], ['replica']);

        $connection->execute("INSERT INTO t (v) VALUES ('second')");

        self::assertSame(['primary', 'second'], $this->values($connection));
    }

    #[Test]
    public function resettingTheStickyWriteRestoresReplicaReads(): void
    {
        $connection = $this->connection(['primary'], ['replica']);
        $connection->execute("INSERT INTO t (v) VALUES ('second')");

        $connection->resetSticky();

        self::assertSame('replica', $this->value($connection));
    }

    #[Test]
    public function onPrimaryForcesOneReadAndRestoresTheRoutingAfterwards(): void
    {
        $connection = $this->connection(['primary'], ['replica']);

        $inside = $connection->onPrimary(fn (): mixed => $this->value($connection));

        self::assertSame('primary', $inside);
        self::assertSame('replica', $this->value($connection));
    }

    #[Test]
    public function onPrimaryNests(): void
    {
        $connection = $this->connection(['primary'], ['replica']);

        $values = $connection->onPrimary(fn (): array => [
            $this->value($connection),
            $connection->onPrimary(fn (): mixed => $this->value($connection)),
            $this->value($connection),
        ]);

        self::assertSame(['primary', 'primary', 'primary'], $values);
        self::assertSame('replica', $this->value($connection));
    }

    #[Test]
    public function aPreparedSelectIsReadAndAPreparedInsertIsAWrite(): void
    {
        $connection = $this->connection(['primary'], ['replica']);

        $select = $connection->prepare('SELECT v FROM t ORDER BY v LIMIT 1');
        $select->execute();

        self::assertSame('replica', $select->fetchColumn());

        $connection->prepare("INSERT INTO t (v) VALUES ('second')")->execute();

        self::assertSame(['primary', 'second'], $this->values($connection));
    }

    #[Test]
    public function aReadFallsOverToTheNextReplica(): void
    {
        $connection = new ReadWriteConnection(
            new PdoConnection($this->pdo(['primary']), 'primary'),
            [$this->dead('dead-1'), new PdoConnection($this->pdo(['replica']), 'replica')],
            new ReplicaSelector(),
        );

        self::assertSame('replica', $this->value($connection));
    }

    #[Test]
    public function everyReplicaFailingRaisesAndNamesThem(): void
    {
        $connection = new ReadWriteConnection(
            new PdoConnection($this->pdo(['primary']), 'primary'),
            [$this->dead('dead-1'), $this->dead('dead-2')],
            new ReplicaSelector(),
        );

        $this->expectException(ReadException::class);
        $this->expectExceptionMessage('primary');

        $this->value($connection);
    }

    #[Test]
    public function beginningATransactionMarksThePrimary(): void
    {
        $connection = $this->connection(['primary'], ['replica']);

        $connection->beginTransaction();
        $connection->commit();

        self::assertSame('primary', $this->value($connection));
        self::assertFalse($connection->inTransaction());
    }

    /**
     * @param list<string> $primary
     * @param list<string> $replica
     */
    private function connection(array $primary, array $replica): ReadWriteConnection
    {
        return new ReadWriteConnection(
            new PdoConnection($this->pdo($primary), 'primary'),
            [new PdoConnection($this->pdo($replica), 'replica')],
            new ReplicaSelector(),
        );
    }

    /**
     * @param list<string> $values
     */
    private function pdo(array $values): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE t (v TEXT)');

        foreach ($values as $value) {
            $statement = $pdo->prepare('INSERT INTO t (v) VALUES (?)');
            $statement->execute([$value]);
        }

        return $pdo;
    }

    private function value(ConnectionInterface $connection): mixed
    {
        $statement = $connection->query('SELECT v FROM t ORDER BY v LIMIT 1');

        return $statement->fetchColumn();
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

    /**
     * A replica that opens but fails every query, which is what a replica
     * behind a broken network path looks like to PDO.
     */
    private function dead(string $name): ConnectionInterface
    {
        return new class ($name) implements ConnectionInterface {
            public function __construct(private readonly string $connectionName)
            {
            }

            public function name(): string
            {
                return $this->connectionName;
            }

            public function query(string $sql): StatementInterface
            {
                throw new \PDOException('SQLSTATE[HY000]: unable to open database file');
            }

            public function prepare(string $sql): StatementInterface
            {
                throw new \PDOException('SQLSTATE[HY000]: unable to open database file');
            }

            public function execute(string $sql, array $bindings = []): int
            {
                throw new \PDOException('SQLSTATE[HY000]: unable to open database file');
            }

            public function lastInsertId(): string
            {
                return '0';
            }

            public function inTransaction(): bool
            {
                return false;
            }

            public function beginTransaction(): void
            {
            }

            public function commit(): void
            {
            }

            public function rollBack(): void
            {
            }
        };
    }
}
