<?php

declare(strict_types = 1);

namespace Shell;

/**
 * LocalizedNav — a menu href as the URL the current locale serves.
 *
 * One menu row is one menu for every language: `menu_items` stores the
 * canonical (default-locale) path — `/blog` — and a page served under a locale
 * prefix renders that path under the same prefix, `/en/blog`. Without this an
 * English reader clicking `Articles` in the header landed on the Slovak blog,
 * and the only two ways out were the language switcher or the browser's back
 * button. Measured on `/en/references`: the header carried `href="/blog"`.
 *
 * ## Why the prefix comes from the caller
 *
 * The kernel does not know which URL prefixes this site serves — the module
 * that owns locales does, through `ShellContextProvider::localePrefix()`,
 * whose answer is `''` for the default locale (it owns the bare path). This
 * class is the rewriting rule alone: give it the prefix the request was served
 * from and it produces the href for that language, or the same list when the
 * prefix is empty.
 *
 * ## What is NOT rewritten
 *
 * - External links: another site has no locale of ours.
 * - Anchors and query strings: they are relative to the page that holds them.
 * - Protocol-relative `//host/...`: a URL, not a path.
 * - A path that already carries the prefix: a tree storing `/en/blog` verbatim
 *   must not become `/en/en/blog`.
 *
 * The `active` flag travels untouched. Providers compute it against the path
 * the request was matched on (the locale middleware strips the prefix before
 * routing), so the item the reader is standing on is already the active one —
 * localizing the href must not un-light it.
 */
final class LocalizedNav
{
    /**
     * Every href in the tree, localized for one prefix.
     *
     * @param list<ShellNavItem> $items
     *
     * @return list<ShellNavItem>
     */
    public static function apply(array $items, string $localePrefix): array
    {
        if ('' === $localePrefix) {
            return $items;
        }

        return array_map(
            static fn (ShellNavItem $item): ShellNavItem => self::localized($item, $localePrefix),
            $items,
        );
    }

    private static function localized(ShellNavItem $item, string $localePrefix): ShellNavItem
    {
        return new ShellNavItem(
            label: $item->label,
            href: self::href($item->href, $localePrefix, $item->external),
            active: $item->active,
            external: $item->external,
            children: self::apply($item->children, $localePrefix),
        );
    }

    private static function href(string $href, string $localePrefix, bool $external): string
    {
        if ($external || !str_starts_with($href, '/') || str_starts_with($href, '//')) {
            return $href;
        }

        if ('/' === $href) {
            return $localePrefix;
        }

        if ($href === $localePrefix || str_starts_with($href, $localePrefix . '/')) {
            return $href;
        }

        return $localePrefix . $href;
    }
}
