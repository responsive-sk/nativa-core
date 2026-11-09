<?php

declare(strict_types = 1);

namespace Themes;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Shell\ShellContext;
use Shell\ShellContextProviderRegistry;
use Shell\ShellNavItem;
use View\InlineCss;
use View\InlineJs;

/**
 * FrontendLayout — shared public page shell (task 10.3).
 *
 * Renders a self-contained HTML page with a minimal BEM-styled header
 * (site name + DB-driven navigation via MenuService) and footer. No
 * external CDNs — assets are resolved from the local Vite manifest via
 * AssetManager (task 10.5).
 *
 * CSS strategy: critical CSS is small, so the built stylesheet is inlined
 * into <head> (with the CSP nonce) instead of being linked. This removes
 * the render-blocking stylesheet request from the critical path (better
 * LCP) while keeping first paint styled exactly once (CLS stays 0).
 *
 * Fallback: when the Vite build has not run (no manifest), the layout
 * degrades to a minimal inline critical style so pages never render fully
 * unstyled during development.
 */
final class FrontendLayout
{
    /**
     * Last-resort site name — used when no provider answers through the
     * shell port. One constant so the layout, the title helpers and the
     * error fallback cannot disagree about what the tree is called.
     */
    public const string DEFAULT_SITE_NAME = 'Nativa PHP Core';

    /**
     * Last-resort meta description, used when neither the action nor the
     * shell port supplies one. One literal for the same reason as the name.
     */
    public const string DEFAULT_DESCRIPTION = 'Nativa PHP Core — the kernel-only tree: zero-dependency runtime, attribute routing, a middleware pipeline, per-module SQLite connections and a theme that needs no build.';

    /**
     * The shared public base critical-CSS chain, in cascade order — the
     * single source of truth every public theme's `criticalCss()` list
     * starts with (themes that layer their own sections append them after
     * this block). `criticalCss()` concatenates exactly this list.
     *
     * @var list<string>
     */
    public const array BASE_CRITICAL_CSS = [
        'Themes/assets/critical/base.css',
        'Themes/assets/critical/shell.css',
        'Themes/assets/critical/nav.css',
        'Themes/assets/critical/blog.css',
        'Themes/assets/critical/blog-search.css',
        'Themes/assets/critical/blog-comments.css',
        'Themes/assets/critical/blog-toc.css',
        'Themes/assets/critical/blog-members.css',
        'Themes/assets/critical/home.css',
    ];
    /**
     * Navigation data (header + footer) — the provider that owns the menu
     * answers through the shell port, already mapped into kernel-owned
     * ShellNavItems; the theme module owns the nav markup. No provider
     * installed means no menu: a decided answer, not a failure.
     *
     * @return array{list<ShellNavItem>, list<ShellNavItem>} [headerNav, footerNav]
     */
    private static function navData(string $currentPath): array
    {
        return [
            ShellContextProviderRegistry::navigation('header', $currentPath),
            ShellContextProviderRegistry::navigation('footer', $currentPath),
        ];
    }

