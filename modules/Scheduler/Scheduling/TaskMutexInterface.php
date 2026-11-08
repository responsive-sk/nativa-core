<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Scheduling;

use Modules\Scheduler\Scheduling\Exceptions\SchedulerException;

/**
 * Prevents a scheduled task from running while a previous run is still in
 * progress (ported from Marko's `scheduler` package).
 *
 * The default implementation is a local file lock. Multi-server deployments
 * would bind a distributed implementation instead — this tree is single-host
 * by design (shared hosting), so the file lock is the whole story.
 */
interface TaskMutexInterface
{
    /**
     * Try to take the mutex for the task without blocking.
     *
     * A mutex held for longer than $expiresAfterSeconds is considered stale
     * and is reclaimed.
     *
     * @return bool true when the mutex was acquired, false when another run holds it
     *
     * @throws SchedulerException when the mutex cannot be stored
     */
    public function acquire(ScheduledTask $task, int $expiresAfterSeconds): bool;

    /**
     * Release a mutex previously acquired by this instance. Does nothing if
     * it is not held.
     *
     * @throws SchedulerException when the task has no stable identity
     */
    public function release(ScheduledTask $task): void;

    /**
     * Whether an unexpired mutex is currently held for the task.
     *
     * @throws SchedulerException when the task has no stable identity
     */
    public function exists(ScheduledTask $task): bool;
}
