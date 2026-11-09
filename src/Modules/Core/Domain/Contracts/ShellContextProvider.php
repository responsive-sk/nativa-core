<?php

declare(strict_types = 1);

namespace Modules\Core\Domain\Contracts;

use Modules\Core\Domain\ShellNavItem;

/**
 * ShellContextProvider — the kernel-owned port the site shell asks instead of
 * reaching into a module.
 *
 * The kernel renders a document for every public surface, and a document needs
 * six facts that belong to whoever owns the site: its name, the active theme,
 * the default locale, the language list, the navigation menu, and the copy a
 * surface renders. Before this port, `FrontendLayout`, `ThemeResolver` and
 * `ContentLocaleServices` reaches for the shell port instead of a module
 * directly, which made the shared kernel unbuildable without two feature
 * modules it does not own.
 *
 * `copy()` is the last of the six and arrived the same way the other five did:
 * the kernel held a surface whose words it could not resolve. `HomepageAction`
 * passed `['title' => $siteName]` and nothing else, so a theme rendering a
 * translated home had to carry the strings itself — measured on `jednotka`,
 * whose English defaults answered a `sk_SK` site. The translations were ALREADY
 * in `locales.db` (25 `home.*` rows in both locales, seeded by the Locales
 * migrator); nothing in the tree could ask for them without a `Locale` import,
 * so the page came out English on a Slovak site with every gate green.
 *
 * **Absence is a first-class answer, not an error.** Every method may answer
 * null (or an empty list), and a kernel with no provider installed is a kernel
 * running as designed: it renders with no site name of its own, its own theme,
 * no locales and no menu. A provider that throws is a broken module, and the
 * caller is free to let that surface — never the whole pipeline.
 *
 * Implementations are discovered by attribute (`#[AsShellContextProvider]`) the
 * same way middleware is, so a module joins by existing rather than by being
 * registered in a list the kernel would have to keep.
 */
interface ShellContextProvider
{
    /** The site's display name, or null when the provider has no opinion. */
    public function siteName(): ?string;

    /**
     * The active public theme name, or null to let the kernel use its own.
     *
     * Null is not "no theme": it means "no preference", and the kernel resolves
     * its default. A provider that names a theme the tree does not have is a
     * configuration problem, and `ThemeKernel` answers it by falling back to
     * the kernel theme rather than by fataling on a missing class.
     */
    public function theme(): ?string;

    /**
     * The configured default locale code ('sk_SK'), or null when unset.
     *
     * The kernel never hardcodes which locale owns the content root — that is
     * what this method is for.
     */
    public function defaultLocale(): ?string;

    /**
     * Every language code the site offers ('sk', 'en'), in display order.
     *
     * @return list<string>
     */
    public function languages(): array;

    /**
     * The language code for `<html lang>`, or null to follow the default locale.
     */
    public function htmlLang(): ?string;

    /**
     * The locale resolved for THIS request — URL prefix, header, cookie,
     * session, or the site default — or null when the provider has no opinion.
     *
     * This is a different fact from `defaultLocale()`: the default says which
     * locale owns the content root, while this says which language the visitor
     * is reading right now. Using the default for both pinned every theme's
     * language switcher to the default entry, so `/en/blog` rendered English
     * copy with Slovak marked current and clicking it changed nothing visible.
     */
    public function activeLocale(): ?string;

    /**
     * The copy one surface renders, in one locale, keyed by FULL translation
     * key (`home.hero_title`).
     *
     * The group is the surface's name (`home`), and the provider answers the
     * whole group rather than a list of keys the kernel would have to know: a
     * theme reading `home.layer2_card3_text` is the only thing that knows it
     * wants that key, and a key nobody renders (`home.eyebrow`) costs nothing.
     *
     * **A key the provider does not hold is ABSENT, never empty.** That is what
     * lets a theme carry its own floor and be overridden key by key.
     *
     * @return array<string, string>
     */
    public function copy(string $group, string $locale): array;

    /**
     * Navigation for one slot ('header' or 'footer'), already marked active
     * against the current path.
     *
     * @return list<ShellNavItem>
     */
    public function navigation(string $slot, string $currentPath): array;

    /**
     * The stored settings of the ACTIVE theme, keyed by the theme's own field
     * names (`nav_toggle_icon`), with the `theme.{name}.` storage prefix
     * already stripped.
     *
     * Deliberately undecorated: defaults are the THEME's answer, not the
     * provider's. The provider owns one row of storage and knows nothing about
     * which fields a theme renders, so it reports what is stored and lets the
     * schema fill the gaps — a second place carrying defaults is a second place
     * that can disagree with the form.
     *
     * `[]` therefore means "nothing stored for this theme", which is exactly
     * the state of a fresh install and the state of every theme that has no
     * settings contract at all.
     *
     * @return array<string, string>
     */
    public function themeSettings(): array;
}