    /**
     * Render the public page shell.
     *
     * @param array<string, string>|null              $localeLinks       Optional explicit
     *                                                                   locale→URL map (e.g. article translations). When provided the locale
     *                                                                   switcher renders exactly these links (a missing locale = no link),
     *                                                                   instead of prefixing the current path for every available locale.
     * @param array<string, string>|null              $hreflangLinks     Optional
     *                                                                   language code → absolute URL map for <link rel="alternate" hreflang>
     *                                                                   tags in <head> (SEO — translated pages announce their alternates;
     *                                                                   x-default points at the default locale version). Rendered only when
     *                                                                   provided — single-language pages emit nothing.
     * @param string|null                             $canonicalUrl      Optional absolute canonical URL for <link rel="canonical">
     *                                                                   (SEO — the preferred version of a translated page, e.g. the
     *                                                                   default-locale SK article). Rendered only when provided.
     * @param string|null                             $ogType            Optional Open Graph type ('website'|'article'|'profile');
     *                                                                   defaults to 'website'. Also drives twitter:card sizing.
     * @param string|null                             $ogImage           Optional absolute Open Graph / twitter image URL (self-hosted
     *                                                                   or the allowed Cloudinary cover origin). Omitted when null.
     * @param string|null                             $description       Optional meta description. When null the shell
     *                                                                   port's site description is used, then the kernel
     *                                                                   fallback.
     * @param string|null                             $structuredData    Optional pre-encoded JSON-LD blob rendered in an
     *                                                                   <script type="application/ld+json"> (Seo\WebsiteStructuredData).
     * @param bool                                    $noindex           When true emits <meta name="robots" content="noindex, nofollow">
     *                                                                   (admin/debug/error/auth pages must not be indexed).
     * @param array{atom?: string, rss?: string}|null $feedLinks         RSS/Atom feed auto-discovery links for <head>.
     * @param string|null                             $searchAction      Optional destination for the theme's
     *                                                                   header search field. When set, the field's action (and its copy) is
     *                                                                   rewritten on this page; when null, the theme's markup is untouched.
     * @param string                                  $searchPlaceholder Copy for the rewritten field.
     * @param string                                  $searchLabel       Label copy for the rewritten field.
     */
    public static function render(
        string $title,
        string $content,
        string $siteName = self::DEFAULT_SITE_NAME,
        ?string $description = null,
        string $currentPath = '/',
        ?array $localeLinks = null,
        ?string $lang = null,
        ?array $hreflangLinks = null,
        ?string $canonicalUrl = null,
        ?string $ogType = null,
        ?string $ogImage = null,
        ?string $structuredData = null,
        bool $noindex = false,
        ?array $feedLinks = null,
        ?string $theme = null,
        ?string $searchAction = null,
        string $searchPlaceholder = 'Search',
        string $searchLabel = 'Search',
    ): string {
        // The site's own name comes first: a provider that knows it answers
        // through the port, and the caller's literal is the last resort (a
        // kernel with nothing installed still renders a titled document).
        $siteName = ShellContextProviderRegistry::siteName() ?? $siteName;

        $titleEscaped = self::e($title);
        $siteNameEscaped = self::e($siteName);
        $description ??= ShellContextProviderRegistry::siteDescription()
            ?? self::DEFAULT_DESCRIPTION;
        $descriptionEscaped = self::e($description);        // Dynamic html lang from the resolved active locale (sk / en). An
        // explicit $lang overrides it — an action that knows the actual
        // content language passes it, even when the site default differs. With
        // no locale provider installed the document still declares a language
        // rather than emitting `<html lang="">`, which reads as no language.
        $htmlLang = $lang ?? ShellContextProviderRegistry::htmlLang() ?? 'en';

        [$themeModule, $shellContext] = self::chrome($siteName, $currentPath, $localeLinks, $theme);

        $bundle = $themeModule->name();
        $jsKey = $themeModule->jsKey();
        $pageChrome = self::routeSearch(
            $themeModule->header($shellContext),
            $searchAction,
            $searchPlaceholder,
            $searchLabel,
        )
            . $themeModule->pageWrap($content)
            . $themeModule->footer($shellContext);

        // Every head segment is handed to document() as bytes, not as a row:
        // the row is what headTags() decides, so a segment that answers with
        // nothing leaves no blank line behind.
        return self::document(
            $titleEscaped,
            $descriptionEscaped,
            $htmlLang,
            $bundle,
            self::canonicalTag($canonicalUrl),
            self::hreflangTags($hreflangLinks),
            self::ogTags($titleEscaped, $descriptionEscaped, $siteNameEscaped, $canonicalUrl, $htmlLang, $ogType, $ogImage),
            self::noindexTag($noindex),
            self::structuredTag($structuredData),
            self::feedTags($feedLinks),
            self::styleTag($bundle, $jsKey, $themeModule->criticalCss()),
            self::themeInitScript($themeModule->themeInitScript()),
            self::themeToggleScript(),
            self::jsTag($bundle, $jsKey),
            $pageChrome,
        );
    }

