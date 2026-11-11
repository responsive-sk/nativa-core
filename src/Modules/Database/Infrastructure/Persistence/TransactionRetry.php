<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Exceptions\TransactionConflictException;

/**
 * TransactionRetry — the retry policy behind `ConnectionInterface::transaction()`.
 *
 * The transaction is opened through the contract, so a routed connection wraps
 * its writes exactly as every other write in the module does, and the callback
 * receives that same connection.
 *
 * ## What is retried
 *
 * Only a conflict: a `TransactionConflictException` raised by the callback, by
 * a statement inside it, or by `commit()`. Every other failure is rethrown on
 * the first attempt, because re-running work that failed for a reason nobody
 * identified is how a bug becomes a duplicate row.
 *
 * `attempts` counts total runs, not retries: `attempts: 3` runs the callback
 * up to three times. The last conflict is thrown as
 * `TransactionConflictException`, with the driver's own failure as its
 * previous.
 *
 * ## What is never retried
 *
 * A nested call. When the connection already has a transaction open, the
 * callback runs inside it and no attempt is made to start another or to replay
 * it: the retry belongs to the outermost transaction, which is the only one
 * that can undo everything the attempt did.
 *
 * ## Waiting
 *
 * `$backoff` is the delay between attempts, in milliseconds:
 *
 *  - `null`: exponential backoff with full jitter, a random delay between 0
 *    and `min(500, 10 * 2 ** ($attempt - 1))` milliseconds. Full jitter is what
 *    stops two transactions that collided once from colliding again at the same
 *    instant.
 *  - `int`: that delay, and `0` means retry immediately.
 *  - `\Closure(int $attempt, TransactionConflictException $conflict): int`:
 *    the delay the caller computes, for a budget a fixed number cannot express.
 *
 * `$attempt` is the number of the attempt that just failed, starting at 1. The
 * wait happens only between attempts: never after the last one, and never with
 * `attempts: 1`.
 *
 * @internal
 */
final class TransactionRetry
{
    /**
     * Run the callback in a transaction, retrying a conflict.
     *
     * @param \Closure(ConnectionInterface): mixed                      $body
     * @param int|\Closure(int, TransactionConflictException): int|null $backoff
     *
     * @throws TransactionConflictException When the last attempt still conflicts
     * @throws \InvalidArgumentException    When `attempts` is below 1 or the delay is negative
     */
    public static function run(
        ConnectionInterface $connection,
        \Closure $body,
        int $attempts = 1,
        int|\Closure|null $backoff = null,
    ): mixed {
        if ($attempts < 1) {
            throw new \InvalidArgumentException(sprintf('attempts must be at least 1, %d given', $attempts));
        }

        if (\is_int($backoff) && $backoff < 0) {
            throw new \InvalidArgumentException(sprintf('backoff must not be negative, %d given', $backoff));
        }

        if ($connection->inTransaction()) {
            // The outer transaction owns the retry; this scope is its work.
            return $body($connection);
        }

        $attempt = 1;

        while (true) {
            $connection->beginTransaction();

            try {
                $result = $body($connection);
                $connection->commit();

                return $result;
            } catch (\Throwable $failure) {
                // The driver may already have unwound the transaction (MySQL
                // does, PostgreSQL leaves it aborted), so only roll back what
                // is still open; asking twice would raise over the real error.
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }

                $conflict = $failure instanceof TransactionConflictException
                    ? $failure
                    : TransactionConflictException::detect($connection->name(), $failure);

                if (null === $conflict) {
                    throw $failure;
                }

                if ($attempt >= $attempts) {
                    throw $conflict;
                }

                self::wait($attempt, $backoff, $conflict);
                $attempt++;
            }
        }
    }

    /**
     * @param int|\Closure(int, TransactionConflictException): int|null $backoff
     */
    private static function wait(int $attempt, int|\Closure|null $backoff, TransactionConflictException $conflict): void
    {
        usleep(self::delay($attempt, $backoff, $conflict) * 1000);
    }

    /**
     * @param int|\Closure(int, TransactionConflictException): int|null $backoff
     */
    private static function delay(int $attempt, int|\Closure|null $backoff, TransactionConflictException $conflict): int
    {
        if (null === $backoff) {
            return random_int(0, self::upperBound($attempt));
        }

        if (\is_int($backoff)) {
            return $backoff;
        }

        $delay = $backoff($attempt, $conflict);

        if ($delay < 0) {
            throw new \InvalidArgumentException(sprintf('backoff returned a negative delay, %d', $delay));
        }

        return $delay;
    }

    /**
     * The jittered ceiling for a failed attempt: 10 ms, 20, 40, ... capped at
     * half a second, which keeps a retried request inside its timeout.
     */
    private static function upperBound(int $attempt): int
    {
        // 10 ms doubling per failed attempt, capped at half a second: enough
        // to separate two colliding transactions, short enough that a retried
        // request still fits inside its timeout.
        $bound = 10;

        for ($i = 1; $i < $attempt && $bound < 500; $i++) {
            $bound *= 2;
        }

        return min($bound, 500);
    }
}
