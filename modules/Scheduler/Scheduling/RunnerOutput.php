<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Scheduling;

/**
 * RunnerOutput — the one-method sink the ScheduleRunner reports through.
 *
 * Marko's runner types against `marko/core` Output; we must not import that
 * (module contract: no marko/* imports). The CLI adapts CliOutput to this
 * (writeln → writeLine); tests pass an in-memory collector.
 */
interface RunnerOutput
{
    public function writeLine(string $message): void;
}
