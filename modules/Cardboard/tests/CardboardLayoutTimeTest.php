<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Interfaces\HTTP\CardboardLayout;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CardboardLayoutTimeTest — storage is UTC, the panel speaks APP_TIMEZONE.
 *
 * Every writer stamps `gmdate()`, so a panel that formats the column directly
 * shows the UTC wall clock. These tests pin the two halves of the read side:
 * a zone-less storage string is parsed AS UTC (not as the process timezone),
 * and the result is rendered in `CardboardLayout::displayTimezone()`.
 *
 * The process default is moved to UTC+14 inside the first test on purpose:
 * when the parser treated the column as local, that clock was nearly a day
 * away from the stored instant, which is exactly the bug.
 *
 * Drives the INSTALLED copy, skips on a kernel-only tree.
 */
final class CardboardLayoutTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Cardboard')) {
            $this->markTestSkipped('Cardboard is not installed.');
        }
    }

    #[Test]
    public function aStoredStampIsReadAsUtcAndPresentedInTheAppTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati'); // UTC+14

        try {
            $instant = CardboardLayout::storedTime('2026-11-17 10:00:00');

            self::assertNotNull($instant);
            self::assertSame('UTC', $instant->getTimezone()->getName(), 'a zone-less column is UTC, not the process timezone');
            self::assertSame(gmmktime(10, 0, 0, 11, 17, 2026), $instant->getTimestamp());

            $expected = (new \DateTimeImmutable('2026-11-17 10:00:00', new \DateTimeZone('UTC')))
                ->setTimezone(CardboardLayout::displayTimezone())
                ->format('Y-m-d H:i:s');

            self::assertSame($expected, CardboardLayout::formatTime('2026-11-17 10:00:00'));

            // The same instant with an explicit offset must land on the same clock.
            self::assertSame($expected, CardboardLayout::formatTime('2026-11-17T10:00:00+00:00'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    #[Test]
    public function theTimeElementCarriesTheDisplayZone(): void
    {
        $expected = (new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC')))
            ->setTimezone(CardboardLayout::displayTimezone())
            ->format('c');

        self::assertStringContainsString(
            'datetime="' . $expected . '"',
            CardboardLayout::relativeTime('2026-01-01 10:00:00'),
            'the machine-readable value must name the instant in APP_TIMEZONE',
        );
    }

    #[Test]
    public function anAbsentTimestampIsEmptyRatherThanNow(): void
    {
        self::assertSame('', CardboardLayout::formatTime(null));
        self::assertSame('', CardboardLayout::formatTime(''));
    }
}
