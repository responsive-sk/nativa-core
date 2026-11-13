<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Interfaces\HTTP\Actions\SettingsAction;
use Themes\ThemeRegistry;
use Themes\ThemeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SettingsThemeSelectTest — the rule the admin theme `<select>` and the
 * `saveSite()` validation share.
 *
 * The form and the save path must not drift apart: a theme the admin can pick
 * but the save path rejects looks like a save that silently did nothing. Both
 * halves read `SettingsAction::selectableThemes()`, and this file pins that
 * contract — kernel whitelist first, every discovered module-carried theme
 * after it, no duplicates — plus the acceptance rule `saveSite()` enforces.
 */
final class SettingsThemeSelectTest extends TestCase
{
    private const string MODULE = 'Cardboard';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the settings form ships with this module.');
        }
    }

    #[Test]
    public function theKernelThemeLeadsTheSelectableSet(): void
    {
        $selectable = SettingsAction::selectableThemes();

        $this->assertContains(ThemeResolver::KERNEL_THEME, $selectable);
        $this->assertSame(
            ThemeResolver::KERNEL_THEME,
            $selectable[0],
            'The kernel theme stays first; discovered themes append after it.',
        );
    }

    #[Test]
    public function everyDiscoveredThemeIsSelectableExactlyOnce(): void
    {
        $selectable = SettingsAction::selectableThemes();

        foreach (array_keys(ThemeRegistry::all()) as $name) {
            $this->assertContains(
                $name,
                $selectable,
                sprintf('Discovered theme `%s` is missing from the admin select.', $name),
            );
        }

        $this->assertSame(
            count($selectable),
            count(array_unique($selectable)),
            'A theme listed twice would render two identical options.',
        );
    }

    #[Test]
    public function theAcceptanceRuleTakesKnownThemesAndRejectsGarbage(): void
    {
        $this->assertTrue(SettingsAction::isSelectableTheme(ThemeResolver::KERNEL_THEME));

        foreach (array_keys(ThemeRegistry::all()) as $name) {
            $this->assertTrue(
                SettingsAction::isSelectableTheme($name),
                sprintf('Discovered theme `%s` must survive the save validation.', $name),
            );
        }

        $this->assertFalse(SettingsAction::isSelectableTheme('no-such-theme'));
        $this->assertFalse(SettingsAction::isSelectableTheme(''));
    }
}
