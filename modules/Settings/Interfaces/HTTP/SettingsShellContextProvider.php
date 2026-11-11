<?php

declare(strict_types = 1);

namespace Modules\Settings\Interfaces\HTTP;

use Modules\Core\Domain\AdminRoutePolicy;
use Shell\ShellContextProvider;
use Shell\ShellNavItem;
use Modules\Layout\ThemePreference;
use Modules\Layout\ThemeSettingValues;
use Shell\AsShellContextProvider;
use Modules\Layout\ThemeRegistry;
use Modules\Layout\ThemeResolver;
use Modules\Settings\Application\DTOs\MenuItemDTO;

/**
 * SettingsShellContextProvider — the MENU half of the shell port, answered by
 * the module that owns the `menu_items` table.
 *
 * The port exists because the kernel used to reach for `SettingsServices` to
 * build the header and footer nav; navigation moved behind the port and nothing
 * answered it, so every public page rendered a shell with no menu at all. Not
 * an error, not a log line — a navbar with a brand and nothing to click.
 * Measured on the Mono theme, whose navbar has no links of its own to fall back
 * on: `mo-navbar__menu` was absent from the document entirely.
 *
 * Besides `navigation()` this provider also answers the stored admin choices
 * nothing else in the tree says: the site name, the public `theme()` and the
 * default locale (`defaultLocale()` + `htmlLang()`). The name belongs here
 * because `settings.site_name` is this module's row and the admin's General
 * screen is the only thing that writes it — while every public surface asks
 * the port. With no answer, a site renamed to `responsive.sk` still shipped
 * `<title>Nativa PHP Core</title>`, the kernel's literal, on every page: the
 * stored name reached the admin chrome and nowhere else.
 *
 * ## What a visitor may see
 *
 * The seeded menu puts `Admin` (→ `/mark`) in the header location, and an
 * anonymous reader has no business being pointed at the admin surface — a link
 * that answers 302-to-login is a dead end dressed as navigation. Items whose
 * href is the admin surface are therefore dropped unless the visitor is
 * signed in; signed-in editors keep them, which is the whole point of the item.
 * A nested item is filtered by the same rule, one level down.
 *
 * "Signed in" is read from `$_SESSION['auth']['user_id']` — the exact key the
 * kernel's own shell chrome reads (`FrontendLayout::sessionHasAuth()`) and the
 * module that owns sessions writes (`AuthService`). The auth PORT is not asked
 * because its `identity()` takes a `Request` and this port carries none: rather
 * than invent a second way to ask, this asks the same question the kernel
 * already answers for itself, with the same guard — no session cookie, no
 * session started. Crucially that guard is kept: a public page must not hand a
 * session to a reader who never logged in.
 *
 * The admin surface is not spelled here at all: `AdminRoutePolicy::isAdminPath()`
 * is the one owner, reading the optional `ADMIN_PATH` prefix with `/mark` as
 * the default, so a deployment that moves the panel moves this filter with it.
 *
 * ## The rest
 *
 * The slot answer comes from `MenuService::menu()`, which already filters
 * `is_active`, nests children (three levels, capped there) and marks the item
 * under the current path active — what makes `mo-navbar__link--active` light up
 * on the page you are actually reading. A slot with no visible rows answers
 * `[]`, the port's own "no menu" answer. A provider that THROWS is not caught
 * (see `ShellContextProviderRegistry`): a table the deployment has not migrated
 * is a broken deployment, and a menu that silently disappears is one its owner
 * cannot see. `bin/cms migrate` — or the first request after a fresh checkout —
 * is what creates the table.
 */
// Priority -100 ("lower sorts first"): the stored theme vote must sort before
// the theme fixtures' votes (`Mono`/`Erik` answer `theme()` at 0) while those
// fixtures are installed — otherwise the admin's choice would lose to glob
// order. A real deployment may drop the fixtures entirely; then this is the
// only theme answer either way. The promotion also covers `navigation()`, of
// which this provider is the sole non-empty answerer (fixtures return `[]`).
#[AsShellContextProvider(priority: -100)]
final class SettingsShellContextProvider implements ShellContextProvider
{
    /**
     * The stored site name — the row the admin edits under General.
     *
     * A missing or blank value abstains (null), keeping whatever the caller
     * passed instead of rendering an empty `<title>`/brand. The value is
     * trimmed but otherwise taken as stored: names like `responsive.sk` are
     * legitimate and must not be "normalised" into something else.
     */
    public function siteName(): ?string
    {
        $stored = SettingsServices::settings()->get('site_name');

        if (!is_string($stored) || '' === trim($stored)) {
            return null;
        }

        return trim($stored);
    }

    /**
     * The stored site-wide meta description. Blank abstains, like the name: a
     * half-filled settings row must not turn the description tag empty.
     */
    public function siteDescription(): ?string
    {
        $stored = SettingsServices::settings()->get('site_description');

        if (!is_string($stored) || '' === trim($stored)) {
            return null;
        }

        return trim($stored);
    }

