<?php

declare(strict_types=1);

namespace Modules\Locales\Tests;

use Modules\Locales\Domain\Model\Locale;
use Modules\Locales\Domain\Model\Translation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TranslationTimestampTest — the two clocks a translation row used to have.
 *
 * `translations.created_at` and `updated_at` default to SQLite's UTC
 * `CURRENT_TIMESTAMP`, and the migrator seeds through that default, but the
 * entity stamped admin writes with PHP's process timezone (`APP_TIMEZONE`,
 * `Europe/Bratislava` by default). A seeded row and an admin-created row in the
 * same table therefore sat two hours apart, and a timezone change moved every
 * admin row relative to its neighbours.
 *
 * The tests run under `Pacific/Kiritimati` (UTC+14) and restore the process
 * timezone afterwards: a local `date()` is fourteen hours from UTC, so the
 * window below fails on the bug and not on clock skew.
 *
 * Like every module test it skips when the module is not installed.
 */
final class TranslationTimestampTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Locales')) {
            $this->markTestSkipped('Locales is not installed.');
        }
    }

    #[Test]
    public function aNewTranslationIsStampedInUtcNotInTheProcessTimezone(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');

        try {
            $translation = Translation::create('nav.home', Locale::fromString('sk_SK'), 'Domov', 'nav');
            $row = $translation->toArray();

            $this->assertUtcNow($row['created_at'], 'created_at');
            $this->assertUtcNow($row['updated_at'], 'updated_at');

            $translation->updateValue('Domov SK');
            $translation->updateGroup('navigation');

            $this->assertUtcNow($translation->toArray()['updated_at'], 'updated_at after an edit');
        } finally {
            date_default_timezone_set($original);
        }
    }

    #[Test]
    public function aStoredRowIsReadBackAsUtc(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');

        try {
            $translation = Translation::fromArray([
                'id'              => 'abc',
                'translation_key' => 'nav.home',
                'locale'          => 'sk_SK',
                'value'           => 'Domov',
                'group_name'      => 'nav',
                'created_at'      => '2026-11-13 10:00:00',
                'updated_at'      => '2026-11-13 10:00:00',
            ]);

            self::assertSame(
                'UTC',
                $translation->createdAt()->getTimezone()->getName(),
                'a stored row was hydrated in the process timezone — the column is UTC',
            );
            self::assertSame('2026-11-13 10:00:00', $translation->toArray()['created_at']);
        } finally {
            date_default_timezone_set($original);
        }
    }

    private function assertUtcNow(string $stamp, string $what): void
    {
        $parsed = (int) strtotime($stamp . ' UTC');

        self::assertGreaterThan(0, $parsed, sprintf('%s "%s" is not a parseable timestamp', $what, $stamp));
        self::assertLessThanOrEqual(
            5,
            abs(time() - $parsed),
            sprintf('%s "%s" is not UTC now — the row was stamped with the process timezone', $what, $stamp),
        );
    }
}
