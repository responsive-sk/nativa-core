<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Tests;

use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Scheduler\Application\Services\ScheduleMetric;
use Modules\Scheduler\Scheduling\FileTaskMutex;
use Modules\Scheduler\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ScheduleMetricTest — beside the code it covers (module test contract:
 * drives the INSTALLED copy, skips on a kernel-only tree).
 *
 * A synthetic timetable (never the shipped one) plus a throwaway mutex
 * dir — the real outbox closures never run, `storage/` is never touched.
 */
final class ScheduleMetricTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Scheduler') || !class_exists(CardboardRegistry::class)) {
            $this->markTestSkipped('Scheduler (or Cardboard) is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/scheduler-metric-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0o775, true);

        CardboardRegistry::reset();
    }

    protected function tearDown(): void
    {
        if (class_exists(CardboardRegistry::class)) {
            CardboardRegistry::reset();
        }

        if (isset($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->dir);
        }

        parent::tearDown();
    }

    #[Test]
    public function registersScheduleCardForSyntheticTimetable(): void
    {
        $schedule = new Schedule();
        $schedule->call(static fn (): null => null)->everyMinute()->description('due-now');
        // "Feb 30" — an expression no clock reaches, so the due count cannot
        // drift with the wall time. `daily()` reads innocent but is due at
        // 00:xx, where it turns the card's value into a third entry and moves
        // the assertion below; a fixture must not depend on when it runs.
        $schedule->call(static fn (): null => null)->cron('0 0 30 2 *')->description('later');
        $guarded = $schedule->call(static fn (): null => null)
            ->everyMinute()->description('guarded')->withoutOverlapping(60);

        $mutex = new FileTaskMutex($this->dir);
        // Another process holds the guarded task's lock.
        $this->assertTrue($mutex->acquire($guarded, 3600));

        (new ScheduleMetric($schedule, new FileTaskMutex($this->dir)))
            ->registerCards(Period::SEVEN_DAYS);

        $mutex->release($guarded);

        $cards = CardboardRegistry::all();
        $this->assertCount(1, $cards);

        $card = $cards[0];
        $this->assertSame('schedule', $card->id);
        $this->assertSame('/mark/schedule', $card->actions[0]->href ?? null);
        // Both everyMinute entries are due at any wall time: the overlap
        // lock moves the guarded one into the subtitle's "1 overlap-held",
        // it does not drop it from the due count (ScheduleMetric counts
        // `isDue` and `held` independently). The third entry is never due,
        // so the value is exactly 2 — whenever the suite runs.
        $this->assertSame(2, $card->value);
        $this->assertSame('3 entries · 1 overlap-held', $card->subtitle);
        $this->assertNull($card->trend);
    }

    #[Test]
    public function emptyTimetableRegistersZeroCard(): void
    {
        (new ScheduleMetric(new Schedule(), new FileTaskMutex($this->dir)))
            ->registerCards(Period::SEVEN_DAYS);

        $cards = CardboardRegistry::all();
        $this->assertCount(1, $cards);
        $this->assertSame(0, $cards[0]->value);
        $this->assertSame('0 entries · 0 overlap-held', $cards[0]->subtitle);
    }
}