    /**
     * The stored public theme — the vote that makes the admin `<select>` real.
     *
     * Only a resolvable value is answered: the kernel whitelist or a discovered
     * registry theme. A stale stored name (theme module removed) or an unset
     * key answers `null`, keeping the fixture/kernel degradation instead of
     * pre-empting it with a name nothing can render.
     */
    public function theme(): ?string
    {
        $stored = SettingsServices::settings()->get(ThemeResolver::THEME_SETTING);

        if (!is_string($stored)) {
            return null;
        }

        $available = [...ThemeResolver::PUBLIC_WHITELIST, ...array_keys(ThemeRegistry::all())];

        return ThemePreference::isResolvable($stored, $available) ? $stored : null;
    }

    /**
     * The stored settings of the ACTIVE theme, one map, prefix stripped.
     *
     * The theme is resolved the same way `theme()` resolves it — stored vote
     * vetted against what the tree can render, else the kernel theme — because
     * the rows are keyed by that name: reading `theme.<something else>.` here
     * would hand Boson the meatball Mono was configured with.
     *
     * Every row of the settings table is scanned rather than queried per key:
     * `SettingsService` already holds the whole table in memory for the request
     * (that is why it exists), and the alternative is a `LIKE 'theme.%'` that
     * would still need the same filtering in PHP to match the theme exactly.
     *
     * @return array<string, string>
     */
    public function themeSettings(): array
    {
        $theme = $this->theme() ?? ThemeResolver::PUBLIC_THEME;
        $values = [];

        foreach (SettingsServices::settings()->all() as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            $field = ThemeSettingValues::fieldFromStorageKey($theme, $key);

            if (null === $field) {
                continue;
            }

            $values[$field] = $value;
        }

        return $values;
    }

    /**
     * The stored default locale — the vote that makes `<html lang>` follow
     * the admin's Locale field instead of the kernel's 'en' fallback.
     *
     * Only a well-formed ll_CC code is answered (the admin field is free
     * text); anything else abstains, keeping the kernel degradation. No
     * Locales import on purpose: availability stays the Locales module's
     * answer (LocaleService::default), this port only echoes a sane stored
     * value — the same split as the theme vote above it.
     */
    public function defaultLocale(): ?string
    {
        $stored = SettingsServices::settings()->get('locale');

        if (!is_string($stored) || 1 !== preg_match('/^[a-z]{2}_[A-Z]{2}$/', $stored)) {
            return null;
        }

        return $stored;
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        return [];
    }

    /**
     * No copy here, and `[]` is the answer rather than an oversight: this
     * module owns the `menu_items` table and the stored admin choices, not a
     * single translated string. `copy()` is answered by Locales, whose rows are
     * the ones the translation admin edits — a second answer from Settings
     * would be a second source for the same words.
     *
     * @return array<string, string>
     */
    public function copy(string $group, string $locale): array
    {
        return [];
    }

    /**
     * Settings holds the stored DEFAULT vote, not the locale a request
     * resolved — that answer belongs to the module that owns locales. Null
     * here keeps this provider out of the vote instead of outranking it.
     */
    public function activeLocale(): ?string
    {
        return null;
    }

    /**
     * The language part of the voted default locale ('sk_SK' → 'sk') —
     * null when there is no vote, so the kernel fallback still applies.
     */
    public function htmlLang(): ?string
    {
        $default = $this->defaultLocale();

        if (null === $default) {
            return null;
        }

        return explode('_', $default)[0];
    }

    /**
     * @return list<ShellNavItem>
     */
    public function navigation(string $slot, string $currentPath): array
    {
        $items = SettingsServices::menu()->menu($slot, $currentPath);

        $visible = array_filter(
            array_map(static fn (MenuItemDTO $item): ?ShellNavItem => self::navItem($item, self::signedIn()), $items),
        );

        return array_values($visible);
    }

    /**
     * One menu row as a kernel-owned nav item, or null when this visitor may
     * not see it.
     */
    private static function navItem(MenuItemDTO $item, bool $signedIn): ?ShellNavItem
    {
        if (!$signedIn && self::isAdminHref($item->href())) {
            return null;
        }

        return new ShellNavItem(
            label: $item->label(),
            href: $item->href(),
            active: $item->active(),
            external: $item->external(),
            children: array_values(array_filter(
                array_map(static fn (MenuItemDTO $child): ?ShellNavItem => self::navItem($child, $signedIn), $item->children()),
            )),
        );
    }

    private static function isAdminHref(string $href): bool
    {
        return AdminRoutePolicy::isAdminPath($href);
    }

    /**
     * Whether this request is signed in — read exactly as the kernel reads it,
     * including the guard that keeps a session from being started for somebody
     * who has none.
     */
    private static function signedIn(): bool
    {
        $sessionName = session_name();

        if (false === $sessionName || !isset($_COOKIE[$sessionName])) {
            return false;
        }

        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        /** @var array{auth?: array{user_id?: string}} $session */
        $session = $_SESSION;

        return isset($session['auth']['user_id']);
    }
}
