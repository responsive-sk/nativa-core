<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Exceptions\TransactionConflictException;

/**
 * UnitOfWork — transactional consistency across repositories.
 *
 * Wraps database operations in a single transaction. If any operation fails,
 * all changes are rolled back. Follows the "all or nothing" principle.
 *
 * Takes the connection contract rather than `\PDO`, so the callback it hands
 * out can route like every other call in the module: writes to the primary, and
 * reads to a replica until the transaction itself marks the connection as
 * written.
 *
 * ## Nesting
 *
 * Every caller constructs its own `UnitOfWork`, so nesting is the normal case,
 * not an edge: `BulkDeleteAction` opens one, then `UserRepository::delete()`
 * opens another on the same database. The previous per-instance
 * `$transactionStarted` boolean could not see that, so the second
 * `beginTransaction()` threw `PDOException: There is already an active
 * transaction` and a privileged delete 500'd half-done. Depth is therefore
 * tracked **per database**, not per instance.
 *
 * The database's identity is `ConnectionInterface::name()`: the manager returns
 * one routed connection per name, so two callers that asked for `users` are one
 * stack. That is what lets a decorator (`ReadWriteConnection`, which changes the
 * handle underneath) sit between this class and everything else without
 * splitting a transaction in two.
 *
 * ## What a nested throw does
 *
 * A nested scope gets its own SQLite SAVEPOINT and rolls back to it, so the
 * outer transaction survives and can still commit the work it had already done.
 * A failure therefore has the blast radius of the scope that caused it, rather
 * than discarding unrelated good work further out.
 *
 * The outermost scope still behaves as "all or nothing": it rolls the whole
 * transaction back when its own callback throws.
 *
 * ## What this does NOT give you
 *
 * Atomicity **within one SQLite file only**. `users.db`, `audit.db` and
 * `outbox.db` are separate databases and no transaction spans them — a
 * privileged write and its audit row are never atomic together. See
 * `docs/security-patterns.md`.
 *
 * Usage:
 *   $db = DatabaseConnectionManager::instance()->connect('users');
 *   $uow = new UnitOfWork($db);
 *
 *   $uow->transaction(function (ConnectionInterface $db) use ($user, $role) {
 *       $db->execute('INSERT INTO users ...', [...]);
 *       $db->execute('INSERT INTO user_roles ...', [...]);
 *   });
 *
 * @see Modules\Outbox for domain event publishing within transactions
 */
final class UnitOfWork
{
    /**
     * Nesting depth per database name.
     *
     * Keyed by name rather than by object: two UnitOfWork instances over one
     * database are one nesting stack, and the routed connection for that name
     * may be a different object from one call to the next. The set of keys is
     * bounded by the number of databases the process opens, so a plain array
     * does not grow the way a per-request WeakMap was guarding against.
     *
     * @var array<string, int>
     */
    private static array $depth = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    /**
     * Execute multiple operations in a single transaction.
     *
     * If the callback returns a value, it will be returned from this method.
     * If the callback throws an exception, this scope is rolled back and the
     * exception is rethrown — for a nested scope that means rolling back to
     * its savepoint, leaving the outer transaction intact.
     *
     * The outermost scope hands the transaction to the connection, which owns
     * the retry (`ConnectionInterface::transaction()`): `$attempts` and
     * `$backoff` reach it unchanged, and a conflict spends an attempt there.
     * A nested scope ignores both, because retrying it would replay work the
     * outer transaction has already done; its conflict propagates instead, and
     * the outer scope is the one that runs again.
     *
     * @template T
     *
     * @param callable(ConnectionInterface): T                            $fn       Operations to execute in transaction
     * @param int                                                         $attempts How many times the outermost transaction may run, at least 1
     * @param int|(\Closure(int, TransactionConflictException): int)|null $backoff  The delay between attempts
     *
     * @throws \Throwable                   Rethrown from callback after rollback
     * @throws TransactionConflictException When the last attempt still conflicts
     *
     * @return T The return value of the callback
     */
    public function transaction(
        callable $fn,
        int $attempts = 1,
        int|\Closure|null $backoff = null,
    ): mixed {
        if (0 === $this->depth()) {
            // The depth entry is what makes the scopes inside the callback
            // nested savepoints; it has to cover every attempt, so it is set
            // before the first one and dropped after the last.
            self::$depth[$this->name()] = 1;

            try {
                return $this->connection->transaction($fn, $attempts, $backoff);
            } finally {
                unset(self::$depth[$this->name()]);
            }
        }

        $this->beginTransaction();

        try {
            $result = $fn($this->connection);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollback();

            throw $e;
        }
    }

