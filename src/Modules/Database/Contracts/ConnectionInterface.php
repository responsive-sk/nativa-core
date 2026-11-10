<?php

declare(strict_types = 1);

namespace Modules\Database\Contracts;

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

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;
}
