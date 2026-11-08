<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Scheduling;

use DateTimeInterface;
use Modules\Scheduler\Scheduling\Exceptions\SchedulerException;
use Throwable;

/**
 * ScheduleRunner — runs the tasks due at a given time (ported from Marko's
 * `scheduler` package; Output swapped for RunnerOutput, no marko/* imports).
 *
 * A failing task is reported and counted but does not stop the remaining
 * due tasks. Tasks marked withoutOverlapping() are skipped while their
 * mutex is held.
 */
final readonly class ScheduleRunner
{
    public function __construct(
        private Schedule $schedule,
        private TaskMutexInterface $taskMutex,
    ) {
    }

    /**
     * @throws SchedulerException when an overlap-protected task has no description
     */
    public function run(DateTimeInterface $now, RunnerOutput $output): ScheduleRunResult
    {
        $this->assertOverlapProtectedTasksHaveIdentity();

        $dueTasks = $this->schedule->dueTasksAt($now);

        if ([] === $dueTasks) {
            $output->writeLine('No scheduled tasks are due.');

            return new ScheduleRunResult(executed: 0, failed: 0, skipped: 0);
        }

        $executed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($dueTasks as $index => $task) {
            $description = $task->getDescription() ?? 'Task ' . ($index + 1);
            $preventsOverlapping = $task->preventsOverlapping();

            if ($preventsOverlapping
                && !$this->taskMutex->acquire($task, (int) $task->getOverlapExpiresAfterMinutes() * 60)
            ) {
                $output->writeLine("Skipped (still running): {$description}");
                $skipped++;

                continue;
            }

            try {
                $task->run();
                $output->writeLine("Executed: {$description}");
                $executed++;
            } catch (Throwable $e) {
                $output->writeLine("Failed: {$description} - " . $e->getMessage());
                $failed++;
            } finally {
                if ($preventsOverlapping) {
                    $this->taskMutex->release($task);
                }
            }
        }

        $output->writeLine("Executed {$executed} scheduled tasks.");

        if ($failed > 0) {
            $noun = 1 === $failed ? 'task' : 'tasks';
            $output->writeLine("{$failed} scheduled {$noun} failed.");
        }

        return new ScheduleRunResult(executed: $executed, failed: $failed, skipped: $skipped);
    }

    /**
     * Validate every overlap-protected task up front, due or not, so a
     * misconfigured task fails loudly on the first run instead of only when
     * it happens to be due.
     *
     * @throws SchedulerException
     */
    private function assertOverlapProtectedTasksHaveIdentity(): void
    {
        foreach ($this->schedule->tasks() as $task) {
            if ($task->preventsOverlapping()) {
                $task->mutexName();
            }
        }
    }
}