    /**
     * Begin a transaction, or open a savepoint when already inside one.
     */
    public function beginTransaction(): void
    {
        $depth = $this->depth();

        if (0 === $depth) {
            self::$depth[$this->name()] = 1;
            $this->connection->beginTransaction();
            self::log('begin', 1);

            return;
        }

        self::$depth[$this->name()] = $depth + 1;
        $this->connection->execute('SAVEPOINT ' . self::savepointName($depth + 1));
        self::log('savepoint ' . self::savepointName($depth + 1), $depth + 1);
    }

    /**
     * Commit this scope: release its savepoint, or commit the transaction when
     * it is the outermost scope.
     */
    public function commit(): void
    {
        $depth = $this->depth();

        if (0 === $depth) {
            return;
        }

        if (1 === $depth) {
            unset(self::$depth[$this->name()]);
            $this->connection->commit();
            self::log('commit', 1);

            return;
        }

        self::$depth[$this->name()] = $depth - 1;
        $this->connection->execute('RELEASE SAVEPOINT ' . self::savepointName($depth));
        self::log('release ' . self::savepointName($depth), $depth);
    }

    /**
     * Roll back this scope: to its savepoint when nested, or the whole
     * transaction when outermost.
     */
    public function rollback(): void
    {
        $depth = $this->depth();

        if (0 === $depth) {
            return;
        }

        if (1 === $depth) {
            unset(self::$depth[$this->name()]);
            $this->connection->rollBack();
            self::log('rollback', 1);

            return;
        }

        self::$depth[$this->name()] = $depth - 1;
        $this->connection->execute('ROLLBACK TO SAVEPOINT ' . self::savepointName($depth));
        // ROLLBACK TO leaves the savepoint itself on the stack; RELEASE is what
        // actually removes it. Without this the next scope at the same depth
        // would reuse a savepoint that already holds rolled-back state.
        $this->connection->execute('RELEASE SAVEPOINT ' . self::savepointName($depth));
        self::log('rollback to ' . self::savepointName($depth), $depth);
    }

    /**
     * Check if a transaction is currently active on this connection.
     */
    public function inTransaction(): bool
    {
        return $this->depth() > 0;
    }

    /**
     * The current nesting depth on this connection — 0 outside a transaction.
     *
     * A connection already in a transaction that this class never opened (a
     * raw `beginTransaction()` further up the stack, or a leftover from a
     * crashed request) reads as depth 1. That is the conservative reading: the
     * next `beginTransaction()` opens a savepoint instead of asking PDO to
     * begin twice, which is the exception this class exists to stop.
     */
    public function depth(): int
    {
        return self::$depth[$this->name()] ?? ($this->connection->inTransaction() ? 1 : 0);
    }

    /**
     * Forget every connection's depth.
     *
     * Test isolation, and the recovery path after an exception escaped a
     * transaction mid-flight: a stale depth entry would make the next
     * `beginTransaction()` open a savepoint on a connection with no
     * transaction, which SQLite rejects.
     */
    public static function reset(): void
    {
        self::$depth = [];
    }

    private function name(): string
    {
        return $this->connection->name();
    }

    private static function savepointName(int $depth): string
    {
        return 'uow_' . $depth;
    }

    /**
     * DEBUG trace of every transaction boundary.
     *
     * A privilege operation that half-ran leaves no useful trace otherwise:
     * the 500 said "transaction error", not "the outer scope was still open
     * when this began".
     */
    private static function log(string $event, int $depth): void
    {
        if ('debug' !== strtolower((string) (\getenv('LOG_LEVEL') ?: 'info'))) {
            return;
        }

        \error_log(sprintf('[uow] %s depth=%d', $event, $depth));
    }
}
