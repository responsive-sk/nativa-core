<?php

declare(strict_types = 1);

namespace Modules\Queue\Interfaces\HTTP;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Queue\Application\Services\DatabaseQueue;
use Modules\Queue\Application\Services\HandlerRegistry;

/**
 * QueueServices — lazy cached service factories for the Queue module.
 *
 * Actions never `new` the queue: the handler registry is shared process-
 * wide (handlers register once, the worker and the admin see the same
 * map), and `reset()` keeps tests isolated.
 *
 * `work()` and `exitCode()` are the seam `bin/cms queue:work` drives, shaped
 * like `SchedulerServices::run()`: the module owns the semantics and the exit
 * code, the CLI owns the printing, and the tests assert the same contract the
 * command does — so what the suite proves and what the operator runs cannot
 * drift apart.
 */
final class QueueServices
{
    private static ?DatabaseQueue $queue = null;

    private static ?HandlerRegistry $handlers = null;

    public static function queue(): DatabaseQueue
    {
        return self::$queue ??= new DatabaseQueue(
            DatabaseConnectionManager::instance()->connection('queue'),
            self::handlers(),
        );
    }

    public static function handlers(): HandlerRegistry
    {
        return self::$handlers ??= new HandlerRegistry();
    }

    /**
     * Work one batch — up to $limit jobs from $queue (null = any queue).
     *
     * A job whose handler throws is RELEASED for retry until it exhausts
     * max_attempts and is parked in failed_jobs; a handler nobody registered,
     * or a payload that will not decode, travels that same path. `failed`
     * counts only what ended up parked, so it is the number an operator has to
     * look at, not the number that happened.
     *
     * @return array{processed: int, failed: int}
     */
    public static function work(?string $queue = null, int $limit = 10): array
    {
        return self::queue()->work($queue, max(1, $limit));
    }

    /**
     * The exit code a worker run reports: 1 when anything is parked in
     * failed_jobs, so a cron entry or a CI probe notices a poison job instead
     * of silently rotating past it. A job released for another attempt is not
     * a failure and does not redden the run.
     *
     * @param array{processed: int, failed: int} $result
     */
    public static function exitCode(array $result): int
    {
        return $result['failed'] > 0 ? 1 : 0;
    }

    /**
     * Remove failed jobs parked longer ago than $interval.
     *
     * Deliberately NOT part of `work()`: a parked job is the only record that
     * something failed and why, so retention is something an operator asks for
     * by name rather than something a cron entry does on its way past. See
     * `DatabaseQueue::pruneFailed()` for the full reasoning.
     *
     * With $dryRun the count is reported and nothing is deleted, so the answer
     * to "what would this remove" costs nothing to ask.
     *
     * @return array{removed: int, wouldRemove: int, interval: string}
     */
    public static function prune(string $interval = '30 days', bool $dryRun = false): array
    {
        $queue = self::queue();

        if ($dryRun) {
            return [
                'removed' => 0,
                'wouldRemove' => $queue->countFailedOlderThan($interval),
                'interval' => $interval,
            ];
        }

        return [
            'removed' => $queue->pruneFailed($interval),
            'wouldRemove' => 0,
            'interval' => $interval,
        ];
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$queue = null;
        self::$handlers = null;
    }
}
