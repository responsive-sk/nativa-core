<?php

declare(strict_types = 1);

namespace Modules\Navigation\Tests;

use Modules\Navigation\Domain\Contracts\NavBadgeContract;
use Modules\Navigation\Infrastructure\Registry\NavBadgeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * NavBadgeRegistryTest — the one rule the admin sidebar depends on.
 *
 * A badge is a work-queue depth, not an unread marker: zero and negative counts
 * render nothing, and a provider that throws is silence rather than a blank
 * admin page.
 */
final class NavBadgeRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Navigation')) {
            $this->markTestSkipped('Navigation is not installed.');
        }
    }

    #[Test]
    public function aPositiveCountIsTheBadge(): void
    {
        self::assertSame(3, NavBadgeRegistry::badgeFrom(self::badge(3)));
    }

    #[Test]
    public function zeroAndNegativeCountsRenderNothing(): void
    {
        self::assertNull(NavBadgeRegistry::badgeFrom(self::badge(0)));
        self::assertNull(NavBadgeRegistry::badgeFrom(self::badge(-1)));
    }

    #[Test]
    public function aProviderThatThrowsRendersNothing(): void
    {
        $provider = new class () implements NavBadgeContract {
            public function navHref(): string
            {
                return '/questions';
            }

            public function count(): int
            {
                throw new \RuntimeException('database is not migrated');
            }
        };

        self::assertNull(NavBadgeRegistry::badgeFrom($provider));
    }

    private static function badge(int $count): NavBadgeContract
    {
        return new class ($count) implements NavBadgeContract {
            public function __construct(private readonly int $value)
            {
            }

            public function navHref(): string
            {
                return '/questions';
            }

            public function count(): int
            {
                return $this->value;
            }
        };
    }
}
