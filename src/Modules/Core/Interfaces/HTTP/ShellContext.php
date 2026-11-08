<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP;

use Modules\Core\Domain\ShellNavItem;

/**
 * Data the theme module needs to render its shell chrome (header/footer).
 *
 * The kernel owns the data fetching (menu DTOs from config.db, locale data
 * from locales.db, auth state from the session); the theme owns ALL markup —
 * including its own prefixed locale switcher, user link, nav and footer nav.
 */
final readonly class ShellContext
{
    /**
     * @param list<ShellNavItem>         $headerNav        header navigation, mapped by the provider
     * @param list<ShellNavItem>         $footerNav        footer navigation, mapped by the provider
     * @param array<string, string>|null $localeLinks      locale code → href map (article
     *                                                     translations). Null = switcher renders the
     *                                                     current path for every available locale.
     * @param list<string>               $availableLocales language codes available in locales.db
     * @param array<string, string>      $themeSettings    the active theme's settings, defaults
     *                                                     already merged in by the kernel — a theme
     *                                                     reads its own field, never the settings table
     */
    private function __construct(
        public string $siteName,
        public array $headerNav,
        public array $footerNav,
        public string $currentPath,
        public int $year,
        public ?array $localeLinks,
        public string $activeLocale,
        public array $availableLocales,
        public bool $signedIn,
        public array $themeSettings,
    ) {
    }

    /**
     * @param list<ShellNavItem>         $headerNav
     * @param list<ShellNavItem>         $footerNav
     * @param array<string, string>|null $localeLinks
     * @param list<string>               $availableLocales
     * @param array<string, string>      $themeSettings
     */
    public static function from(
        string $siteName,
        array $headerNav,
        array $footerNav,
        string $currentPath,
        ?array $localeLinks,
        string $activeLocale,
        array $availableLocales,
        bool $signedIn,
        array $themeSettings = [],
    ): self {
        return new self(
            $siteName,
            $headerNav,
            $footerNav,
            $currentPath,
            (int) date('Y'),
            $localeLinks,
            $activeLocale,
            $availableLocales,
            $signedIn,
            $themeSettings,
        );
    }
}
