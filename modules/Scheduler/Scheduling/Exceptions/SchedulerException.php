<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Scheduling\Exceptions;

use RuntimeException;

/**
 * Scheduler failures with actionable messages (ported from Marko's
 * `scheduler` package; the `MarkoException` context/suggestion shape is
 * folded into the message — this tree has no Marko base exception).
 */
class SchedulerException extends RuntimeException
{
    public static function overlapRequiresDescription(string $expression): self
    {
        return new self(
            "A scheduled task with expression '{$expression}' uses withoutOverlapping() but has no "
            . "description. Closures have no stable identity, so overlap protection keys the mutex on "
            . "the task's description — add ->description('Unique task name') to the task.",
        );
    }

    public static function invalidOverlapExpiry(int $expiresAfterMinutes): self
    {
        return new self(
            "withoutOverlapping() expiry must be at least 1 minute, {$expiresAfterMinutes} given. "
            . 'Pass the number of minutes after which a held mutex is considered stale, e.g. '
            . '->withoutOverlapping(60). The default is 1440 (24 hours).',
        );
    }

    public static function mutexDirectoryNotWritable(string $directory): self
    {
        return new self(
            "Scheduler mutex directory '{$directory}' could not be created or is not writable. "
            . 'Create the directory and make it writable by the user running the scheduler.',
        );
    }

    public static function mutexFileNotOpenable(string $path): self
    {
        return new self(
            "Scheduler mutex file '{$path}' could not be opened. "
            . 'Check the permissions of the mutex directory and file for the user running the scheduler.',
        );
    }
}
