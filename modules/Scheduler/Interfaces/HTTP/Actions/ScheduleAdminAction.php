<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Interfaces\HTTP\Actions;

use DateTimeImmutable;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Scheduler\Interfaces\HTTP\SchedulerServices;

/**
 * Schedule admin action — timetable browser at /mark/schedule.
 *
 * The /mark prefix is already protected by AuthMiddleware (admin role
 * required). Read-only: one row per shipped timetable entry with its
 * cron expression, live due-now state and overlap locks. There is no
 * next-run column — CronExpression is a matcher, not a solver, and
 * brute-forcing unbounded minutes forward is not an admin page.
 */
#[Route(path: '/mark/schedule', method: 'GET', name: 'mark.schedule')]
final class ScheduleAdminAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $schedule = SchedulerServices::schedule();
        $mutex = SchedulerServices::mutex();
        $now = new DateTimeImmutable();

        $tasks = $schedule->tasks();

        $summary = '<p class="m-muted">' . count($tasks) . ' entries · checked at '
            . AdminLayout::e($now->format('Y-m-d H:i:s')) . '</p>';

        if ([] === $tasks) {
            $content = $summary . '<p class="m-muted">No timetable entries.</p>';
        } else {
            $rows = '';

            foreach ($tasks as $task) {
                $description = $task->getDescription() ?? $task->getExpression();
                $due = $task->isDue($now);
                $protected = $task->preventsOverlapping();
                $held = $protected && $mutex->exists($task);

                $rows .= '<tr>'
                    . '<td>' . AdminLayout::e($description) . '</td>'
                    . '<td class="m-mono">' . AdminLayout::e($task->getExpression()) . '</td>'
                    . '<td>' . $this->badge($due ? 'due now' : 'waiting', $due ? ' m-badge-success' : '') . '</td>'
                    . '<td>' . $this->overlapCell($protected, $held) . '</td>'
                    . '</tr>';
            }

            $content = $summary
                . '<table class="m-table"><thead><tr>'
                . '<th>Entry</th><th>Schedule</th><th>State</th><th>Overlap</th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table>'
                . '<p class="m-muted">Runs via <span class="m-mono">php bin/cms schedule:run</span> '
                . '(system cron, every minute). Edit the timetable in code — this page only reads it.</p>';
        }

        return Response::html(AdminLayout::render('Schedule', $content, 'schedule', router: $this->router()));
    }

    private function badge(string $label, string $modifier): string
    {
        return '<span class="m-badge' . $modifier . '">' . AdminLayout::e($label) . '</span>';
    }

    private function overlapCell(bool $protected, bool $held): string
    {
        if (!$protected) {
            return '<span class="m-muted">—</span>';
        }

        return $held
            ? $this->badge('held (running)', ' m-badge-error')
            : $this->badge('protected', '');
    }
}
