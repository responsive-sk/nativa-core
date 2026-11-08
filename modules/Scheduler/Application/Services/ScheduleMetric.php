<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Application\Services;

use DateTimeImmutable;
use Modules\Cardboard\Domain\Contracts\MetricContract;
use Modules\Cardboard\Domain\Model\Card;
use Modules\Cardboard\Domain\Model\CardLink;
use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Infrastructure\Attributes\AsMetricProvider;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Scheduler\Interfaces\HTTP\SchedulerServices;
use Modules\Scheduler\Scheduling\FileTaskMutex;
use Modules\Scheduler\Scheduling\Schedule;

/**
 * ScheduleMetric — timetable stat card for the admin dashboard.
 *
 * Registers 1 card:
 * 1. Schedule — timetable entries due right now + total entries and
 *    overlap-held locks in the subtitle
 *
 * The card is point-in-time (what WOULD run on this request), so it
 * carries no period trend — trends compare countable history, and the
 * timetable is configuration, not history.
 */
#[AsMetricProvider(priority: 66)]
final readonly class ScheduleMetric implements MetricContract
{
    public function __construct(
        private ?Schedule $schedule = null,
        private ?FileTaskMutex $mutex = null,
    ) {
    }

    public function registerCards(Period $period): void
    {
        try {
            $schedule = $this->schedule ?? SchedulerServices::schedule();
            $mutex = $this->mutex ?? SchedulerServices::mutex();
            $now = new DateTimeImmutable();

            $tasks = $schedule->tasks();
            $due = 0;
            $held = 0;

            foreach ($tasks as $task) {
                if ($task->isDue($now)) {
                    $due++;
                }

                if ($task->preventsOverlapping() && $mutex->exists($task)) {
                    $held++;
                }
            }

            $count = count($tasks);

            CardboardRegistry::register(
                Card::make('schedule', 'Schedule', $due)
                    ->withSubtitle("{$count} entries · {$held} overlap-held")
                    ->withActions(CardLink::make('View Timetable', '/mark/schedule'))
                    ->withPriority(66),
            );
        } catch (\Throwable) {
            // Timetable not readable — skip silently.
            // @mago-expect lint:no-empty-catch-clause
        }
    }
}
