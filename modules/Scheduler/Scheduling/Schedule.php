<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Scheduling;

use Closure;
use DateTimeInterface;

/**
 * The timetable: an ordered list of scheduled closures (ported from Marko's
 * `scheduler` package).
 */
final class Schedule
{
    /** @var list<ScheduledTask> */
    private array $tasks = [];

    public function call(Closure $callback): ScheduledTask
    {
        $task = new ScheduledTask($callback);
        $this->tasks[] = $task;

        return $task;
    }

    /** @return list<ScheduledTask> */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /** @return list<ScheduledTask> */
    public function dueTasksAt(DateTimeInterface $time): array
    {
        return array_values(array_filter(
            $this->tasks,
            static fn (ScheduledTask $task): bool => $task->isDue($time),
        ));
    }
}
