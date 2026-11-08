<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Interfaces\HTTP;

use DateTimeImmutable;
use DateTimeInterface;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Outbox\Application\Services\OutboxDrain;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;
use Modules\Scheduler\Scheduling\FileTaskMutex;
use Modules\Scheduler\Scheduling\RunnerOutput;
use Modules\Scheduler\Scheduling\Schedule;
use Modules\Scheduler\Scheduling\ScheduleRunner;
use Modules\Scheduler\Scheduling\ScheduleRunResult;

/**
 * SchedulerServices — lazy cached service factories for the Scheduler module.
 *
 * The shipped timetable mirrors `outbox:process`: `outbox:drain` every five
 * minutes delivers pending outbox messages, `outbox:prune` daily deletes
 * processed rows older than seven days. Both entries are registered only
 * when the Outbox module is installed (`class_exists`, not a hard
 * dependency) — Scheduler stays a leaf, and on a tree without Outbox the
 * timetable is empty and `schedule:run` reports nothing due.
 *
 * `run()` is the exit-code seam `bin/cms schedule:run` drives: per-task
 * lines plus the summary go to $output, 1 returns on any failure else 0.
 */
final class SchedulerServices
{
    private static ?FileTaskMutex $mutex = null;

    /**
     * The shipped timetable. Callers add their own entries to the returned
     * Schedule — it is a fresh instance per call, never shared.
     */
    public static function schedule(): Schedule
    {
        $schedule = new Schedule();

        if (class_exists(OutboxDrain::class)) {
            $schedule->call(static function (): void {
                OutboxDrain::drain(100);
            })->everyFiveMinutes()->description('outbox:drain');
        }

        if (class_exists(OutboxServices::class)) {
            $schedule->call(static function (): void {
                OutboxServices::repository()->cleanProcessed('7 days');
            })->daily()->description('outbox:prune');
        }

        return $schedule;
    }

    public static function mutex(): FileTaskMutex
    {
        return self::$mutex ??= new FileTaskMutex(
            AppPaths::instance()->storage('cache/scheduler-mutexes/'),
        );
    }

/**
     * Add the queue's worker to $schedule — every minute, with a five-minute
     * overlap guard, so a slow batch is not landed on by the next tick.
     *
     * This lives HERE rather than in `bin/cms` for a measured reason, and the
     * placement is not arbitrary:
     *
     *  - `bin/cms` is analysed by PHPStan in a tree with no module installed,
     *    where the class $schedule belongs to does not exist. Annotating that
     *    parameter, or calling a method on the value, is `class.notFound` at
     *    level 9 — the trap the kernel's own comments keep naming: a type
     *    position is resolved REGARDLESS of the guard.
     *  - Registering it here costs the closure scan nothing. That scan counts
     *    `use Modules\…` lines only (Boot 26), so `QueueServices` is named
     *    inline and never imported — the same escape hatch Boot 26 used for the
     *    Cardboard bulk actions.
     *
     * The entry is skipped while the queue's database has no `jobs` table,
     * because the table comes from a migrator: a cron that fires before the
     * first `bin/cms migrate` would otherwise fail a task every minute and
     * redden every run, for a reason the failure line does not carry. $report
     * is where that one line goes; the module prints nothing itself.
     *
     * @param callable(string): void|null $report
     */
    public static function withQueueWork(Schedule $schedule, ?callable $report = null): void
    {
        if (!class_exists('Modules\Queue\Interfaces\HTTP\QueueServices')) {
            return;
        }

        if (!self::queueDatabaseReady()) {
            if (null !== $report) {
                $report(sprintf(
                    'queue:work skipped — %s has no `jobs` table yet (run: php bin/cms migrate).',
                    AppPaths::instance()->database('queue'),
                ));
            }

            return;
        }

        $schedule->call(static function (): void {
            \Modules\Queue\Interfaces\HTTP\QueueServices::work();
        })->everyMinute()->description('queue:work')->withoutOverlapping(5);
    }

    /**
     * Whether the queue's database carries the `jobs` table.
     *
     * A table lookup, not a file check: an operator who removed and reinstalled
     * the module has the file and no schema, and a file check would call that
     * ready and hand the cron a missing-table error every minute.
     */
    private static function queueDatabaseReady(): bool
    {
        try {
            $statement = DatabaseConnectionManager::instance()
                ->connection('queue')
                ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'jobs'");
        } catch (\Throwable) {
            return false;
        }

        if (false === $statement) {
            return false;
        }

        return false !== $statement->fetchColumn();
    }

    public static function runner(?Schedule $schedule = null, ?FileTaskMutex $mutex = null): ScheduleRunner
    {
        return new ScheduleRunner($schedule ?? self::schedule(), $mutex ?? self::mutex());
    }

    /**
     * Adapt a per-line writer into the RunnerOutput the runner reports
     * through.
     *
     * The CLI owns the printing (bin/cms's out()); the module owns the type.
     * Building the anonymous implementation HERE — inside the module — keeps
     * bin/cms free of any RunnerOutput reference, which is what lets PHPStan
     * analyse the CLI in a kernel-only tree where no Scheduler slice exists.
     */
    public static function output(callable $writeLine): RunnerOutput
    {
        return new class ($writeLine) implements RunnerOutput {
            /** @var callable(string): void */
            private $writeLine;

            public function __construct(callable $writeLine)
            {
                $this->writeLine = $writeLine;
            }

            public function writeLine(string $message): void
            {
                ($this->writeLine)($message);
            }
        };
    }

    /**
     * Run the due tasks of $schedule at $now (default: wall time).
     *
     * @return int 1 when any due task failed, else 0
     */
    public static function run(
        Schedule $schedule,
        RunnerOutput $output,
        ?DateTimeInterface $now = null,
        ?FileTaskMutex $mutex = null,
    ): int {
        $result = self::runner($schedule, $mutex)->run($now ?? new DateTimeImmutable(), $output);

        return self::exitCode($result);
    }

    public static function exitCode(ScheduleRunResult $result): int
    {
        return $result->hasFailures() ? 1 : 0;
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$mutex = null;
    }
}