    /**
     * The active theme module and the shell context its chrome renders from.
     *
     * One owner for the answer because two callers ask the same question: the
     * page shell, and `headerCarriesSearchField()`, which decides whether the
     * kernel has to compose a search field of its own. The second caller renders
     * the header and nothing else, so a page never pays for a second shell.
     *
     * @param array<string, string>|null $localeLinks
     *
     * @return array{ThemeModule, ShellContext}
     */
    private static function chrome(string $siteName, string $currentPath, ?array $localeLinks, ?string $theme): array
    {
        // The ACTIVE locale, not the default one. `defaultLocale()` answers
        // which locale owns the content root; passing it as the active value
        // pinned every theme's language switcher to the default entry, so an
        // English page showed Slovak as current. A tree without a locale
        // provider has no active answer, and the default is then the only
        // honest fallback.
        $activeLocale = ShellContextProviderRegistry::activeLocale()
            ?? ShellContextProviderRegistry::defaultLocale()
            ?? '';

        // The theme module is resolved BEFORE the shell context because the
        // context now carries the theme's own settings: the stored rows are
        // the port's answer, the defaults and the accepted values are the
        // theme's, and only the module can supply the second half.
        $themeModule = ThemeKernel::for($theme ?? ThemeResolver::PUBLIC_THEME);
        [$headerNav, $footerNav] = self::navData($currentPath);

        return [
            $themeModule,
            self::shellContext(
                $siteName,
                $headerNav,
                $footerNav,
                $currentPath,
                $localeLinks,
                $activeLocale,
                ShellContextProviderRegistry::languages(),
                self::themeSettings($themeModule),
            ),
        ];
    }

    /**
     * Does the theme's shell carry a search field on this path?
     *
     * The kernel asks before composing one of its own, because the answer is the
     * theme's: Boson ships a field in its header and a second behind the menu
     * toggle, while Jednotka, Erik, Griffe and Mono ship none at all. A kernel
     * that assumed every shell has one left those four themes with no way into
     * a module's `?q=` surface from the page.
     *
     * The field is recognised by `name="q"`, the name every destination in this
     * tree consumes. A theme that names its input something else is read as "no
     * field", which costs a second input rather than a lost one, and is why the
     * kernel's own field is the one used when this answers false.
     */
    public static function headerCarriesSearchField(string $currentPath = '/', ?string $theme = null): bool
    {
        [$themeModule, $shellContext] = self::chrome(
            ShellContextProviderRegistry::siteName() ?? self::DEFAULT_SITE_NAME,
            $currentPath,
            null,
            $theme,
        );

        return str_contains($themeModule->header($shellContext), 'name="q"');
    }

    /**
     * Point the shell's search field at the surface the caller names.
     *
     * The header's field is the THEME's markup, and a theme here is a byte-copy
     * of `nativa-themes` — `make themes-check` fails a hand edit, and the next
     * `themes-sync` would overwrite it. Routing is the caller's decision: a
     * module that owns a `?q=` surface passes its own action and copy, so this
     * method needs no knowledge of which module that is.
     *
     * The rewrite is scoped to the form carrying `name="q"` and matches exact
     * copy, so a theme whose markup differs is left alone rather than
     * half-edited: a miss changes nothing, and the field keeps the destination
     * it shipped with.
     */
    private static function routeSearch(
        string $header,
        ?string $searchAction,
        string $searchPlaceholder,
        string $searchLabel,
    ): string {
        if (null === $searchAction) {
            return $header;
        }

        return preg_replace_callback(
            '#<form\b[^>]*>.*?</form>#is',
            static function (array $match) use ($searchAction, $searchPlaceholder, $searchLabel): string {
                if (!str_contains($match[0], 'name="q"')) {
                    return $match[0];
                }

                $form = preg_replace(
                    '/(<form\b[^>]*\baction=")[^"]*(")/i',
                    '${1}' . self::e($searchAction) . '${2}',
                    $match[0],
                    1,
                ) ?? $match[0];

                return str_replace(
                    [
                        'placeholder="Search articles"',
                        '>Search articles</label>',
                        'placeholder="Search"',
                        '>Search</label>',
                    ],
                    [
                        'placeholder="' . self::e($searchPlaceholder) . '"',
                        '>' . self::e($searchLabel) . '</label>',
                        'placeholder="' . self::e($searchPlaceholder) . '"',
                        '>' . self::e($searchLabel) . '</label>',
                    ],
                    $form,
                );
            },
            $header,
        ) ?? $header;
    }

    /** Noindex robots meta — admin/debug/error/auth pages must not be indexed. */
    private static function noindexTag(bool $noindex): string
    {
        return $noindex ? '<meta name="robots" content="noindex, nofollow">' . "\n" : '';
    }

