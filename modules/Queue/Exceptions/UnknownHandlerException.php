<?php

declare(strict_types = 1);

namespace Modules\Queue\Exceptions;

/**
 * UnknownHandlerException — no callable is registered under this name.
 *
 * The worker catches it like any handler failure: the job is retried to
 * max_attempts and then parked in failed_jobs with this message. An
 * unroutable job is data, never a fatal.
 */
final class UnknownHandlerException extends QueueException
{
    public static function for(string $name): self
    {
        return new self(sprintf('No queue handler registered under "%s".', $name));
    }
}
