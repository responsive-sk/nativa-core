<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Tests;

use DateTimeImmutable;
use Modules\Scheduler\Scheduling\CronExpression;
use Modules\Scheduler\Scheduling\Exceptions\InvalidCronExpressionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CronExpressionTest — the cron matrix beside the code it covers (module
 * test contract: drives the INSTALLED copy, skips on a kernel-only tree).
 *
 * Monday 2026-10-12 14:35 is the fixed probe instant (dow 1, month 10).
 */
final class CronExpressionTest extends TestCase
{
    private const PROBE = '2026-10-12 14:35:00';

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Scheduler')) {
            $this->markTestSkipped('Scheduler is not installed.');
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function expressions(): iterable
    {
        yield 'every minute' => ['* * * * *', true];
        yield 'exact minute' => ['35 * * * *', true];
        yield 'other minute' => ['36 * * * *', false];
        yield 'exact hour' => ['* 14 * * *', true];
        yield 'other hour' => ['* 15 * * *', false];
        yield 'step minutes' => ['*/5 * * * *', true];
        yield 'step mismatch' => ['*/6 * * * *', false];
        yield 'range' => ['30-40 * * * *', true];
        yield 'range miss' => ['0-30 * * * *', false];
        yield 'range step' => ['30-40/5 * * * *', true];
        yield 'list hit' => ['5,35,50 * * * *', true];
        yield 'list miss' => ['5,36,50 * * * *', false];
        yield 'day of month' => ['* * 12 * *', true];
        yield 'month' => ['* * * 10 *', true];
        yield 'other month' => ['* * * 11 *', false];
        yield 'day of week' => ['* * * * 1', true];
        yield 'other dow' => ['* * * * 2', false];
        yield 'sunday alias 7' => ['* * * * 7', false];
        yield 'dom-or-dow first hits' => ['* * 12 * 2', true];
        yield 'dom-or-dow second hits' => ['* * 13 * 1', true];
        yield 'dom-or-dow neither' => ['* * 13 * 2', false];
    }

    #[Test]
    #[DataProvider('expressions')]
    public function matrix(string $expression, bool $expected): void
    {
        $this->assertSame(
            $expected,
            CronExpression::matches($expression, new DateTimeImmutable(self::PROBE)),
        );
    }

    #[Test]
    public function wrongFieldCountThrows(): void
    {
        $this->expectException(InvalidCronExpressionException::class);

        CronExpression::matches('* * * *', new DateTimeImmutable(self::PROBE));
    }

    #[Test]
    public function unparseableFieldThrows(): void
    {
        $this->expectException(InvalidCronExpressionException::class);

        CronExpression::matches('abc * * * *', new DateTimeImmutable(self::PROBE));
    }
}
