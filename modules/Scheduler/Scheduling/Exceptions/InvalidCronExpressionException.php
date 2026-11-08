<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Scheduling\Exceptions;

/**
 * A cron expression that cannot be parsed (ported from Marko's `scheduler`
 * package; message text kept, `MarkoException` base dropped).
 */
final class InvalidCronExpressionException extends SchedulerException
{
    public static function wrongFieldCount(string $expression, int $actual): self
    {
        return new self(
            "Cron expression '{$expression}' has {$actual} field(s) but exactly 5 are required. "
            . 'A valid cron expression has five space-separated fields: minute hour day-of-month '
            . 'month day-of-week (e.g. "0 * * * *").',
        );
    }

    public static function unparseableField(string $field, string $expression): self
    {
        return new self(
            "Cron expression field '{$field}' in '{$expression}' cannot be parsed. "
            . 'Each field must be *, a number, a range (a-b), a step (*/n or a-b/n), '
            . 'or a comma-separated list of those forms.',
        );
    }
}
