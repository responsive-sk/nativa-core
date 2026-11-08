<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Tests;

use DateTimeImmutable;
use Modules\Scheduler\Scheduling\Exceptions\SchedulerException;
use Modules\Scheduler\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ScheduleTest — the fluent timetable API beside the code it covers
 * (module test contract: drives the INSTALLED copy, skips on a
 * kernel-only tree).
 */
final class ScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Scheduler')) {
            $this->markTestSkipped('Scheduler is not installed.');
        }
    }

    #[Test]
    public function fluentsSetExpressions(): void
    {
        $schedule = new Schedule();

        $this->assertSame('* * * * *', $schedule->call(static fn (): null => null)->everyMinute()->getExpression());
        $this->assertSame('*/5 * * * *', $schedule->call(static fn (): null => null)->everyFiveMinutes()->getExpression());
        $this->assertSame('*/10 * * * *', $schedule->call(static fn (): null => null)->everyTenMinutes()->getExpression());
        $this->assertSame('*/15 * * * *', $schedule->call(static fn (): null => null)->everyFifteenMinutes()->getExpression());
        $this->assertSame('*/30 * * * *', $schedule->call(static fn (): null => null)->everyThirtyMinutes()->getExpression());
        $this->assertSame('0 * * * *', $schedule->call(static fn (): null => null)->hourly()->getExpression());
        $this->assertSame('0 0 * * *', $schedule->call(static fn (): null => null)->daily()->getExpression());
        $this->assertSame('0 0 * * 0', $schedule->call(static fn (): null => null)->weekly()->getExpression());
        $this->assertSame('0 0 1 * *', $schedule->call(static fn (): null => null)->monthly()->getExpression());
        $this->assertSame('30 2 * * *', $schedule->call(static fn (): null => null)->cron('30 2 * * *')->getExpression());
    }

    #[Test]
    public function dueFilteringAtFixedTime(): void
    {
        // Monday 2026-10-12 14:35.
        $now = new DateTimeImmutable('2026-10-12 14:35:00');
        $schedule = new Schedule();
        $schedule->call(static fn (): string => 'every')->everyMinute();
        $schedule->call(static fn (): string => 'five')->everyFiveMinutes();
        $schedule->call(static fn (): string => 'hourly-no')->hourly();

        $due = array_map(
            static fn ($task): string => $task->run(),
            $schedule->dueTasksAt($now),
        );

        $this->assertSame(['every', 'five'], $due);
    }

    #[Test]
    public function callbackReturnBubbles(): void
    {
        $schedule = new Schedule();
        $task = $schedule->call(static fn (): int => 42);

        $this->assertSame(42, $task->run());
        $this->assertCount(1, $schedule->tasks());
    }

    #[Test]
    public function overlapGuards(): void
    {
        $schedule = new Schedule();
        $task = $schedule->call(static fn (): null => null);

        $this->assertFalse($task->preventsOverlapping());

        $task->description('nightly')->withoutOverlapping(60);

        $this->assertTrue($task->preventsOverlapping());
        $this->assertSame(60, $task->getOverlapExpiresAfterMinutes());
        $this->assertSame('schedule-' . sha1('* * * * *nightly'), $task->mutexName());
    }

    #[Test]
    public function overlapWithoutDescriptionHasNoMutexName(): void
    {
        $schedule = new Schedule();
        $task = $schedule->call(static fn (): null => null)->withoutOverlapping();

        $this->expectException(SchedulerException::class);

        $task->mutexName();
    }

    #[Test]
    public function zeroExpiryRejected(): void
    {
        $schedule = new Schedule();
        $task = $schedule->call(static fn (): null => null);

        $this->expectException(SchedulerException::class);

        $task->withoutOverlapping(0);
    }
}
