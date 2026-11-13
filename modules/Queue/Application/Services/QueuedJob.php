<?php

declare(strict_types = 1);

namespace Modules\Queue\Application\Services;

/**
 * QueuedJob — one reserved row from the jobs table.
 *
 * `attempts` already includes the current reservation (the reserve
 * increments it), so the worker fails the job when
 * attempts >= maxAttempts.
 */
final readonly class QueuedJob
{
    /**
     * @param array<string, mixed> $args
     */
    public function __construct(
        public string $id,
        public string $queue,
        public string $handler,
        public array $args,
        public int $attempts,
        public int $maxAttempts,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        /** @var array<string, mixed> $args */
        $args = json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR);

        return new self(
            id: (string) $row['id'],
            queue: (string) $row['queue'],
            handler: (string) $row['handler'],
            args: $args,
            attempts: (int) $row['attempts'],
            maxAttempts: (int) $row['max_attempts'],
        );
    }
}
