<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Tests;

use DateTimeImmutable;
use Modules\Scheduler\Scheduling\Exceptions\SchedulerException;
use Modules\Scheduler\Scheduling\FileTaskMutex;
use Modules\Scheduler\Scheduling\RunnerOutput;
use Modules\Scheduler\Scheduling\Schedule;
use Modules\Scheduler\Scheduling\ScheduleRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ScheduleRunnerTest — beside the code it covers (module test contract:
 * drives the INSTALLED copy, skips on a kernel-only tree).
 */
final class ScheduleRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Scheduler')) {
            $this->markTestSkipped('Scheduler is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/scheduler-runner-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->dir);
        }

        parent::tearDown();
    }

    /** In-memory RunnerOutput: keeps every line for assertions. */
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
    public function failingTaskDoesNotStopTheRest(): void
    {
        $log = [];
        $schedule = new Schedule();
        $schedule->call(static function () use (&$log): void {
            $log[] = 'first';

            throw new RuntimeException('boom');
        })->everyMinute()->description('fails');
        $schedule->call(static function () use (&$log): void {
            $log[] = 'second';
        })->everyMinute()->description('ok');
        // Not due at the fixed time below (02:00 only).
        $schedule->call(static function () use (&$log): void {
            $log[] = 'never';
        })->daily()->description('later');

        $output = $this->collector();
        $runner = new ScheduleRunner($schedule, new FileTaskMutex($this->dir));

        $result = $runner->run(new DateTimeImmutable('2026-01-01 00:07:00'), $output);

        $this->assertSame(['first', 'second'], $log);
        $this->assertSame(1, $result->executed);
        $this->assertSame(1, $result->failed);
        $this->assertSame(0, $result->skipped);
        $this->assertTrue($result->hasFailures());

        $lines = $output->lines;
        $this->assertContains('Failed: fails - boom', $lines);
        $this->assertContains('Executed: ok', $lines);
        $this->assertContains('Executed 1 scheduled tasks.', $lines);
        $this->assertContains('1 scheduled task failed.', $lines);
    }

    #[Test]
    public function overlapHeldTaskIsSkippedAndNotRun(): void
    {
        $ran = false;
        $schedule = new Schedule();
        $task = $schedule->call(static function () use (&$ran): void {
            $ran = true;
        })->everyMinute()->description('guarded')->withoutOverlapping(60);

        $mutex = new FileTaskMutex($this->dir);
        // Another process holds the lock.
        $this->assertTrue($mutex->acquire($task, 3600));

        $output = $this->collector();
        $runner = new ScheduleRunner($schedule, new FileTaskMutex($this->dir));

        $result = $runner->run(new DateTimeImmutable('2026-01-01 00:07:00'), $output);

        $this->assertFalse($ran);
        $this->assertSame(0, $result->executed);
        $this->assertSame(0, $result->failed);
        $this->assertSame(1, $result->skipped);
        $this->assertContains('Skipped (still running): guarded', $output->lines);

        $mutex->release($task);
    }

    #[Test]
    public function noDueTasksReportsZeroes(): void
    {
        $schedule = new Schedule();
        $schedule->call(static fn (): null => null)->daily()->description('midnight');

        $output = $this->collector();
        $runner = new ScheduleRunner($schedule, new FileTaskMutex($this->dir));

        // 12:34 is not midnight: nothing due.
        $result = $runner->run(new DateTimeImmutable('2026-01-01 12:34:00'), $output);

        $this->assertSame(0, $result->executed);
        $this->assertSame(0, $result->failed);
        $this->assertSame(0, $result->skipped);
        $this->assertFalse($result->hasFailures());
        $this->assertContains('No scheduled tasks are due.', $output->lines);
    }

    #[Test]
    public function overlapWithoutDescriptionThrowsUpFront(): void
    {
        $schedule = new Schedule();
        // No description, not due either (midnight-only) — the runner must
        // still fail loudly on the missing identity.
        $schedule->call(static fn (): null => null)->daily()->withoutOverlapping(60);

        $runner = new ScheduleRunner($schedule, new FileTaskMutex($this->dir));

        $this->expectException(SchedulerException::class);

        $runner->run(new DateTimeImmutable('2026-01-01 12:34:00'), $this->collector());
    }
}
