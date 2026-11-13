<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Domain\Model;

/**
 * Period — enum for dashboard metric time period selection.
 *
 * Each case provides SQL intervals for querying current and previous
 * periods, enabling trend comparison in metric providers.
 *
 * Usage:
 *   $period = Period::tryFrom('30d');  // Period::THIRTY_DAYS
 *   $period->sqlInterval();            // '-30 days'
 *   $period->previousSqlInterval();    // '-60 days'
 */
enum Period: string
{
    case SEVEN_DAYS = '7d';
    case THIRTY_DAYS = '30d';
    case NINETY_DAYS = '90d';

    /**
     * Human-readable label for UI display.
     */
    public function label(): string
    {
        return match ($this) {
            self::SEVEN_DAYS => '7 days',
            self::THIRTY_DAYS => '30 days',
            self::NINETY_DAYS => '90 days',
        };
    }

    /**
     * Number of days in this period.
     */
    public function days(): int
    {
        return match ($this) {
            self::SEVEN_DAYS => 7,
            self::THIRTY_DAYS => 30,
            self::NINETY_DAYS => 90,
        };
    }

    /**
     * SQLite datetime() interval string for the current period.
     *
     * Example: datetime('now', '-7 days')
     */
    public function sqlInterval(): string
    {
        return match ($this) {
            self::SEVEN_DAYS => '-7 days',
            self::THIRTY_DAYS => '-30 days',
            self::NINETY_DAYS => '-90 days',
        };
    }

    /**
     * SQLite datetime() interval string for the previous period
     * (same length, before the current period).
     *
     * Used for trend comparison: "this period vs. the period before it".
     *
     * Example: for 7d period, returns '-14 days' (the 7 days before the last 7 days).
     */
    public function previousSqlInterval(): string
    {
        return match ($this) {
            self::SEVEN_DAYS => '-14 days',
            self::THIRTY_DAYS => '-60 days',
            self::NINETY_DAYS => '-180 days',
        };
    }
}
