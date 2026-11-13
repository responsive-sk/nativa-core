<?php

declare(strict_types = 1);

namespace Tests\Database\Support;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Contracts\StatementInterface;
use Modules\Database\Infrastructure\Persistence\TransactionRetry;

/**
 * RecordingConnection — a connection whose only real behaviour is counting
 * what the retry does to it, so an extra begin or a missing rollback shows.
 *
 * It runs the real `TransactionRetry`, so a test using it exercises the policy
 * rather than a copy of it.
 *
 * @internal
 */
final class RecordingConnection implements ConnectionInterface
{
    public int $begins = 0;

    public int $commits = 0;

    public int $rollbacks = 0;

    private bool $open = false;

    public function name(): string
    {
        return 'recording';
    }

    public function query(string $sql): StatementInterface
    {
        throw new \LogicException('RecordingConnection does not run SQL.');
    }

    public function prepare(string $sql): StatementInterface
    {
        throw new \LogicException('RecordingConnection does not run SQL.');
    }

    public function execute(string $sql, array $bindings = []): int
    {
        throw new \LogicException('RecordingConnection does not run SQL.');
    }

    public function lastInsertId(): string
    {
        return '0';
    }

    public function inTransaction(): bool
    {
        return $this->open;
    }

    public function transaction(
        callable $callback,
        int $attempts = 1,
        int|\Closure|null $backoff = null,
    ): mixed {
        return TransactionRetry::run($this, $callback(...), $attempts, $backoff);
    }

    public function beginTransaction(): void
    {
        $this->begins++;
        $this->open = true;
    }

    public function commit(): void
    {
        $this->commits++;
        $this->open = false;
    }

    public function rollBack(): void
    {
        $this->rollbacks++;
        $this->open = false;
    }
}
