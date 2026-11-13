<?php

declare(strict_types = 1);

namespace Modules\Queue\Application\Services;

use Closure;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Queue\Exceptions\PoisonJobException;
use Modules\Queue\Infrastructure\Persistence\Statement;
use PDO;
use Throwable;

/**
 * DatabaseQueue — SQLite-backed job queue on the jobs / failed_jobs
 * tables (QueueDatabaseMigrator schema).
 *
 * Reservation runs under BEGIN IMMEDIATE with a guarded UPDATE
 * (`... WHERE id = ? AND reserved_at IS NULL`): SQLite has no
 * SELECT ... FOR UPDATE / SKIP LOCKED, so the second contender's UPDATE
 * matches zero rows and gets null instead of the same job. That deviation
 * from row-locking queues is load-bearing — do not "simplify" the guard
 * away.
 *
 * The `attempts` column is authoritative: reserve increments it, and the
 * worker parks the job in failed_jobs (with the error message) once
 * attempts >= max_attempts. Handler failures and unknown handlers both
 * land there — never a fatal.
 *
 * Time is a `Y-m-d H:i:s` string column compared lexicographically; the
 * clock is injectable for tests (default: wall time).
 */
final class DatabaseQueue
{
    /**
     * Retry backoff, doubling per attempt from the first: 30s, 60s, 120s …
     *
     * A throwing handler used to be released with NO delay, which wrote
     * `available_at = now` — so the next loop iteration found the same row
     * again and a failing job burned every attempt inside one batch. Measured:
     * a job with `max_attempts = 3` and the default limit of 10 ended up parked
     * with `attempts = 3` after a single `work()` call. `max_attempts` bounded
     * RETRIES, not MINUTES.
     *
     * The formula matches Media's Cloudinary client
     * (`BASE_DELAY_MS * (2 ** ($attempt - 1))`), which is the one place this
     * tree already does exponential backoff, so the two retry paths read alike.
     */
    private const int RETRY_BASE_SECONDS = 30;

    /**
     * The cap, and it is not optional: `max_attempts` has no upper bound (a
     * caller sets it per job), so an uncapped `2 ** attempts` would schedule a
     * job years into the future and quietly turn a poison handler into a row
     * nobody ever looks at again.
     */
    private const int RETRY_MAX_SECONDS = 3600;

