<?php

declare(strict_types = 1);

namespace Modules\Locales\Interfaces\HTTP;

use Modules\Core\Domain\Contracts\ShellContextProvider;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Attributes\AsShellContextProvider;
use Modules\Locales\Domain\Model\Locale;

/**
 * LocalesShellContextProvider — the LOCALE answers of the shell port,
 * owned by the module that owns locale knowledge.
 *
 * The Settings provider echoes the raw stored `locale` key (free text,
 * format-checked); this provider answers the RESOLVED default — the stored
 * value vetted against the available set, falling back to the built-in
 * default when the stored value is missing or unknown. First-non-null wins
 * by priority, so a stored valid code is echoed by Settings first and this
 * provider is the backstop that keeps `<html lang>` truthful either way.
 *
 * `languages()` is the available set itself (Locale::available, the single
 * source of truth) — the union the theme switchers render. It travels with
 * the slice like every provider, so removing the module removes the answers
 * and the switchers hide again instead of pointing at dead locales.
 *
 * ## The copy
 *
 * `copy()` is a third kind of answer here, and it closed the last hole in this
 * port: a `sk_SK` site rendered English home copy because the only thing in the
 * tree that knew what `home.hero_title` says in Slovak was THIS module, and the
 * kernel rendering the page had no way to ask it. It answers the group straight
 * out of `locales.db` — the same rows the translation admin edits and the
 * migrator seeds — so the page and the admin screen cannot disagree about which
 * strings a locale has. Keys come back whole (`home.hero_title`), because the
 * group IS the surface's name and a stripped prefix would let `home.title`
 * collide with the shell's own `title`.
 */
#[AsShellContextProvider(priority: 0)]
final class LocalesShellContextProvider implements ShellContextProvider
{
    public function siteName(): ?string
    {
        return null;
    }

    public function theme(): ?string
    {
        return null;
    }

    public function defaultLocale(): ?string
    {
        return LocalesServices::locale()->default()->code();
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        return array_keys(Locale::available());
    }

    public function htmlLang(): ?string
    {
        return LocalesServices::locale()->default()->language();
    }

    /**
     * The locale the middleware resolved for THIS request — the entry every
     * theme's switcher marks as current.
     *
     * Not `defaultLocale()`: that fact answers which locale owns the content
     * root, and the kernel used it for both, so `/en/blog` rendered English
     * with Slovak marked active. `current()` is the middleware's answer and
     * falls back to the default when nothing has resolved.
     */
    public function activeLocale(): ?string
    {
        return LocalesServices::locale()->current()->code();
    }

    /**
     * The copy one surface renders, in one locale — the `locales.db` rows for
     * that group.
     *
     * An unknown or empty locale answers `[]` rather than falling back to the
     * default: the kernel asked for a locale it has, and a provider that quietly
     * substitutes another is how a Slovak site ends up rendering English with
     * no way to tell. The theme's own floor covers the gap, visibly.
     *
     * @return array<string, string>
     */
    public function copy(string $group, string $locale): array
    {
        if ('' === $group || '' === $locale) {
            return [];
        }

        $copy = [];

        foreach (LocalesServices::translations()->getByGroup($group, $locale) as $translation) {
            $copy[$translation->key] = $translation->value;
        }

        return $copy;
    }

    /**
     * @return list<ShellNavItem>
     */
    public function navigation(string $slot, string $currentPath): array
    {
        return [];
    }

    /**
     * No theme settings here, and `[]` is the answer rather than an oversight:
     * this module owns translations, not the choices a theme offers its admin.
     * The rows live with the module that owns the settings table; a second
     * reader of the same keys would be a second source for one fact.
     *
     * @return array<string, string>
     */
    public function themeSettings(): array
    {
        return [];
    }
}
