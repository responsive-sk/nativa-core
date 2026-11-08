<?php

declare(strict_types = 1);

namespace Tests\Theme;

use Modules\Core\Interfaces\HTTP\ShellContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ShellContextThemeSettingsTest — the shell context is where a theme reads its
 * own settings, and it must stay optional.
 *
 * Every existing theme test builds a context with the eight arguments the
 * factory has always taken. If the new map were a required argument, adding
 * settings to ONE theme would have broken the fixtures of all of them —
 * including the themes that have no settings at all. `from()` therefore keeps
 * the old shape working and defaults the map to `[]`.
 */
final class ShellContextThemeSettingsTest extends TestCase
{
    #[Test]
    public function aContextBuiltTheOldWayCarriesNoThemeSettings(): void
    {
        $context = ShellContext::from('Site', [], [], '/', null, 'sk', [], false);

        self::assertSame(
            [],
            $context->themeSettings,
            'A theme without settings must keep rendering from the old factory signature.',
        );
    }

    #[Test]
    public function aContextCarriesTheResolvedValues(): void
    {
        $context = ShellContext::from(
            'Site',
            [],
            [],
            '/',
            null,
            'sk',
            [],
            false,
            ['nav_toggle_icon' => 'meatball'],
        );

        self::assertSame(['nav_toggle_icon' => 'meatball'], $context->themeSettings);
    }
}
