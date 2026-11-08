<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Scheduling;

use Closure;
use DateTimeInterface;
use Modules\Scheduler\Scheduling\Exceptions\SchedulerException;

/**
 * One scheduled closure with a fluent cadence (ported from Marko's
 * `scheduler` package): `Schedule::call(fn () => ...)->hourly()`.
 *
 * Overlap protection keys its mutex on the description — closures have no
 * stable identity — so `withoutOverlapping()` requires `description()`.
 */
final class ScheduledTask
{
    private string $expression = '* * * * *';

    private ?string $description = null;

    private ?int $overlapExpiresAfterMinutes = null;

    public function __construct(
        private readonly Closure $callback,
    ) {
    }

    public function everyMinute(): self
    {
        $this->expression = '* * * * *';

        return $this;
    }

    public function everyFiveMinutes(): self
    {
        $this->expression = '*/5 * * * *';

        return $this;
    }

    public function everyTenMinutes(): self
    {
        $this->expression = '*/10 * * * *';

        return $this;
    }

    public function everyFifteenMinutes(): self
    {
        $this->expression = '*/15 * * * *';

        return $this;
    }

    public function everyThirtyMinutes(): self
    {
        $this->expression = '*/30 * * * *';

        return $this;
    }

    public function hourly(): self
    {
        $this->expression = '0 * * * *';

        return $this;
    }

    public function daily(): self
    {
        $this->expression = '0 0 * * *';

        return $this;
    }

    public function weekly(): self
    {
        $this->expression = '0 0 * * 0';

        return $this;
    }

    public function monthly(): self
    {
        $this->expression = '0 0 1 * *';

        return $this;
    }

    public function cron(string $expression): self
    {
        $this->expression = $expression;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Skip this task while a previous run is still in progress.
     *
     * A held mutex older than $expiresAfterMinutes is treated as stale (its
     * holder hung or crashed) and is reclaimed.
     *
     * @throws SchedulerException when $expiresAfterMinutes is below 1
     */
    public function withoutOverlapping(int $expiresAfterMinutes = 1440): self
    {
        if ($expiresAfterMinutes < 1) {
            throw SchedulerException::invalidOverlapExpiry($expiresAfterMinutes);
        }

        $this->overlapExpiresAfterMinutes = $expiresAfterMinutes;

        return $this;
    }

    public function preventsOverlapping(): bool
    {
        return null !== $this->overlapExpiresAfterMinutes;
    }

    public function getOverlapExpiresAfterMinutes(): ?int
    {
        return $this->overlapExpiresAfterMinutes;
    }

    /**
     * Stable identity used to key the overlap mutex.
     *
     * @throws SchedulerException when the task has no description
     */
    public function mutexName(): string
    {
        if (null === $this->description) {
            throw SchedulerException::overlapRequiresDescription($this->expression);
        }

        return 'schedule-' . sha1($this->expression . $this->description);
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function getCallback(): Closure
    {
        return $this->callback;
    }

    public function isDue(DateTimeInterface $now): bool
    {
        return CronExpression::matches($this->expression, $now);
    }

    public function run(): mixed
    {
        return ($this->callback)();
    }
}
