<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Tests;

use DateTimeImmutable;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Scheduler\Interfaces\HTTP\SchedulerServices;
use Modules\Scheduler\Scheduling\FileTaskMutex;
use Modules\Scheduler\Scheduling\RunnerOutput;
use Modules\Scheduler\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ScheduleRunCommandTest — the exit-code seam `bin/cms schedule:run`
 * drives (SchedulerServices::run), beside the code it covers (module test
 * contract: drives the INSTALLED copy, skips on a kernel-only tree).
 *
 * The CLI branch itself stays thin (build timetable, adapt output, exit);
 * what is asserted here is its contract: per-task lines reach the output,
 * a clean timetable exits 0, a throwing due task exits 1. The mutex is a
 * throwaway temp dir — never the site's storage/.
 */
final class ScheduleRunCommandTest extends TestCase
{
    private string $dir;

    private ?string $dbFile = null;

    private ?string $previousDbQueue = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Scheduler')) {
            $this->markTestSkipped('Scheduler is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/scheduler-cmd-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->dir);

            SchedulerServices::reset();

            // A test that pointed DB_QUEUE at a throwaway file puts the
            // process back the way it found it — the tree's own storage/data
            // must not become a test fixture.
            if (null !== $this->dbFile) {
                if (null === $this->previousDbQueue) {
                    putenv('DB_QUEUE');
                } else {
                    putenv('DB_QUEUE=' . $this->previousDbQueue);
                }

                AppPaths::resetInstance();
                DatabaseConnectionManager::resetInstance();
            }
        }

        parent::tearDown();
    }

    private function collector(): RunnerOutput
    {
        return new class implements RunnerOutput {
            /** @var list<string> */
            public array $lines = [];

            public function writeLine(string $message): void
            {
                $this->lines[] = $message;
            }
        };
    }

    #[Test]
    public function cleanTimetableExitsZeroWithPerTaskLines(): void
    {
        $schedule = new Schedule();
        $schedule->call(static fn (): null => null)->everyMinute()->description('tidy');
        $schedule->call(static fn (): null => null)->daily()->description('not-due');

        $output = $this->collector();

        $exit = SchedulerServices::run(
            $schedule,
            $output,
            new DateTimeImmutable('2026-01-01 00:07:00'),
            new FileTaskMutex($this->dir),
        );

        $this->assertSame(0, $exit);
        $this->assertContains('Executed: tidy', $output->lines);
        $this->assertContains('Executed 1 scheduled tasks.', $output->lines);
    }

    #[Test]
    public function throwingDueTaskExitsOne(): void
    {
        $schedule = new Schedule();
        $schedule->call(static function (): void {
            throw new RuntimeException('cron blew up');
        })->everyMinute()->description('unlucky');

        $output = $this->collector();

        $exit = SchedulerServices::run(
            $schedule,
            $output,
            new DateTimeImmutable('2026-01-01 00:07:00'),
            new FileTaskMutex($this->dir),
        );

        $this->assertSame(1, $exit);
        $this->assertContains('Failed: unlucky - cron blew up', $output->lines);
    }

    #[Test]
    public function theQueueWorkerJoinsTheTimetableWhenItsDatabaseIsMigrated(): void
    {
        if (!class_exists(\Modules\Queue\Interfaces\HTTP\QueueServices::class)) {
            $this->markTestSkipped('Queue is not installed.');
        }

        $this->migrateQueueDatabase();

        $schedule = new Schedule();
        $reported = [];

        SchedulerServices::withQueueWork(
            $schedule,
            static function (string $message) use (&$reported): void {
                $reported[] = $message;
            },
        );

        $this->assertCount(1, $schedule->tasks(), 'exactly one entry, not one per cron tick.');
        $this->assertSame('queue:work', $schedule->tasks()[0]->getDescription());
        $this->assertTrue($schedule->tasks()[0]->preventsOverlapping(), 'a slow batch must not be landed on.');
        $this->assertSame([], $reported, 'a migrated database produces no skip line.');
    }

    #[Test]
    public function theQueueEntryIsSkippedWhileItsDatabaseHasNoJobsTable(): void
    {
        if (!class_exists(\Modules\Queue\Interfaces\HTTP\QueueServices::class)) {
            $this->markTestSkipped('Queue is not installed.');
        }

        // Point DB_QUEUE at a file with no schema. The entry must NOT be
        // registered and the reason must be REPORTED — a cron that fires before
        // the first `bin/cms migrate` would otherwise fail a task every minute
        // and redden every run, for a reason the failure line does not carry.
        $this->previousDbQueue = getenv('DB_QUEUE') ?: null;
        $this->dbFile = $this->dir . '/unmigrated-queue.db';
        putenv('DB_QUEUE=' . $this->dbFile);
        // BOTH singletons: AppPaths resolves the path, and the connection
        // manager CACHES the open PDO per name. Resetting only the first leaves
        // the manager handing back the PREVIOUS test's migrated handle — which
        // is how "the entry must not register" passed anyway.
        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();

        $schedule = new Schedule();
        $reported = [];

        SchedulerServices::withQueueWork(
            $schedule,
            static function (string $message) use (&$reported): void {
                $reported[] = $message;
            },
        );

        $this->assertSame([], $schedule->tasks());
        $this->assertCount(1, $reported);
        $this->assertStringContainsString('has no `jobs` table yet', $reported[0]);
        $this->assertStringContainsString(
            $this->dbFile,
            $reported[0],
            'the skip line must quote the REAL path, or it sends the operator to the wrong file.',
        );
    }

    /**
     * Migrate a throwaway queue database so the entry registers.
     */
    private function migrateQueueDatabase(): void
    {
        $this->previousDbQueue = getenv('DB_QUEUE') ?: null;
        $this->dbFile = $this->dir . '/queue.db';
        putenv('DB_QUEUE=' . $this->dbFile);
        // BOTH singletons: AppPaths resolves the path, and the connection
        // manager CACHES the open PDO per name. Resetting only the first leaves
        // the manager handing back the PREVIOUS test's migrated handle — which
        // is how "the entry must not register" passed anyway.
        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();

        $null = new class implements \Modules\Core\Interfaces\CLI\CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new \Modules\Queue\Infrastructure\Persistence\Migrations\QueueDatabaseMigrator(
            \Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager::instance()->connection('queue'),
        ))->run($null);
    }

    #[Test]
    public function shippedTimetableCarriesOutboxEntries(): void
    {
        if (!class_exists(\Modules\Outbox\Application\Services\OutboxDrain::class)) {
            $this->markTestSkipped('Outbox is not installed.');
        }

        $schedule = SchedulerServices::schedule();
        $descriptions = array_map(
            static fn ($task): ?string => $task->getDescription(),
            $schedule->tasks(),
        );

        $this->assertContains('outbox:drain', $descriptions);
        $this->assertContains('outbox:prune', $descriptions);
    }
}