    private readonly Closure $now;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly HandlerRegistry $handlers,
        ?callable $now = null,
    ) {
        $this->now = $now instanceof Closure ? $now : time(...);
    }

    /**
     * Push a job. Returns the job id (32-char hex, tree convention).
     *
     * @param array<string, mixed> $args JSON-encoded into the payload column
     */
    public function push(
        string $handler,
        array $args = [],
        string $queue = 'default',
        int $maxAttempts = 3,
        int $delaySeconds = 0,
    ): string {
        $id = bin2hex(random_bytes(16));

        $statement = Statement::prepare($this->connection,
            'INSERT INTO jobs (id, queue, handler, payload, attempts, max_attempts, reserved_at, available_at)'
            . ' VALUES (:id, :queue, :handler, :payload, 0, :max_attempts, NULL, :available_at)',
        );
        $statement->execute([
            ':id' => $id,
            ':queue' => $queue,
            ':handler' => $handler,
            ':payload' => json_encode($args, flags: JSON_THROW_ON_ERROR),
            ':max_attempts' => max(1, $maxAttempts),
            ':available_at' => $this->stamp($delaySeconds),
        ]);

        return $id;
    }

    /**
     * Reserve the next available job (null queue = any queue, earliest
     * available_at first). Returns null when nothing is reservable.
     *
     * @throws PoisonJobException when the row's payload is corrupt — the
     *   row is already parked in failed_jobs; call reserve() again.
     */
    public function reserve(?string $queue = null): ?QueuedJob
    {
        // `BEGIN IMMEDIATE` stays spelled out: PDO's beginTransaction() issues a
        // deferred BEGIN, and the whole reservation guard depends on the write
        // lock being taken before the SELECT.
        $this->connection->execute('BEGIN IMMEDIATE');

        try {
            $row = $this->findAvailable($queue);

            if (null === $row) {
                $this->connection->execute('ROLLBACK');

                return null;
            }

            $row['attempts'] = (int) $row['attempts'] + 1;

            try {
                $job = QueuedJob::fromRow($row);
            } catch (Throwable $e) {
                // Corrupt payload: park the row before anyone can spin on
                // it, then tell the caller to move to the next job.
                $this->parkPoison($row, $e->getMessage());
                $this->connection->execute('COMMIT');

                throw new PoisonJobException(
                    sprintf('Job %s has a corrupt payload and was moved to failed_jobs.', $row['id']),
                    previous: $e,
                );
            }

            $claimed = Statement::prepare($this->connection,
                'UPDATE jobs SET reserved_at = :now, attempts = attempts + 1'
                . ' WHERE id = :id AND reserved_at IS NULL',
            );
            $claimed->execute([':now' => $this->stamp(), ':id' => $row['id']]);

            if (0 === $claimed->rowCount()) {
                // Lost the race: another process reserved it first.
                $this->connection->execute('ROLLBACK');

                return null;
            }

            $this->connection->execute('COMMIT');

            return $job;
        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->execute('ROLLBACK');
            }

            throw $e;
        }
    }

    /**
     * Delete a finished job.
     */
    public function delete(string $id): void
    {
        $statement = Statement::prepare($this->connection, 'DELETE FROM jobs WHERE id = :id');
        $statement->execute([':id' => $id]);
    }

    /**
     * List jobs newest-first (null queue = all queues).
     *
     * Read-only admin API: rows carry a derived `state` (reserved /
     * delayed / pending) and no decoded payload — the detail accessor
     * decodes that. Payload stays raw so a corrupt row lists instead of
     * fataling.
     *
     * @return list<array{id: string, queue: string, handler: string, attempts: int, max_attempts: int, reserved_at: ?string, available_at: string, created_at: ?string, state: string}>
     */
    public function listJobs(?string $queue = null, int $limit = 100): array
    {
        $now = $this->stamp();

        if (null === $queue) {
            $statement = Statement::prepare($this->connection,
                'SELECT id, queue, handler, attempts, max_attempts, reserved_at, available_at, created_at'
                . ' FROM jobs ORDER BY created_at DESC, rowid DESC LIMIT :limit',
            );
            $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        } else {
            $statement = Statement::prepare($this->connection,
                'SELECT id, queue, handler, attempts, max_attempts, reserved_at, available_at, created_at'
                . ' FROM jobs WHERE queue = :queue ORDER BY created_at DESC, rowid DESC LIMIT :limit',
            );
            $statement->bindValue(':queue', $queue);
            $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        }

        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->presentJobRow($row, $now), $rows);
    }

    /**
     * One job row with raw payload (null when missing). The caller
     * decodes the payload for display, falling back to the raw string
     * when the row is poison.
     *
     * @return array{id: string, queue: string, handler: string, attempts: int, max_attempts: int, reserved_at: ?string, available_at: string, created_at: ?string, state: string, payload: string}|null
     */
    public function findJob(string $id): ?array
    {
        $statement = Statement::prepare($this->connection,
            'SELECT id, queue, handler, attempts, max_attempts, reserved_at, available_at, created_at, payload'
            . ' FROM jobs WHERE id = :id',
        );
        $statement->execute([':id' => $id]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (false === $row) {
            return null;
        }

        return $this->presentJobRow($row, $this->stamp()) + ['payload' => (string) $row['payload']];
    }

    /**
     * How many failed jobs are older than $interval — what `pruneFailed()`
     * would remove. The preview half of the pair, so a destructive command can
     * say what it is about to do without doing it.
     */
    public function countFailedOlderThan(string $interval = '30 days'): int
    {
        $statement = Statement::prepare($this->connection,
            "SELECT COUNT(*) FROM failed_jobs WHERE failed_at < datetime('now', '-' || ?)",
        );
        $statement->execute([$interval]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Remove failed jobs parked longer ago than $interval. Returns how many
     * went.
     *
     * The shape follows `SqliteOutboxRepository::cleanProcessed()` exactly —
     * same `datetime('now', '-' || ?)` comparison, same returned rowCount — so
     * the two retention stories in this tree read alike. What differs is
     * WHAT gets retained, and why this is not folded into `work()`:
     *
     * `outbox_messages` rows that are `processed` are finished work whose only
     * remaining value is historical, so the Outbox prunes them in the same
     * command that drains the queue. **`failed_jobs` is not that.** A parked
     * row is the only record that a job failed AND why — it is an operator's
     * work queue, with "retry" and "drop" on it in the panel. Deleting it on a
     * schedule would remove evidence nobody had looked at yet, and the run
     * that did it would report success.
     *
     * So retention here is an explicit command, not a side effect. The default
     * is a month: long enough that a failure reported on Monday is still
     * diagnosable the following month, bounded enough that the table is not a
     * second log.
     */
    public function pruneFailed(string $interval = '30 days'): int
    {
        $statement = Statement::prepare($this->connection,
            "DELETE FROM failed_jobs WHERE failed_at < datetime('now', '-' || ?)",
        );
        $statement->execute([$interval]);

        return $statement->rowCount();
    }

    /**
     * List failed jobs newest-first.
     *
     * @return list<array{id: string, queue: string, handler: string, attempts: int, error: ?string, failed_at: ?string, payload: string}>
     */
    public function listFailed(int $limit = 100): array
    {
        $statement = Statement::prepare($this->connection,
            'SELECT id, queue, handler, attempts, error, failed_at, payload'
            . ' FROM failed_jobs ORDER BY failed_at DESC, rowid DESC LIMIT :limit',
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => [
            'id' => (string) $row['id'],
            'queue' => (string) $row['queue'],
            'handler' => (string) $row['handler'],
            'attempts' => (int) $row['attempts'],
            'error' => null !== $row['error'] ? (string) $row['error'] : null,
            'failed_at' => null !== $row['failed_at'] ? (string) $row['failed_at'] : null,
            'payload' => (string) $row['payload'],
        ], $rows);
    }

    /**
     * One failed row (null when missing).
     *
     * @return array{id: string, queue: string, handler: string, attempts: int, error: ?string, failed_at: ?string, payload: string}|null
     */
    public function findFailed(string $id): ?array
    {
        $statement = Statement::prepare($this->connection,
            'SELECT id, queue, handler, attempts, error, failed_at, payload'
            . ' FROM failed_jobs WHERE id = :id',
        );
        $statement->execute([':id' => $id]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (false === $row) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'queue' => (string) $row['queue'],
            'handler' => (string) $row['handler'],
            'attempts' => (int) $row['attempts'],
            'error' => null !== $row['error'] ? (string) $row['error'] : null,
            'failed_at' => null !== $row['failed_at'] ? (string) $row['failed_at'] : null,
            'payload' => (string) $row['payload'],
        ];
    }

    /**
     * Re-queue a failed job under its own id with attempts reset.
     *
     * failed_jobs carries no max_attempts column (schema is fixed), so a
     * retried job gets $maxAttempts (default 3) — documented, not hidden.
     * Returns false when the id is not in failed_jobs.
     */
    public function retryFailed(string $id, int $maxAttempts = 3): bool
    {
        $failed = $this->findFailed($id);

        if (null === $failed) {
            return false;
        }

        $statement = Statement::prepare($this->connection,
            'INSERT INTO jobs (id, queue, handler, payload, attempts, max_attempts, reserved_at, available_at)'
            . ' VALUES (:id, :queue, :handler, :payload, 0, :max_attempts, NULL, :available_at)',
        );
        $statement->execute([
            ':id' => $failed['id'],
            ':queue' => $failed['queue'],
            ':handler' => $failed['handler'],
            ':payload' => $failed['payload'],
            ':max_attempts' => max(1, $maxAttempts),
            ':available_at' => $this->stamp(),
        ]);

        $this->deleteFailed($id);

        return true;
    }

    /**
     * Drop a failed job. Returns false when the id is not there.
     */
    public function deleteFailed(string $id): bool
    {
        $statement = Statement::prepare($this->connection, 'DELETE FROM failed_jobs WHERE id = :id');
        $statement->execute([':id' => $id]);

        return $statement->rowCount() > 0;
    }

    /**
     * Shape a raw jobs row for the admin list (derived state included).
     *
     * @param array<string, mixed> $row
     *
     * @return array{id: string, queue: string, handler: string, attempts: int, max_attempts: int, reserved_at: ?string, available_at: string, created_at: ?string, state: string}
     */
    private function presentJobRow(array $row, string $now): array
    {
        $reservedAt = null !== $row['reserved_at'] ? (string) $row['reserved_at'] : null;

        if (null !== $reservedAt) {
            $state = 'reserved';
        } elseif ((string) $row['available_at'] > $now) {
            $state = 'delayed';
        } else {
            $state = 'pending';
        }

        return [
            'id' => (string) $row['id'],
            'queue' => (string) $row['queue'],
            'handler' => (string) $row['handler'],
            'attempts' => (int) $row['attempts'],
            'max_attempts' => (int) $row['max_attempts'],
            'reserved_at' => $reservedAt,
            'available_at' => (string) $row['available_at'],
            'created_at' => null !== $row['created_at'] ? (string) $row['created_at'] : null,
            'state' => $state,
        ];
    }

    /**
     * Release a reserved job back to available (optional backoff delay).
     */
    public function release(string $id, int $delaySeconds = 0): void
    {
        $statement = Statement::prepare($this->connection,
            'UPDATE jobs SET reserved_at = NULL, available_at = :at WHERE id = :id',
        );
        $statement->execute([':at' => $this->stamp($delaySeconds), ':id' => $id]);
    }

    /**
     * Seconds to wait before attempt N+1, doubling from the base and capped.
     *
     * `$job->attempts` already counts the reservation that just failed, so the
     * first release is the base delay and not half of it.
     */
    private function retryDelayFor(QueuedJob $job): int
    {
        return min(
            self::RETRY_MAX_SECONDS,
            self::RETRY_BASE_SECONDS * (2 ** max(0, $job->attempts - 1)),
        );
    }

    /**
     * Park an exhausted job in failed_jobs with the error that sank it.
     */
    public function fail(QueuedJob $job, string $error): void
    {
        $statement = Statement::prepare($this->connection,
            'INSERT INTO failed_jobs (id, queue, handler, payload, attempts, error)'
            . ' VALUES (:id, :queue, :handler, :payload, :attempts, :error)',
        );
        $statement->execute([
            ':id' => $job->id,
            ':queue' => $job->queue,
            ':handler' => $job->handler,
            ':payload' => json_encode($job->args, flags: JSON_THROW_ON_ERROR),
            ':attempts' => $job->attempts,
            ':error' => $error,
        ]);

        $this->delete($job->id);
    }

    /**
     * Run up to $limit jobs from $queue (null = any queue).
     *
     * A throwing handler releases the job for retry until attempts reach
     * max_attempts, then parks it in failed_jobs. Corrupt payloads and
     * unknown handlers travel the same path.
     *
     * @return array{processed: int, failed: int}
     */
    public function work(?string $queue = null, int $limit = 10): array
    {
        $processed = 0;
        $failed = 0;

        for ($i = 0; $i < $limit; $i++) {
            try {
                $job = $this->reserve($queue);
            } catch (PoisonJobException $e) {
                // Already parked in failed_jobs by reserve().
                $failed++;

                continue;
            }

            if (null === $job) {
                break;
            }

            try {
                $this->handlers->run($job->handler, $job->args);
                $this->delete($job->id);
                $processed++;
            } catch (Throwable $e) {
                if ($job->attempts >= $job->maxAttempts) {
                    $this->fail($job, $e->getMessage());
                    $failed++;
                } else {
                    // Released INTO THE FUTURE, which is the whole point: the
                    // row drops out of `findAvailable()` for this batch and for
                    // every tick until the delay has passed.
                    $this->release($job->id, $this->retryDelayFor($job));
                }
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    /**
     * Move a corrupt-payload row to failed_jobs. The caller holds the
     * IMMEDIATE transaction; this neither commits nor rolls back.
     *
     * @param array<string, mixed> $row
     */
    private function parkPoison(array $row, string $error): void
    {
        $statement = Statement::prepare($this->connection,
            'INSERT INTO failed_jobs (id, queue, handler, payload, attempts, error)'
            . ' VALUES (:id, :queue, :handler, :payload, :attempts, :error)',
        );
        $statement->execute([
            ':id' => $row['id'],
            ':queue' => $row['queue'],
            ':handler' => $row['handler'],
            ':payload' => $row['payload'],
            ':attempts' => $row['attempts'],
            ':error' => 'Corrupt payload: ' . $error,
        ]);

        $this->delete($row['id']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAvailable(?string $queue): ?array
    {
        if (null === $queue) {
            $statement = Statement::prepare($this->connection,
                'SELECT * FROM jobs WHERE reserved_at IS NULL AND available_at <= :now'
                . ' ORDER BY available_at ASC LIMIT 1',
            );
            $statement->execute([':now' => $this->stamp()]);
        } else {
            $statement = Statement::prepare($this->connection,
                'SELECT * FROM jobs WHERE queue = :queue AND reserved_at IS NULL AND available_at <= :now'
                . ' ORDER BY available_at ASC LIMIT 1',
            );
            $statement->execute([':queue' => $queue, ':now' => $this->stamp()]);
        }

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : $row;
    }

    private function stamp(int $offsetSeconds = 0): string
    {
        return date('Y-m-d H:i:s', ($this->now)() + $offsetSeconds);
    }
}
