<?php

declare(strict_types = 1);

namespace Modules\Database\Contracts;

use Modules\Database\Exceptions\TransactionConflictException;

/**
 * ConnectionInterface — what a module database looks like to its callers.
 *
 * The kernel used to hand `\PDO` to every repository, which made "where does
 * this read go?" a property of the driver and nothing else. This contract is
 * the seam that lets a connection answer the question: a plain SQLite file
 * sends every read to itself, a read/write connection sends reads to a replica
 * and keeps writes on the primary.
 *
 * `query()` means READ and `execute()` means WRITE. That is the whole reason
 * the split is expressible: the caller states its intent, the connection does
 * not have to guess it from SQL. `prepare()` is the one place where the intent
 * is not known yet, so the statement's first keyword decides (`StatementIntent`)
 * and an unreadable intent counts as a write.
 *
 * A statement that cannot be prepared or a query that cannot run raises. The
 * old `PDOStatement|false` branch was unreachable on these connections
 * (`ERRMODE_EXCEPTION`) and every caller already wrote the happy path.
 */
interface ConnectionInterface
{
    /**
     * The database this connection serves: `users`, `audit`, `media`, ...
     *
     * For error messages and diagnostics. Routing never keys on it.
     */
    public function name(): string;

    /**
     * Run a read and return its statement.
     */
    public function query(string $sql): StatementInterface;

    /**
     * Prepare a statement whose intent is read from its first keyword.
     */
    public function prepare(string $sql): StatementInterface;

    /**
     * Run a write and return the affected row count.
     *
     * @param array<string|int, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int;

    /**
     * The row id the last write inserted on the write connection.
     */
    public function lastInsertId(): string;

    /**
     * Whether a transaction is open on the write connection.
     */
    public function inTransaction(): bool;

    /**
     * Run the callback in a transaction, committing on success and rolling
     * back on any failure.
     *
     * The callback receives this connection, so its writes route like every
     * other write: to the primary, through `ReadWriteConnection` when that is
     * what the caller holds.
     *
     * With `$attempts` above 1 the whole transaction runs again when it fails
     * with a `TransactionConflictException` (a deadlock or a serialization
     * failure, raised by the callback, by a statement, or by `commit()`), up
     * to `$attempts` runs in total, and the last conflict is rethrown. Every
     * other failure is rethrown at once. That means the callback must be safe
     * to run more than once, and it is the reason the retry exists: a
     * conflicting transaction is undone by the engine anyway.
     *
     * A call made while a transaction is already open runs inside it and is
     * never retried: the outermost transaction owns the retry, because it is
     * the only one that can undo everything an attempt did. Nesting inside
     * `UnitOfWork` costs a savepoint instead, as it always has.
     *
     * Between attempts the connection waits as `$backoff` says, in
     * milliseconds: `null` (the default) waits a random delay between 0 and
     * `min(500, 10 * 2 ** ($attempt - 1))` (exponential backoff with full
     * jitter), an int waits exactly that long, and a closure
     * `(int $attempt, TransactionConflictException $conflict): int` returns
     * the delay. `$attempt` is the attempt that just failed, starting at 1.
     * There is no wait after the last attempt.
     *
     * @template T
     *
     * @param callable(ConnectionInterface): T                            $callback
     * @param int                                                         $attempts How many times the transaction may run, at least 1
     * @param int|(\Closure(int, TransactionConflictException): int)|null $backoff  The delay between attempts
     *
     * @throws TransactionConflictException When the last attempt still conflicts
     * @throws \InvalidArgumentException    When $attempts is below 1 or a delay is negative
     *
     * @return T The return value of the callback
     */
    public function transaction(
        callable $callback,
        int $attempts = 1,
        int|\Closure|null $backoff = null,
    ): mixed;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;
}
