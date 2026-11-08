<?php

declare(strict_types = 1);

namespace Tests\Theme;

use Modules\Core\Domain\ThemeSetting;
use Modules\Core\Domain\ThemeSettingValues;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Themes\ThemeSettings;

/**
 * ThemeSettingValuesTest — the rule that turns stored rows into the values a
 * theme may render.
 *
 * Two failure modes this pins, both measured as "setting saved, page
 * unchanged":
 *
 * 1. a value the theme's schema no longer accepts reaching markup it was never
 *    designed for (a renamed key leaves its old row behind), and
 * 2. two themes sharing a value because the storage key lost its theme part.
 *
 * Pure fixture themes on purpose: no database, no registry, no module — the
 * rule is the kernel's, and a kernel that needs an installed theme to test its
 * own resolution rule cannot be tested in a kernel-only checkout.
 */
final class ThemeSettingValuesTest extends TestCase
{
    #[Test]
    public function aMissingRowRendersTheSchemaDefault(): void
    {
        $theme = self::theme(self::iconField());

        self::assertSame(['nav_toggle_icon' => 'auto'], ThemeSettingValues::resolve($theme, []));
    }

    #[Test]
    public function aStoredValueTheSchemaAcceptsWins(): void
    {
        $theme = self::theme(self::iconField());

        self::assertSame(
            ['nav_toggle_icon' => 'meatball'],
            ThemeSettingValues::resolve($theme, ['nav_toggle_icon' => 'meatball']),
        );
    }

    #[Test]
    public function aStoredValueTheSchemaRejectsFallsBackToTheDefault(): void
    {
        $theme = self::theme(self::iconField());

        self::assertSame(
            ['nav_toggle_icon' => 'auto'],
            ThemeSettingValues::resolve($theme, ['nav_toggle_icon' => 'hamburger']),
            'A value the form could not have offered must never reach the markup.',
        );
    }

    #[Test]
    public function keysOutsideTheSchemaNeverReachTheTheme(): void
    {
        $theme = self::theme(self::iconField());

        self::assertSame(
            ['nav_toggle_icon' => 'burger'],
            ThemeSettingValues::resolve(
                $theme,
                ['nav_toggle_icon' => 'burger', 'retired_field' => 'old'],
            ),
            'A retired field keeps its row in the table; it must not keep rendering.',
        );
    }

    #[Test]
    public function eachThemeOwnsItsOwnRow(): void
    {
        $field = self::iconField();

        // Same field name, two themes, one table — the theme is part of the key.
        $first = ThemeSettingValues::storageKey(ThemeResolver::KERNEL_THEME, 'nav_toggle_icon');
        $second = ThemeSettingValues::storageKey('drifted-theme', 'nav_toggle_icon');

        self::assertNotSame($first, $second);
        self::assertSame('theme.kernel.nav_toggle_icon', $first);
        self::assertSame('nav_toggle_icon', ThemeSettingValues::fieldFromStorageKey(ThemeResolver::KERNEL_THEME, $first));
        self::assertNull(
            ThemeSettingValues::fieldFromStorageKey(ThemeResolver::KERNEL_THEME, $second),
            'One theme must not read the rows another one wrote.',
        );

        self::assertSame(
            ['nav_toggle_icon' => 'meatball'],
            ThemeSettingValues::resolve(self::theme($field), ['nav_toggle_icon' => 'meatball']),
        );
        self::assertSame(
            ['nav_toggle_icon' => 'auto'],
            ThemeSettingValues::resolve(self::theme($field), []),
            'The other theme has no stored row, so it keeps its own default.',
        );
    }

    #[Test]
    public function aThemeWithoutTheContractRendersNothing(): void
    {
        self::assertSame(
            [],
            ThemeSettingValues::resolve(null, ['nav_toggle_icon' => 'meatball']),
            'No schema means nothing can be validated, so nothing may be handed over.',
        );
    }

    #[Test]
    public function sanitizeReplacesRejectedInputWithTheDefault(): void
    {
        $theme = self::theme(self::iconField());

        self::assertSame('meatball', ThemeSettingValues::sanitize($theme, 'nav_toggle_icon', ' meatball '));
        self::assertSame('auto', ThemeSettingValues::sanitize($theme, 'nav_toggle_icon', 'banana'));
    }

    #[Test]
    public function sanitizeRefusesFieldsTheSchemaDoesNotDeclare(): void
    {
        self::assertNull(ThemeSettingValues::sanitize(self::theme(self::iconField()), 'nope', 'x'));
        self::assertNull(ThemeSettingValues::sanitize(null, 'nav_toggle_icon', 'meatball'));
    }

    #[Test]
    public function textFieldsAcceptTrimmedTextAndRejectBlanks(): void
    {
        $theme = self::theme(ThemeSetting::text('tagline', 'Tagline', 'Default tagline'));

        self::assertSame(
            ['tagline' => 'Written by hand'],
            ThemeSettingValues::resolve($theme, ['tagline' => '  Written by hand  ']),
        );
        self::assertSame(
            ['tagline' => 'Default tagline'],
            ThemeSettingValues::resolve($theme, ['tagline' => '   ']),
            'A blank row is absent, not an empty tagline.',
        );
    }

    #[Test]
    public function aSchemaWithADuplicateKeyKeepsTheFirstField(): void
    {
        $theme = self::theme(
            ThemeSetting::select('nav_toggle_icon', 'Menu icon', ['auto' => 'Auto', 'burger' => 'Burger'], 'auto'),
            ThemeSetting::select('nav_toggle_icon', 'Second icon', ['auto' => 'Auto', 'meatball' => 'Meatball'], 'meatball'),
        );

        self::assertSame(
            ['nav_toggle_icon' => 'auto'],
            ThemeSettingValues::resolve($theme, []),
            'Two fields writing one key would render the second choice and store the first.',
        );
    }

    private static function iconField(): ThemeSetting
    {
        return ThemeSetting::select(
            'nav_toggle_icon',
            'Menu icon',
            ['auto' => 'Auto', 'burger' => 'Burger', 'meatball' => 'Meatball'],
            'auto',
        );
    }

    private static function theme(ThemeSetting ...$fields): ThemeSettings
    {
        return new class (array_values($fields)) implements ThemeSettings {
            /** @param list<ThemeSetting> $fields */
            public function __construct(private readonly array $fields)
            {
            }

            /** @return list<ThemeSetting> */
            public function settingsSchema(): array
            {
                return $this->fields;
            }
        };
    }
}
