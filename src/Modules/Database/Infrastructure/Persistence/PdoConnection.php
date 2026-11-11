<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Contracts\StatementInterface;

/**
 * PdoConnection — one PDO handle behind the connection contract.
 *
 * This is the single-handle case: every read and every write goes to the handle
 * the manager opened, so there is nothing to route. The hardening (exception
 * mode, fetch mode, busy timeout, foreign keys, WAL) stays in
 * DatabaseConnectionManager::openPdo(), which is where the handle is created;
 * this class does not reconfigure a connection somebody else owns.
 *
 * The contract still pays for itself here: a repository typed against it can
 * be handed a read/write connection later without its own code changing.
 *
 * @internal
 */
final readonly class PdoConnection implements ConnectionInterface
{
    public function __construct(
        private \PDO $pdo,
        private string $name = 'default',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function driverName(): string
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return \is_string($driver) ? strtolower($driver) : '';
    }

    public function query(string $sql): StatementInterface
    {
        return new PdoStatement($this->statement($this->pdo->query($sql), $sql));
    }

    public function prepare(string $sql): StatementInterface
    {
        return new PdoStatement($this->statement($this->pdo->prepare($sql), $sql));
    }

    public function execute(string $sql, array $bindings = []): int
    {
        if ($bindings === []) {
            $affected = $this->pdo->exec($sql);

            if (false === $affected) {
                throw new \RuntimeException('Failed to execute statement on ' . $this->name . ': ' . $sql);
            }

            return $affected;
        }

        $statement = $this->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    public function lastInsertId(): string
    {
        $id = $this->pdo->lastInsertId();

        if (false === $id) {
            throw new \RuntimeException('Failed to read the last insert id on ' . $this->name);
        }

        return $id;
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function transaction(
        callable $callback,
        int $attempts = 1,
        int|\Closure|null $backoff = null,
    ): mixed {
        return TransactionRetry::run($this, static fn (ConnectionInterface $db): mixed => $callback($db), $attempts, $backoff);
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    /**
     * @param \PDOStatement|false $statement
     */
    private function statement(\PDOStatement|false $statement, string $sql): \PDOStatement
    {
        if (false === $statement) {
            throw new \RuntimeException('Failed to prepare statement on ' . $this->name . ': ' . $sql);
        }

        return $statement;
    }
}