    /** Built-stylesheet and font-preload <head> tags (critical-CSS fallback when the build is missing).
     *
     * @param list<string> $criticalCss
     */
    private static function styleTag(string $bundle, string $jsKey, array $criticalCss): string
    {
        $inlineCss = self::inlineCss(new AssetManager($bundle), $jsKey);

        if ('' === $inlineCss) {
            $inlineCss = InlineCss::files($criticalCss);
        }

        // The bytes between the tags are the build's own, untouched: the theme
        // tests in `modules/Themes` compare them to the stylesheet on disk, and
        // that copy is byte-verified against nativa-themes (`make themes-check`).
        // So `</style>` stays flush left and the stylesheet's trailing newline
        // stays with it; the rows around the block are this file's to indent.
        return self::fontPreloads($inlineCss)
            . '    <style nonce="__CSP_NONCE_PLACEHOLDER__">' . "\n" . $inlineCss . "\n" . '</style>' . "\n";
    }

    /**
     * <link rel="preload"> for every webfont the inlined stylesheet declares.
     *
     * The stylesheet is inline, so the browser reaches the @font-face rules
     * only after it has parsed up to them; the preload starts the same request
     * at the first byte of <head> instead. That is exactly the "network
     * dependency tree" audit: `HTML → stylesheet → font` is a chain, and the
     * preload is what takes the font out of it. It does not add a download
     * that would not otherwise happen — the faces are used by text that is
     * already in the first viewport, so a preload only moves it earlier.
     *
     * Same-origin paths only, each URL once (a family may declare two weights
     * that share one file), and `crossorigin` is mandatory: fonts are fetched
     * in CORS mode, and a preload without the attribute is a second, wasted
     * download rather than a head start.
     */
    private static function fontPreloads(string $css): string
    {
        if ('' === $css) {
            return '';
        }

        preg_match_all('/@font-face\s*\{[^}]*?url\(\s*[\'"]?([^\'")]+\.woff2)[\'"]?\s*\)/i', $css, $matches);

        $tags = '';
        foreach (array_unique($matches[1]) as $url) {
            if (!str_starts_with($url, '/')) {
                continue;
            }

            $tags .= '    <link rel="preload" href="' . self::e($url) . '" as="font" type="font/woff2" crossorigin>' . "\n";
        }

        return $tags;
    }

    /** Manifest-resolved deferred JS tag (empty when no entry resolves). */
    private static function jsTag(string $bundle, string $jsKey): string
    {
        $jsUrl = (new AssetManager($bundle))->jsUrl($jsKey);

        return null !== $jsUrl
            ? '<script src="' . $jsUrl . '" nonce="__CSP_NONCE_PLACEHOLDER__" defer></script>' . "\n"
            : '';
    }

    /** Shared theme-toggle handler — one copy referenced by every theme module. */
    private static function themeToggleScript(): string
    {
        return InlineJs::file('Themes/assets/scripts/theme-toggle.js');
    }

    /**
     * Pre-paint colour-mode resolution — the theme's own script when it
     * supplies one, otherwise the shared bytes every other theme uses.
     *
     * The fallback is not a simplification: for a theme whose LAST
     * resolution step is a design decision (jednotka is dark-first, and its
     * `:root` IS the dark palette), the shared script's unconditional
     * `matchMedia(...)` cannot express it. A theme that returns '' keeps the
     * shared bytes exactly — the emitted block is identical either way, which
     * is why the golden-master documents of the other five do not move.
     */
    private static function themeInitScript(string $themeScript): string
    {
        return '' !== $themeScript ? $themeScript : self::sharedThemeInitScript();
    }

    /**
     * The shared theme-init block, verbatim in what it does for every theme
     * that does not override it: restore the persisted choice before first
     * paint to avoid a flash of the wrong colour mode.
     *
     * Its own four-space indent is part of the literal so the block sits on
     * the same column as the head rows around it. It used to arrive at mixed
     * depths (the comment one step in, the `<script>` flush left) because the
     * heredoc's closing indent was the only thing deciding the column.
     */
    private static function sharedThemeInitScript(): string
    {
        return <<<'HTML'
                <!-- Sync theme init — FOUC prevention: restore the persisted theme
                     (or the OS preference) before first paint. Falls back to the
                     dark default when neither is available (Chrome 70 baseline). -->
                <script nonce="__CSP_NONCE_PLACEHOLDER__">
                    (function() {
                        try {
                            var theme = localStorage.getItem('nativa-theme');
                            if (!theme) {
                                theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                            }
                            document.documentElement.setAttribute('data-theme', theme);
                        } catch (e) {}
                    })();
                </script>
            HTML;
    }

