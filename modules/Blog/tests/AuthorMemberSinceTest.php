<?php

declare(strict_types = 1);

namespace Modules\Blog\Tests;

use Modules\Blog\Interfaces\HTTP\Actions\AuthorProfileAction;
use Modules\Cardboard\Interfaces\HTTP\CardboardLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * AuthorMemberSinceTest — `users.created_at` is UTC, "Member since" is the
 * operator's date.
 *
 * The profile used to run `strtotime()` over the column and hand the result to
 * `date()`, which reads a zone-less string as the PROCESS timezone. A member
 * who joined at 23:20 UTC (00:20 CET) therefore showed the previous day. The
 * seam is `CardboardLayout::formatTime()`: UTC on disk, `APP_TIMEZONE` on screen.
 *
 * `AuthorProfileAction::memberSince()` is private, so the test reaches it via
 * reflection with a constructor-less instance (the router instantiates actions
 * without arguments, and the method only formats its argument). Like every
 * module test it drives the INSTALLED copy and skips when Blog is not
 * installed.
 */
final class AuthorMemberSinceTest extends TestCase
{
    /** @var callable(string): string */
    private $memberSince;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Blog')) {
            $this->markTestSkipped('Blog is not installed.');
        }

        $method = new ReflectionMethod(AuthorProfileAction::class, 'memberSince');
        $action = (new ReflectionClass(AuthorProfileAction::class))->newInstanceWithoutConstructor();

        $this->memberSince = static fn (string $createdAt): string => (string) $method->invoke($action, $createdAt);
    }

    #[Test]
    public function theStoredStampIsShownAsTheDisplayDate(): void
    {
        $expected = (new \DateTimeImmutable('2026-11-16 23:20:47', new \DateTimeZone('UTC')))
            ->setTimezone(CardboardLayout::displayTimezone())
            ->format('Y-m-d');

        self::assertSame('Member since ' . $expected, ($this->memberSince)('2026-11-16 23:20:47'));
    }

    #[Test]
    public function theProcessTimezoneDoesNotMoveTheDate(): void
    {
        $before = ($this->memberSince)('2026-11-16 23:20:47');
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati'); // UTC+14

        try {
            self::assertSame(
                $before,
                ($this->memberSince)('2026-11-16 23:20:47'),
                'the column is UTC on disk; the process clock must not shift the day',
            );
        } finally {
            date_default_timezone_set($previous);
        }
    }

    #[Test]
    public function anAbsentStampIsEmptyRatherThanADanglingLabel(): void
    {
        self::assertSame('', ($this->memberSince)(''));
    }

    /**
     * PostgreSQL reads `TIMESTAMPTZ` back with the offset attached. The date
     * must stay the same instant, not be read as a second wall clock.
     */
    #[Test]
    #[DataProvider('timestamptzShapes')]
    public function aTimestamptzStampKeepsTheSameInstant(string $stored): void
    {
        $plain = ($this->memberSince)('2026-11-16 23:20:47');

        self::assertSame(
            $plain,
            ($this->memberSince)($stored),
            'a TIMESTAMPTZ offset names the instant already in the column; the reader must not add it again',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function timestamptzShapes(): iterable
    {
        yield 'utc offset in hours' => ['2026-11-16 23:20:47+00'];
        yield 'central european offset' => ['2026-11-17 00:20:47+01:00'];
        yield 'negative offset' => ['2026-11-16 18:20:47-05'];
        yield 'utc offset with minutes' => ['2026-11-16 23:20:47+00:00'];
    }
}
