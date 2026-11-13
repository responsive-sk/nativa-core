<?php

declare(strict_types = 1);

namespace Themes;

/**
 * Theme settings contract — an OPTIONAL half of `ThemeModule`.
 *
 * A theme has settings only when it says so: this interface is separate from
 * `ThemeModule` on purpose, so the themes that own no choices (the kernel
 * fallback, the docs reader surface) implement nothing extra and keep working
 * byte-for-byte. A theme that adds it gets a block in the admin's Design tab
 * and its values handed back through `ShellContext::$themeSettings` — the
 * theme never reaches for the settings table itself.
 *
 * The schema is the single owner of three answers the admin form and the
 * renderer must agree on: which keys exist, which values are legal, and what
 * the value is when nothing is stored. A field the schema does not name is
 * dropped on the way in AND on the way out, so renaming a key retires the old
 * stored row instead of leaking it into markup.
 */
interface ThemeSettings
{
    /**
     * The theme's own settings, in display order.
     *
     * Keys are theme-local (`nav_toggle_icon`), not storage keys — the
     * `theme.{name}.` prefix belongs to `ThemeSettingValues`, which is what
     * keeps two themes from colliding on the same field name.
     *
     * @return list<ThemeSetting>
     */
    public function settingsSchema(): array;
}
