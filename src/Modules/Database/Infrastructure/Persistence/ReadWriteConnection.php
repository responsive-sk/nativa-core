<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Contracts\PrimaryReadInterface;
use Modules\Database\Contracts\StatementInterface;
use Modules\Database\Exceptions\ReadException;

/**
 * ReadWriteConnection — reads from a replica, writes on the primary.
 *
 * Three rules, and the first one is the only reason this class exists:
 *
 *  1. **Sticky write.** Once this request has written, every later read in the
 *     same request goes to the primary. Without it a replica that lags by even
 *     a millisecond breaks read-your-writes, and the failure looks like "the
 *     row I just saved is not there" rather than like replication lag.
 *  2. **Explicit intent.** `query()` reads, `execute()` writes. `prepare()`
 *     asks `StatementIntent` for its side, because the target is chosen before
 *     the statement runs. A prepared read does not fail over: the throw happens
 *     at `execute()`, when the replica is already chosen.
 *  3. **Transactions on the primary.** Beginning a transaction marks the
 *     connection as written. `UnitOfWork` now hands its callback this same
 *     object, so writes inside it would mark it anyway; doing it at `begin`
 *     keeps the read side on the primary from the first statement, which is
 *     what a transaction means.
 *
 * `onPrimary()` is the escape for a read that must not be stale, and it nests
 * like a stack: an inner scope cannot lift an outer scope's primary routing.
 */
final class ReadWriteConnection implements ConnectionInterface, PrimaryReadInterface
{
    private bool $wrote = false;

    private int $primaryReads = 0;

    /**
     * @param list<ConnectionInterface> $replicas
     */
    public function __construct(
        private readonly ConnectionInterface $write,
        private readonly array $replicas,
        private readonly ReplicaSelector $selector,
    ) {
    }

    public function name(): string
    {
        return $this->write->name();
    }

    public function query(string $sql): StatementInterface
    {
        if ($this->goesToPrimary()) {
            return $this->write->query($sql);
        }

        $failures = [];

        foreach ($this->readOrder() as $replica) {
            try {
                return $replica->query($sql);
            } catch (\PDOException $failure) {
                $failures[] = $replica->name() . ': ' . $failure->getMessage();
            }
        }

        throw ReadException::allReplicasFailed($this->name(), $failures);
    }

    public function prepare(string $sql): StatementInterface
    {
        if (StatementIntent::writes($sql)) {
            $this->markWritten();

            return $this->write->prepare($sql);
        }

        return $this->readSide()->prepare($sql);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $this->markWritten();

        return $this->write->execute($sql, $bindings);
    }

    public function lastInsertId(): string
    {
        return $this->write->lastInsertId();
    }

    public function inTransaction(): bool
    {
        return $this->write->inTransaction();
    }

    public function beginTransaction(): void
    {
        // A transaction is a write intent: its first read must see the rows its
        // writes will touch, not a replica that may not have them yet.
        $this->markWritten();
        $this->write->beginTransaction();
    }

    public function commit(): void
    {
        $this->write->commit();
    }

    public function rollBack(): void
    {
        $this->write->rollBack();
    }

    public function onPrimary(callable $callback): mixed
    {
        $this->primaryReads++;

        try {
            return $callback();
        } finally {
            $this->primaryReads--;
        }
    }

    /**
     * Forget the sticky write and any open `onPrimary()` scope.
     *
     * Called at the request boundary. A PHP-FPM worker keeps this object for
     * its whole life, so without the reset the first write would pin every
     * later request in that worker to the primary and nothing would look wrong.
     */
    public function resetSticky(): void
    {
        $this->wrote = false;
        $this->primaryReads = 0;
    }

    private function goesToPrimary(): bool
    {
        return $this->wrote || $this->primaryReads > 0 || $this->replicas === [];
    }

    private function readSide(): ConnectionInterface
    {
        if ($this->goesToPrimary()) {
            return $this->write;
        }

        return $this->selector->select($this->replicas);
    }

    /**
     * The replica the selector picked, followed by the rest as failover.
     *
     * @return list<ConnectionInterface>
     */
    private function readOrder(): array
    {
        $first = $this->selector->select($this->replicas);
        $order = [$first];

        foreach ($this->replicas as $replica) {
            if ($replica !== $first) {
                $order[] = $replica;
            }
        }

        return $order;
    }

    private function markWritten(): void
    {
        $this->wrote = true;
    }
}