    /** Canonical <link> — rendered only when the action provides a URL. */
    private static function canonicalTag(?string $canonicalUrl): string
    {
        return null !== $canonicalUrl
            ? '<link rel="canonical" href="' . self::e($canonicalUrl) . '">' . "\n"
            : '';
    }

    /**
     * JSON-LD block — rendered only when the action provides structured data.
     *
     * Carries the CSP nonce like every other inline script: without it the
     * `script-src 'self' 'nonce-…'` directive blocks the block in browsers
     * (reported as two `script-src-elem` violations on article pages) and
     * the structured data never reaches the consumer. The placeholder is
     * substituted per response by SecurityHeadersMiddleware, exactly like
     * the theme init and toggle scripts below.
     */
    private static function structuredTag(?string $structuredData): string
    {
        return null !== $structuredData
            ? '<script type="application/ld+json" nonce="__CSP_NONCE_PLACEHOLDER__">' . $structuredData . '</script>' . "\n"
            : '';
    }

    /**
     * The tag rows of `<head>`, one line each, four spaces in, and no row at
     * all for a segment that answered with nothing.
     *
     * `document()` used to interpolate every builder on its own heredoc line,
     * so the row existed whether or not the builder had anything to put in it:
     * no hreflang pair, no feed, no `noindex`, no structured data still left a
     * line holding four spaces, and a builder that ended in `\n` left a second,
     * empty one beside it. That is why the page source carried a gap between
     * `og:url` and the next tag; the rows are decided here now, from the bytes.
     */
    private static function headTags(string ...$segments): string
    {
        $lines = [];

        foreach ($segments as $segment) {
            foreach (explode("\n", $segment) as $line) {
                if ('' !== trim($line)) {
                    $lines[] = '    ' . $line;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Assemble the final HTML document (head + body). The $pageChrome
     * carries 12 leading spaces from the legacy heredoc — the theme modules
     * reproduce them for byte parity (theme-as-module golden masters).
     */
    private static function document(
        string $titleEscaped,
        string $descriptionEscaped,
        string $htmlLang,
        string $bundle,
        string $canonicalTag,
        string $hreflangTags,
        string $ogTags,
        string $noindexTag,
        string $structuredTag,
        string $feedTags,
        string $styleTag,
        string $themeInitScript,
        string $themeToggleScript,
        string $jsTag,
        string $pageChrome,
    ): string {
        // The line break between two rows is the heredoc's, so a segment that
        // keeps a trailing newline of its own prints one empty row beside it.
        $styleTag = rtrim($styleTag);
        $themeInitScript = rtrim($themeInitScript);
        $pageChrome = rtrim($pageChrome);
        // The deferred bundle is the body's only optional row. It rides the
        // toggle script's line so a tree with no build (the kernel theme) has
        // no row to leave empty.
        $jsTag = '' === trim($jsTag) ? '' : "\n    " . rtrim($jsTag);

        $headTags = self::headTags(
            $canonicalTag,
            $hreflangTags,
            $ogTags,
            $noindexTag,
            $structuredTag,
            $feedTags,
        );

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$htmlLang}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="theme-color" content="#0f172a">
            <link rel="icon" type="image/svg+xml" href="/favicon.svg">
            <link rel="icon" type="image/x-icon" href="/favicon.ico">
            <link rel="apple-touch-icon" href="/apple-touch-icon.png">
            <title>{$titleEscaped}</title>
            <meta name="description" content="{$descriptionEscaped}">
        {$headTags}
        {$styleTag}
        {$themeInitScript}
        </head>
        <body class="{$bundle}-shell">
            {$pageChrome}
            <!-- Theme toggle handler — flips data-theme on <html>, persisted to localStorage -->
            <script nonce="__CSP_NONCE_PLACEHOLDER__">{$themeToggleScript}</script>{$jsTag}
        </body>
        </html>
        HTML;
    }

    /**
     * Build the ShellContext for the theme module (kernel owns data fetch,
     * the theme owns markup).
     *
     * The locale half arrives as finished answers — the port resolved which
     * locale is active and which ones exist — so this method does no locale
     * work of its own and imports no locale module.
     *
     * @param list<ShellNavItem>         $headerNav
     * @param list<ShellNavItem>         $footerNav
     * @param array<string, string>|null $localeLinks
     * @param list<string>               $availableLocales language codes, in display order
     * @param array<string, string>      $themeSettings    resolved values for the active theme
     */
    private static function shellContext(
        string $siteName,
        array $headerNav,
        array $footerNav,
        string $currentPath,
        ?array $localeLinks,
        string $activeLocale,
        array $availableLocales,
        array $themeSettings,
    ): ShellContext {
        return ShellContext::from(
            $siteName,
            $headerNav,
            $footerNav,
            $currentPath,
            $localeLinks,
            $activeLocale,
            $availableLocales,
            self::sessionHasAuth(),
            $themeSettings,
        );
    }

    /**
     * The active theme's settings, defaults merged in — what the theme reads off
     * the shell context.
     *
     * Two sources, and neither can answer alone: the port knows what an admin
     * stored (and nothing about which fields exist), the theme's schema knows
     * which fields exist and which values are legal (and nothing about
     * storage). `ThemeSettingValues::resolve()` is the one place they meet, so a
     * hand-written row or a value retired from the schema cannot reach markup.
     *
     * A theme without the contract gets `[]` — no schema, no values, no change
     * to how it renders today.
     *
     * @return array<string, string>
     */
    private static function themeSettings(ThemeModule $theme): array
    {
        return ThemeSettingValues::resolve(
            $theme instanceof ThemeSettings ? $theme : null,
            ShellContextProviderRegistry::themeSettings(),
        );
    }

    /**
     * Read the built stylesheet contents from the Vite manifest so the CSS
     * can be inlined (critical CSS is small — no separate request).
     *
     * @return string concatenated built CSS (empty when the build is missing)
     */
    private static function inlineCss(AssetManager $assets, string $jsKey): string
    {
        $css = '';

        foreach ($assets->cssUrls($jsKey) as $url) {
            $path = AppPaths::instance()->public(ltrim($url, '/'));

            if (is_file($path)) {
                $content = file_get_contents($path);
                if (is_string($content) && '' !== trim($content)) {
                    $css .= $content . "\n";
                }
            }
        }

        return $css;
    }

    /**
     * Critical CSS — development fallback when the Vite build has not run.
     * The bytes live as real .css files under
     * Themes/assets/critical/ and are inlined verbatim
     * via InlineCss (byte-parity guarded by CriticalCssByteParityTest).
     */
    public static function criticalCss(): string
    {
        return InlineCss::files(self::BASE_CRITICAL_CSS);
    }

    /**
     * Open Graph + Twitter card meta tags — emitted in <head> for social
     * previews (Facebook/LinkedIn/WhatsApp read og:*, Twitter reads both).
     * og:url mirrors the canonical URL when present (otherwise the scraper
     * falls back to the page URL); og:locale is derived from the html lang.
     * All values arrive already escaped by the caller.
     */
    private static function ogTags(
        string $title,
        string $description,
        string $siteName,
        ?string $canonicalUrl,
        string $htmlLang,
        ?string $ogType,
        ?string $ogImage,
    ): string {
        $locale = 'en' === $htmlLang ? 'en_US' : 'sk_SK';

        $tags = '<meta property="og:site_name" content="' . $siteName . '">' . "\n"
            . '<meta property="og:type" content="' . ($ogType ?? 'website') . '">' . "\n"
            . '<meta property="og:title" content="' . $title . '">' . "\n"
            . '<meta property="og:description" content="' . $description . '">' . "\n"
            . '<meta property="og:locale" content="' . $locale . '">' . "\n"
            . '<meta name="twitter:card" content="' . (null !== $ogImage ? 'summary_large_image' : 'summary') . '">' . "\n"
            . '<meta name="twitter:title" content="' . $title . '">' . "\n"
            . '<meta name="twitter:description" content="' . $description . '">' . "\n";

        if (null !== $canonicalUrl) {
            $tags .= '<meta property="og:url" content="' . $canonicalUrl . '">' . "\n";
        }

        if (null !== $ogImage) {
            $tags .= '<meta property="og:image" content="' . $ogImage . '">' . "\n"
                . '<meta name="twitter:image" content="' . $ogImage . '">' . "\n";
        }

        return $tags;
    }

    /**
     * hreflang alternate link tags for translated pages — emitted in <head>
     * so search engines know the language versions of the current URL. Each
     * entry maps a language code (sk, en, x-default) to an absolute URL.
     *
     * @param array<string, string>|null $hreflangLinks
     */
    private static function hreflangTags(?array $hreflangLinks): string
    {
        if (null === $hreflangLinks) {
            return '';
        }

        $tags = '';

        foreach ($hreflangLinks as $language => $url) {
            $tags .= '<link rel="alternate" hreflang="' . self::e($language) . '" href="'
                . self::e($url) . '">' . "\n";
        }

        return $tags;
    }

    /**
     * RSS/Atom feed auto-discovery links.
     *
     * @param array{atom?: string, rss?: string}|null $feedLinks
     */
    private static function feedTags(?array $feedLinks): string
    {
        if (null === $feedLinks) {
            return '';
        }

        $tags = '';

        if (isset($feedLinks['atom'])) {
            $tags .= '<link rel="alternate" type="application/atom+xml" title="Atom Feed" href="'
                . self::e($feedLinks['atom']) . '">' . "\n";
        }

        if (isset($feedLinks['rss'])) {
            $tags .= '<link rel="alternate" type="application/rss+xml" title="RSS Feed" href="'
                . self::e($feedLinks['rss']) . '">' . "\n";
        }

        return $tags;
    }

    /**
     * Does the (already-started or startable) session carry an auth stamp?
     * Zero users.db I/O — only the session cookie + session file are touched,
     * and the session is only started when the cookie is present.
     */
    private static function sessionHasAuth(): bool
    {
        $sessionName = session_name();
        if (false === $sessionName || !isset($_COOKIE[$sessionName])) {
            return false;
        }

        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        /** @var array{auth?: array{user_id: string}} $session */
        $session = $_SESSION;

        return isset($session['auth']['user_id']);
    }

    /**
     * The reading surface shared by every long-form page the kernel composes:
     * a prose column bounded by measure, beside a floating contents rail.
     *
     * Why it lives here rather than in a theme: the themes are a copy of
     * `nativa-themes` and a hand edit there is drift the gate reports
     * (`make themes-check`), while the surfaces that need the layout are the
     * a docs module's (`/docs`) and a blog module's (`/blog/<slug>`). A rule five themes
     * must each carry — and a sixth silently misses — belongs on the calling
     * side, once.
     *
     * The rail is built from `MarkdownRenderer::extractHeadings()`, which
     * returns the anchors the renderer itself generated, so a rail link can
     * never point at an id the page does not carry.
     *
     * ONE RAIL, NEVER TWO. A theme whose article already composes a contents
     * rail (Boson, Jednotka, Mono and Griffe all do) answers the reader's
     * question itself; wrapping it again would render two. The check is the
     * marker every rail carries — `aria-label="On this page"` — read off the
     * composed HTML rather than guessed from the theme's name, so a theme that
     * starts or stops composing one needs no change here.
     *
     * Fewer than two headings means no rail and the full measure: a one-entry
     * table of contents is furniture, and it would narrow the prose to show a
     * single link.
     *
     * The `<style>` block carries the CSP nonce placeholder, which
     * `SecurityHeadersMiddleware` replaces on every HTML response, so a cached
     * page keeps no stale nonce.
     *
     * The rail's sticky top is `calc(var(--docs-header-offset) + 1.5rem)`, not a
     * constant: Boson's bar is fixed at 100px (70px once scrolled) and is painted
     * over the top of the viewport, so a rail at 1.5rem sat behind it. The number
     * is measured per page by `readerLayoutScript()`, which keeps the kernel out
     * of the business of knowing any theme's header.
     *
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    public static function readerLayout(string $content, array $headings): string
    {
        // One rail, never two — measured off the composed document.
        if (str_contains($content, 'aria-label="On this page"')) {
            return $content;
        }

        $rail = self::contentsRail($headings);

        return self::readerLayoutStyle()
            . '<div data-docs-reader' . ('' !== $rail ? ' data-has-rail' : '') . '>'
            . '<div data-docs-prose>' . $content . '</div>'
            . $rail
            . '</div>'
            // After the markup it measures: the rail has to be in the DOM
            // before the script looks for it, and nothing above should wait.
            . self::readerLayoutScript();
    }

    /**
     * The floating "On this page" rail, or an empty string when the document has
     * fewer than two headings to list.
     *
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    private static function contentsRail(array $headings): string
    {
        if (\count($headings) < 2) {
            return '';
        }

        $items = '';

        foreach ($headings as $heading) {
            $items .= '<li data-docs-toc__item data-level="' . $heading['level'] . '">'
                . '<a href="#' . self::e($heading['anchor']) . '">'
                . self::e($heading['text'])
                . '</a></li>';
        }

        return '<aside data-docs-toc aria-label="On this page">'
            . '<p data-docs-toc__title>On this page</p>'
            . '<ul>' . $items . '</ul>'
            . '</aside>';
    }

    /**
     * The rule set that travels with the markup. Keyed on `data-docs-*`
     * attributes rather than a theme prefix, so every theme renders the same
     * reader layout without a rule in its own stylesheet. 68ch is the
     * long-standing comfortable measure for body copy; a paragraph at full width
     * on a 1440 px display is ~180 characters and the reader loses the line on
     * return. Code and tables opt out of the measure — a sample squashed to 68
     * characters wraps mid-token — but they opt out INSIDE the column, not
     * outside it: a table wide enough to need more room scrolls in its own box
     * instead of running under the rail. That distinction is measured, not
     * theoretical: `/docs/themes` put a theme's `.bo-page` (90vw) and its table
     * 527px into the rail column before `[data-docs-prose] > *` was bounded.
     */
    private static function readerLayoutStyle(): string
    {
        return <<<'CSS'
            <style nonce="__CSP_NONCE_PLACEHOLDER__">
            [data-docs-reader]{--docs-header-offset:0px;display:grid;grid-template-columns:minmax(0,1fr);gap:2.5rem}
            @media (min-width:1024px){
              [data-docs-reader][data-has-rail]{grid-template-columns:minmax(0,68ch) minmax(12rem,16rem);justify-content:center;align-items:start}
            }
            [data-docs-prose]{max-width:68ch;min-width:0}
            [data-docs-prose] p,[data-docs-prose] li,[data-docs-prose] blockquote{max-width:68ch}
            [data-docs-prose]>*{max-width:100%}
            [data-docs-prose] pre,[data-docs-prose] table{max-width:100%}
            [data-docs-prose] pre{overflow-x:auto}
            [data-docs-prose] table{display:block;overflow-x:auto}
            [data-docs-toc]{font-size:.875rem;line-height:1.5}
            @media (min-width:1024px){
              [data-docs-toc]{position:sticky;top:calc(var(--docs-header-offset) + 1.5rem);max-height:calc(100vh - var(--docs-header-offset) - 3rem);overflow:auto;padding-left:1rem;border-left:1px solid rgba(128,128,128,.35)}
            }
            [data-docs-toc__title]{margin:0 0 .5rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;font-size:.75rem;opacity:.7}
            [data-docs-toc] ul{margin:0;padding:0;list-style:none}
            [data-docs-toc] li{margin:0 0 .35rem}
            [data-docs-toc] li[data-level="3"]{padding-left:.85rem;opacity:.85}
            [data-docs-toc] a{text-decoration:none;opacity:.8}
            [data-docs-toc] a:hover,[data-docs-toc] a:focus-visible{opacity:1;text-decoration:underline}
            </style>
            CSS;
    }

    /**
     * The measuring script that answers `--docs-header-offset` (see
     * assets/scripts/docs-reader.js). Carries the nonce placeholder like every
     * other inline script, so CSP lets it run; a missing asset emits nothing and
     * the rail falls back to the 1.5rem it had before.
     */
    private static function readerLayoutScript(): string
    {
        $script = InlineJs::file('Themes/assets/scripts/docs-reader.js');

        return '' !== $script
            ? '<script nonce="__CSP_NONCE_PLACEHOLDER__">' . "\n" . $script . "\n" . '</script>'
            : '';
    }

    public static function e(mixed $value): string
    {
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
