<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Scheduling;

/**
 * ScheduleRunResult — the counts one `schedule:run` pass produced.
 */
final readonly class ScheduleRunResult
{
    public function __construct(
        public int $executed,
        public int $failed,
        public int $skipped,
    ) {
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
