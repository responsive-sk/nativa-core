<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

/**
 * UnitOfWork — transactional consistency across repositories.
 *
 * Wraps database operations in a single transaction. If any operation fails,
 * all changes are rolled back. Follows the "all or nothing" principle.
 *
 * Accepts a PDO connection directly so it works with any module database.
 *
 * ## Nesting
 *
 * Every caller constructs its own `UnitOfWork`, so nesting is the normal case,
 * not an edge: `BulkDeleteAction` opens one, then `UserRepository::delete()`
 * opens another on the same PDO. The previous per-instance `$transactionStarted`
 * boolean could not see that, so the second `beginTransaction()` threw
 * `PDOException: There is already an active transaction` and a privileged
 * delete 500'd half-done. Depth is therefore tracked **per PDO handle**, not
 * per instance.
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
 *   $pdo = DatabaseConnectionManager::instance()->connection('users');
 *   $uow = new UnitOfWork($pdo);
 *
 *   $uow->transaction(function (\PDO $pdo) use ($user, $role) {
 *       $pdo->prepare('INSERT INTO users ...')->execute([...]);
 *       $pdo->prepare('INSERT INTO user_roles ...')->execute([...]);
 *   });
 *
 * @see Modules\Outbox for domain event publishing within transactions
 */
final class UnitOfWork
{
    /**
     * Nesting depth per PDO handle.
     *
     * Keyed by handle rather than by object: two UnitOfWork instances over one
     * connection are one nesting stack, and only the handle can tell that.
     * A WeakMap lets the entry disappear with the connection, so a per-request
     * process does not accumulate one entry per database it ever opened.
     *
     * @var \WeakMap<\PDO, int>
     */
    private static ?\WeakMap $depth = null;

    public function __construct(
        private readonly \PDO $pdo,
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
     * @template T
     *
     * @param callable(\PDO): T $fn Operations to execute in transaction
     *
     * @throws \Throwable Rethrown from callback after rollback
     *
     * @return T The return value of the callback
     */
    public function transaction(callable $fn): mixed
    {
        $this->beginTransaction();

        try {
            $result = $fn($this->pdo);
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
            self::stack()[$this->pdo] = 1;
            $this->pdo->beginTransaction();
            self::log('begin', 1);

            return;
        }

        self::stack()[$this->pdo] = $depth + 1;
        $this->pdo->exec('SAVEPOINT ' . self::savepointName($depth + 1));
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
            unset(self::stack()[$this->pdo]);
            $this->pdo->commit();
            self::log('commit', 1);

            return;
        }

        self::stack()[$this->pdo] = $depth - 1;
        $this->pdo->exec('RELEASE SAVEPOINT ' . self::savepointName($depth));
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
            unset(self::stack()[$this->pdo]);
            $this->pdo->rollBack();
            self::log('rollback', 1);

            return;
        }

        self::stack()[$this->pdo] = $depth - 1;
        $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::savepointName($depth));
        // ROLLBACK TO leaves the savepoint itself on the stack; RELEASE is what
        // actually removes it. Without this the next scope at the same depth
        // would reuse a savepoint that already holds rolled-back state.
        $this->pdo->exec('RELEASE SAVEPOINT ' . self::savepointName($depth));
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
        return self::stack()[$this->pdo] ?? ($this->pdo->inTransaction() ? 1 : 0);
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
        self::$depth = null;
    }

    /**
     * The depth stack, created on first use.
     *
     * A `new` expression cannot appear in a static property initializer, and
     * building the WeakMap eagerly for every class load would be wasteful on a
     * request that never opens a transaction.
     *
     * @return \WeakMap<\PDO, int>
     */
    private static function stack(): \WeakMap
    {
        return self::$depth ??= new \WeakMap();
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
