<?php

declare(strict_types = 1);

namespace Modules\Themes\Mono\Theme;

use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Domain\ThemeSetting;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Themes\ThemeModule;
use Themes\ThemeSettings;

/**
 * MonoTheme — the evan70.github.io/mono vocabulary as a kernel theme module.
 *
 * The CSS is a vendored, prefixed snapshot (see assets/critical/mono.css):
 * navbar, hero, feature cards, footer. What the preview page never had — the
 * locale switcher, a data-driven nav, the blog views — is DESIGNED in its
 * idiom here (navbar links, card grids), not ported: those views exist in the
 * contract and the theme must answer them.
 *
 * It imports nothing from a feature module. Article shapes are read through
 * `get_object_vars` rather than `use`ing the entity, the established kernel
 * pattern for a theme that must not name the module owning the article.
 */
final class MonoTheme implements ThemeModule, ThemeSettings
{
    public function name(): string
    {
        return 'mono';
    }

    public function bundle(): string
    {
        return 'mono';
    }

    public function jsKey(): string
    {
        return 'mono';
    }

    /**
     * @return list<string> src/-relative, resolved by InlineCss
     */
    public function criticalCss(): array
    {
        return ['Modules/Mono/assets/critical/mono.css', 'Modules/Mono/assets/critical/mo-variants.css'];
    }

    /**
     * Pre-paint colour-mode resolution: the shared dark/light restore,
     * verbatim, plus the persisted dev variant.
     *
     * Returning '' would keep the shared bytes exactly — but the variant
     * must also apply before first paint, or the page flashes the default
     * palette on every load. The override therefore reproduces the shared
     * block and appends the variant restore; only Mono pages move.
     */
    public function themeInitScript(): string
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
            <!-- Sync dev variant — same FOUC contract as the theme above:
                 unknown values are ignored, so a stale stored variant can
                 never leave the page unstyled. -->
            <script nonce="__CSP_NONCE_PLACEHOLDER__">
                (function() {
                    try {
                        var variant = localStorage.getItem('nativa-variant');
                        if ('ember' === variant || 'glacier' === variant) {
                            document.documentElement.setAttribute('data-variant', variant);
                        }
                    } catch (e) {}
                })();
            </script>
            <!-- Sync slider staging signal — same pre-paint contract as above:
                 the stylesheet stages a multi-slide hero the moment `mo-js`
                 is present, so the deferred bundle never reflows a stacked
                 opening frame. Skipped under reduced motion (the calm
                 fallback stays stacked) — `classList` and `matchMedia` are
                 both older than the Chrome 70 baseline. -->
            <script nonce="__CSP_NONCE_PLACEHOLDER__">
                (function() {
                    try {
                        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                            return;
                        }
                        document.documentElement.classList.add('mo-js');
                    } catch (e) {}
                })();
            </script>
            HTML;
    }

    public function prefix(): string
    {
        return 'mo';
    }

    /**
     * Mono's one admin choice: which glyph the phone menu toggle draws.
     *
     * `auto` keeps the meatball (this theme's own, and the name of the idiom
     * the other themes borrowed), so an untouched install renders what it
     * always did. Both closed-state glyphs ship and CSS shows one — the open
     * state still costs no JS.
     *
     * @return list<ThemeSetting>
     */
    public function settingsSchema(): array
    {
        return [
            ThemeSetting::select(
                'nav_toggle_icon',
                'Menu icon',
                [
                    'auto'     => 'Auto (theme default: meatball)',
                    'burger'   => 'Burger (three lines)',
                    'meatball' => 'Meatball (three dots)',
                ],
                'auto',
            ),
        ];
    }

    public function header(ShellContext $context): string
    {
        $hasMenu = [] !== $context->headerNav;
        $icon = 'burger' === ($context->themeSettings['nav_toggle_icon'] ?? 'auto') ? 'burger' : 'meatball';

        return '<nav class="mo-navbar">'
            . '<div class="mo-navbar__container mo-container">'
            . $this->menuToggleInput($hasMenu)
            . '<a class="mo-navbar__brand" href="/">' . self::e($context->siteName) . '</a>'
            . $this->menu($context->headerNav)
            . '<div class="mo-navbar__actions">'
            . self::themeToggle()
            . self::variantToggle('ember', 'dev-theme-switcher', 'Toggle Ember variant', self::flameIcon())
            . self::variantToggle('glacier', 'dev-variant-switcher-2', 'Toggle Glacier variant', self::snowflakeIcon())
            . $this->localeLinks($context)
            // The menu toggle is the last control on a phone, where it is the
            // actionable one; the switcher sits before it rather than after.
            . $this->menuToggleLabel($hasMenu, $icon)
            . '</div>'
            . '</div>'
            . '</nav>' . "\n";
    }

    public function footer(ShellContext $context): string
    {
        return '<footer class="mo-footer">'
            . '<div class="mo-footer__container">'
            . '<div class="mo-footer__bottom">'
            . '<p class="mo-footer__copyright">© ' . self::e($context->year) . ' ' . self::e($context->siteName) . '</p>'
            . $this->footerLinks($context->footerNav)
            . '</div>'
            . '</div>'
            . '</footer>' . "\n";
    }

    public function pageWrap(string $content): string
    {
        // No inline scripts here: the three behaviours this wrap used to
        // gate per surface (highlight on fences, scrollspy on TOC asides,
        // the ungated variant switcher) ship in the frozen `mono` bundle
        // FrontendLayout defers from the manifest. Each init self-gates on
        // its markup, so a page without fences or a TOC runs nothing. The
        // switcher syncs its buttons on DOMContentLoaded rather than during
        // parsing now — the pre-paint variant restore in themeInitScript()
        // still applies before first paint, so only the button pressed
        // state arrives a tick later, never the palette.
        return '<main>' . "\n" . $content . '</main>' . "\n";
    }

    /**
     * Fullscreen home slider, the griffe shape in the mono idiom: slide 1 is
     * the editorial hero (H1 + lede + CTA from `$copy`), slides 2+ are case
     * slides from `$latestArticles` (falling back to `$articles`, then to the
     * Blog latest-cards port when the action passed none — the kernel home
     * carries no Blog import by contract, so without the fallback the slider
     * would stay a lone hero on every tree that actually publishes). Slide 1
     * is active server-side, so a static render (no JS, reduced motion) still
     * opens on the hero.
     *
     * Every slide's text ships `mo-is-visible`: the slider cross-fades the
     * slide containers, never the titles — a stacked no-JS render stays fully
     * readable, and no bundle timing can leave an empty hero behind.
     */
    public function homepage(PageContext $context): string
    {
        $articles = [] !== $context->latestArticles ? $context->latestArticles : $context->articles;

        if ([] === $articles) {
            $articles = self::latestFallback($context->locale);
        }
        $slides = $this->heroSlide($context) . $this->caseSlides($context, $articles);
        $count = substr_count($slides, '<li class="mo-slide');

        // `mo-hero-slider--multi` lets the stylesheet stage the hero before
        // first paint (see themeInitScript): a lone hero needs no staging —
        // it reads the same stacked or staged — so only 2+ slides opt in.
        $multi = $count >= 2 ? ' mo-hero-slider--multi' : '';

        return '<section class="mo-hero-slider' . $multi . '" data-mo-autoplay="6000" aria-roledescription="carousel">'
            . '<ul class="mo-slides">' . $slides . '</ul>'
            . $this->sliderPag($count)
            . $this->sliderArrows($count)
            . '</section>';
    }

    /**
     * Quiet per-slide tints, cycled — the dark default carries the slide,
     * the light override reverts to warm paper. Colours stay deprioritised,
     * photography stays optional.
     *
     * @var list<array{light: string, dark: string}>
     */
    private const array SLIDE_TINTS = [
        ['light' => '#faf7f0', 'dark' => '#0a0a0a'],
        ['light' => '#f4efe4', 'dark' => '#14110a'],
        ['light' => '#eef2f0', 'dark' => '#0b1210'],
        ['light' => '#f1ecec', 'dark' => '#120d0d'],
    ];

    /**
     * The slider's photography: committed demo art served from the docroot
     * (`public/demo/*.jpg`), cycled by slide position — slide 1 opens on
     * index 0, the case slides follow. Every slide ships one, so the stage
     * reads photographic whatever storage publishes (an article's own cover
     * is not slider art). Three files reused across every slide: ~240 KiB
     * fetched once — the budget the single 157 KiB hero jpg broke when it
     * landed alone on the LCP slide.
     *
     * @var list<string>
     */
    private const array SLIDE_IMAGES = [
        '/demo/hero-1.jpg',
        '/demo/hero-2.jpg',
        '/demo/hero-3.jpg',
    ];

    /**
     * @param array<array-key, mixed> $articles
     */
    private function caseSlides(PageContext $context, array $articles): string
    {
        // `case_service` has no row in the `home` group — nobody translated a
        // "case study" label — so it keeps the theme's own string. Writing a
        // Slovak one here would be authoring content, not wiring copy.
        $service = $context->copy['case_service'] ?? 'Case study';

        // The link label DOES have a row: `home.read_article` is the same slot
        // jednotka's card link reads. The arrow stays in the markup, not in the
        // translated string.
        $readMore = $context->copy['home.read_article'] ?? 'Read More';

        $html = '';
        $i = 1;

        foreach (self::pairs($articles) as $pair) {
            // Demo art by position: the tint index and the photo index move
            // together, so every slide keeps its own pair whatever storage
            // publishes — an article's cover never drives the stage.
            $index = $i++;
            $html .= '<li class="mo-slide"' . self::tint($index) . '>'
                . self::slideBg(self::SLIDE_IMAGES[$index % count(self::SLIDE_IMAGES)], $pair['title'])
                . '<div class="mo-container">'
                . '<p class="mo-slide__kicker">' . self::e($service) . '</p>'
                . '<h2 class="mo-slide__title mo-is-visible">' . self::e($pair['title']) . '</h2>'
                . '<a class="mo-btn mo-btn--outline" href="/blog/' . self::e($pair['slug']) . '">' . self::e($readMore) . ' →</a>'
                . '</div>'
                . '</li>';
        }

        return $html;
    }

    /**
     * The opening slide.
     *
     * ## Which key each slot reads
     *
     * The home surface's own keys come FIRST: `home.title`, `home.message` and
     * `home.cta_start` are the rows the translation admin edits, so a `sk_SK`
     * site renders `Postavte Niečo Skvelé` / `Začať` here. The bare keys
     * (`title`, `lede`, `cta`) stay behind them — this theme read those first
     * and a caller may still pass one — and the strings below are the floor,
     * so a tree with no translation module renders what it always rendered.
     *
     * Measured 2026-10-17: with the site switched to `mono`, this slide
     * answered the SITE NAME as its `<h1>` and `Discover more` for its button
     * while `home.title` and `home.cta_start` held Slovak in the same
     * database. The site name still arrives as `title`, so it remains the
     * answer on a tree where the group carries nothing.
     */
    private function heroSlide(PageContext $context): string
    {
        $copy = $context->copy;

        $title = $copy['home.title'] ?? $copy['title'] ?? 'Home';
        $lede = $copy['home.message'] ?? $copy['lede'] ?? '';
        $cta = $copy['home.cta_start'] ?? $copy['cta'] ?? 'Discover more';
        $href = $context->blogHref ?? '/blog';

        // The action's explicit hero still wins — that is the action asking
        // for one specific photo (and its srcset travels with it). The kernel
        // home passes none, so slide 1 opens on the demo art like every stage
        // after it: never a flat tint, never tied to what storage publishes.
        $heroSrc = $context->heroSrc;
        $heroSrcset = $context->heroSrcset;

        if (null === $heroSrc || '' === $heroSrc) {
            $heroSrc = self::SLIDE_IMAGES[0];
            $heroSrcset = null;
        }

        // `mo-slide--hero` stages the editorial glow behind the photo: it
        // carries the opening frame until the jpg decodes and shows through
        // the shade's transparent side.
        $html = '<li class="mo-slide mo-slide--active mo-slide--hero"' . self::tint(0) . '>'
            . self::slideBg($heroSrc, $title, $heroSrcset, true)
            . '<div class="mo-container">'
            . '<h1 class="mo-slide__title mo-is-visible">' . self::e($title) . '</h1>';

        if ('' !== $lede) {
            $html .= '<p class="mo-slide__lede mo-is-visible">' . self::e($lede) . '</p>';
        }

        return $html
            . '<a class="mo-btn" href="' . self::e($href) . '">' . self::e($cta) . '</a>'
            . '</div>'
            . '</li>';
    }

    private static function tint(int $index): string
    {
        $tint = self::SLIDE_TINTS[$index % count(self::SLIDE_TINTS)];

        return sprintf(
            ' style="--mo-slide-bg: %s; --mo-slide-bg-dark: %s;"',
            $tint['light'],
            $tint['dark'],
        );
    }

    /**
     * Full-bleed slide image + legibility shade, '' when the action provides
     * no source. `alt` carries the slide headline — a background with no
     * `<img>` alternative is invisible to assistive tech.
     */
    private static function slideBg(?string $src, string $alt, ?string $srcset = null, bool $priority = false): string
    {
        if (null === $src || '' === $src) {
            return '';
        }

        $set = null !== $srcset && '' !== $srcset
            ? ' srcset="' . self::e($srcset) . '"'
            : '';

        // The opening slide is the LCP element: fetched now, at high
        // priority. The rest sit off-stage until the slider rotates, so they
        // load late — a stacked no-JS render would otherwise pull every
        // slide's jpg onto the critical path, the cost that dropped the demo
        // photos the first time.
        $load = $priority
            ? ' fetchpriority="high"'
            : ' loading="lazy" decoding="async"';

        return '<img class="mo-slide__bg" src="' . self::e($src) . '"' . $set . $load
            . ' alt="' . self::e($alt) . '">' . "\n"
            . '<div class="mo-slide__shade" aria-hidden="true"></div>';
    }

    private function sliderPag(int $count): string
    {
        if ($count < 2) {
            return '';
        }

        $buttons = '';

        for ($i = 0; $i < $count; $i++) {
            $active = 0 === $i ? ' mo-pag--active' : '';
            $current = 0 === $i ? ' aria-current="true"' : '';
            $buttons .= '<button type="button" class="mo-pag' . $active . '"'
                . ' aria-label="Go to slide ' . ($i + 1) . '"' . $current . '>'
                . '<span class="mo-pag__fill"></span></button>';
        }

        return '<div class="mo-slider__pag">' . $buttons . '</div>';
    }

    private function sliderArrows(int $count): string
    {
        if ($count < 2) {
            return '';
        }

        return '<button type="button" class="mo-icon-btn mo-slider__arrow mo-slider__arrow--prev"'
            . ' aria-label="Previous slide">←</button>'
            . '<button type="button" class="mo-icon-btn mo-slider__arrow mo-slider__arrow--next"'
            . ' aria-label="Next slide">→</button>';
    }

    /**
     * Latest published articles when the action passed none. The kernel
     * HomepageAction carries no Blog import by explicit contract, so it never
     * passes any — without this fallback the slider would stay a lone hero on
     * every tree that actually publishes. Guarded by class_exists: a tree
     * without Blog renders the hero alone, exactly as before. A failure here
     * degrades to empty, never to a white homepage — and it is logged, so the
     * silence stays visible.
     *
     * @return list<object>
     */
    private static function latestFallback(string $locale): array
    {
        $facade = 'Modules\\Blog\\Application\\BlogServices';

        if (!class_exists($facade)) {
            return [];
        }

        try {
            $cards = $facade::articles()->latestCards(6, $locale);
        } catch (\Throwable $e) {
            error_log('[MonoTheme] latest fallback failed: ' . $e->getMessage());

            return [];
        }

        return array_map(static fn (array $card): object => (object) $card, $cards);
    }

    public function blogIndex(PageContext $context): string
    {
        return $this->cardSection('Blog', '', $context->articles, $this->searchForm($context, ''));
    }

    public function blogSearch(PageContext $context): string
    {
        return $this->cardSection(
            'Search: ' . $context->query,
            '',
            $context->results,
            $this->searchForm($context, $context->query),
        );
    }

    public function blogArticle(PageContext $context): string
    {
        $article = '<article class="mo-card"><div class="mo-card__header">'
            . '<h1 class="mo-card__title">' . self::e($context->title) . '</h1>'
            . '</div><div class="mo-card__body">' . $context->bodyHtml . '</div></article>';
        $toc = self::articleToc($context->headings);

        if ('' === $toc) {
            return '<section class="mo-features-section"><div class="mo-container">'
                . $article
                . '</div></section>';
        }

        return '<section class="mo-features-section"><div class="mo-container">'
            . '<div class="mo-article-layout">' . $article . $toc . '</div>'
            . '</div></section>';
    }

    /**
     * The "On this page" aside, rendered from the headings
     * MarkdownRenderer::extractHeadings() collected — the same HTML the
     * browser sees, so every anchor matches a heading id actually present.
     * Server-side links only: no JS needed to jump, and Mono ships none.
     * The data-toc-link / data-heading opt-ins mirror the shared scrollspy
     * contract, so a future Mono scrollspy can hook in without new markup.
     * '' when the article has no h2/h3 — without headings there is nothing
     * to navigate, and an empty aside would ship a labelled nav to nowhere.
     *
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    private static function articleToc(array $headings): string
    {
        $items = '';

        foreach ($headings as $heading) {
            if (!is_array($heading)) {
                continue;
            }

            $anchor = $heading['anchor'] ?? '';
            $text = $heading['text'] ?? '';

            if (!is_string($anchor) || '' === $anchor || !is_string($text) || '' === trim($text)) {
                continue;
            }

            $itemClass = (3 === ($heading['level'] ?? 0)) ? ' mo-toc__item--sub' : '';
            $items .= '<li class="mo-toc__item' . $itemClass . '">'
                . '<a class="mo-toc__link" data-toc-link data-heading="' . self::e($anchor) . '"'
                . ' href="#' . self::e($anchor) . '">' . self::e($text) . '</a></li>';
        }

        if ('' === $items) {
            return '';
        }

        return '<aside class="mo-toc"><nav class="mo-toc__nav" aria-label="On this page">'
            . '<p class="mo-toc__title">On this page</p>'
            . '<ul class="mo-toc__list">' . $items . '</ul>'
            . '</nav></aside>';
    }

    public function articleTail(string $html): string
    {
        return $html;
    }

    public function blogTags(PageContext $context): string
    {
        $title = null !== $context->tag ? 'Tag: ' . $context->tag : 'Tags';

        return $this->cardSection($title, '', $context->articles);
    }

    public function blogCategories(PageContext $context): string
    {
        $title = null !== $context->category ? $context->category : 'Categories';

        return $this->cardSection($title, '', $context->articles);
    }

    public function authorProfile(PageContext $context): string
    {
        return $this->cardSection($this->authorName($context), '', $context->articles);
    }

    public function errorContent(int $status, string $title, string $detail): string
    {
        return '<section class="mo-hero-section">'
            . '<div class="mo-hero-section__content">'
            . '<p class="mo-hero-section__title mo-is-visible">' . $status . '</p>'
            . '<p class="mo-hero-section__description mo-is-visible">' . self::e($title) . ' — ' . self::e($detail) . '</p>'
            . '<div class="mo-hero-section__buttons">'
            . '<a class="mo-btn" href="/">Back to homepage</a>'
            . '</div></div></section>';
    }

    /**
     * @param array<array-key, mixed> $articles article DTOs (slug + title read via get_object_vars, like cards())
     */
    private function cardSection(string $title, string $subtitle, array $articles, string $lead = ''): string
    {
        $html = '<section class="mo-features-section"><div class="mo-container">'
            . '<header class="mo-features-section__header">'
            . '<h1 class="mo-features-section__title">' . self::e($title) . '</h1>';

        if ('' !== $subtitle) {
            $html .= '<p class="mo-features-section__subtitle">' . self::e($subtitle) . '</p>';
        }

        return $html . '</header>' . $lead . $this->cards($articles) . '</div></section>';
    }

    /**
     * The blog's search control, under the title and above the cards.
     *
     * This theme's shell carries no field, so without this the blog would be
     * searchable only by typing `?q=` by hand. The action is the locale's blog
     * base, never `getPathInfo()`: LocaleMiddleware strips the `/en` prefix
     * before routing, so the path here is `/blog` even for an English reader,
     * and posting to it would search the default corpus. Boson's shell builds
     * the same address from the same mapper. The button reuses `mo-btn`, the
     * theme's own control.
     */
    private function searchForm(PageContext $context, string $query): string
    {
        return '<form role="search" method="get" action="'
            . self::e(ContentServices::mapper()->urlPrefix($context->locale) . '/blog') . '">'
            . '<label for="mo-blog-search">Search articles</label>'
            . '<input id="mo-blog-search" name="q" type="search"'
            . ' placeholder="Search articles" value="' . self::e($query) . '" autocomplete="off">'
            . '<button class="mo-btn" type="submit">Search</button>'
            . '</form>';
    }

    /**
     * Slug + title are required; an image is opportunistic (`image`, `cover`
     * or `coverImage` when the producer carries one) — still no import, still
     * no coupling, just one more optional field off the same shape.
     *
     * @param array<array-key, mixed> $articles
     * @return list<array{slug: string, title: string, image: ?string}>
     */
    private static function pairs(array $articles): array
    {
        $pairs = [];

        foreach ($articles as $article) {
            if (!is_object($article)) {
                continue;
            }

            $fields = get_object_vars($article);
            $slug = $fields['slug'] ?? null;
            $title = $fields['title'] ?? null;

            if (is_string($slug) && is_string($title)) {
                $image = $fields['image'] ?? $fields['cover'] ?? $fields['coverImage'] ?? null;
                $pairs[] = [
                    'slug' => $slug,
                    'title' => $title,
                    'image' => is_string($image) && '' !== $image ? $image : null,
                ];
            }
        }

        return $pairs;
    }

    /**
     * @param array<array-key, mixed> $articles
     */
    private function cards(array $articles): string
    {
        $pairs = self::pairs($articles);

        if ([] === $pairs) {
            return '<p>Nothing published yet.</p>';
        }

        $items = '';

        foreach ($pairs as $pair) {
            $items .= '<article class="mo-card mo-card--interactive">'
                . '<div class="mo-card__header">'
                . '<h2 class="mo-card__title"><a class="mo-card__link" href="/blog/'
                . self::e($pair['slug']) . '">' . self::e($pair['title']) . '</a></h2>'
                . '</div></article>';
        }

        return '<div class="mo-card-grid mo-card-grid--cols-3">' . $items . '</div>';
    }

    private function authorName(PageContext $context): string
    {
        $author = $context->author ?? [];
        $name = $author['name'] ?? null;

        return is_string($name) && '' !== $name ? $name : 'Author';
    }

    /**
     * @param list<ShellNavItem> $items
     */
    private function menu(array $items): string
    {
        if ([] === $items) {
            return '';
        }

        $links = '';

        foreach ($items as $item) {
            $links .= $this->menuLink($item);
        }

        return '<div class="mo-navbar__menu">' . $links . '</div>';
    }

    /**
     * The checkbox the meatball label toggles on a phone.
     *
     * The vendored snapshot expects the live site's JS to add
     * `mo-navbar--menu-open` to the navbar; this tree ships no JS, so the open
     * state is a form control and the styling keys off `:checked` instead. The
     * input is first in the container on purpose: the menu and the label both
     * come after it, which is what lets one `~` selector reach them without
     * `:has()`. Rendered only when there is a menu to open — a toggle that
     * opens nothing is worse than no toggle.
     */
    private function menuToggleInput(bool $hasMenu): string
    {
        if (!$hasMenu) {
            return '';
        }

        return '<input type="checkbox" id="mo-nav-toggle" class="mo-navbar__toggle-input" aria-label="Open navigation">';
    }

    /**
     * The dark/light switcher, straight from the upstream navbar__actions
     * (evan70.github.io/mono) with the mo- prefix: sun shows in dark mode,
     * moon in light mode, both in the DOM and CSS showing one at a time.
     *
     * No Mono JS needed — the click is owned by the shared theme-toggle
     * handler FrontendLayout inlines for every theme, which wires every
     * [data-theme-toggle]. The pre-paint restore comes from the same place.
     */
    private static function themeToggle(): string
    {
        return '<button type="button" class="mo-icon-btn mo-theme-toggle" data-theme-toggle aria-label="Toggle theme">'
            . '<svg class="mo-theme-toggle__sun" width="20" height="20" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="12" r="5"></circle>'
            . '<line x1="12" y1="1" x2="12" y2="3"></line>'
            . '<line x1="12" y1="21" x2="12" y2="23"></line>'
            . '<line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>'
            . '<line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>'
            . '<line x1="1" y1="12" x2="3" y2="12"></line>'
            . '<line x1="21" y1="12" x2="23" y2="12"></line>'
            . '<line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>'
            . '<line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>'
            . '</svg>'
            . '<svg class="mo-theme-toggle__moon" width="20" height="20" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>'
            . '</svg>'
            . '</button>';
    }

    /**
     * One dev variant button: the upstream dev-theme-switcher idiom
     * (evan70.github.io/mono) with a custom Mono palette behind it.
     *
     * The first button keeps the upstream `id="dev-theme-switcher"`; both
     * key off `data-variant-toggle`, which is what the vendored tracker
     * reads. `aria-pressed` ships false and is synced from storage by the
     * tracker on load — static markup cannot know the persisted choice.
     */
    private static function variantToggle(string $variant, string $id, string $label, string $icon): string
    {
        return '<button type="button" class="mo-icon-btn mo-navbar__dev-toggle"'
            . ' id="' . $id . '" data-variant-toggle="' . $variant . '"'
            . ' aria-pressed="false" aria-label="' . $label . '" title="' . $label . '">'
            . $icon . '</button>';
    }

    private static function flameIcon(): string
    {
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.292 1-3a2.5 2.5 0 0 0 2.5 2.5z"></path>'
            . '</svg>';
    }

    private static function snowflakeIcon(): string
    {
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<line x1="12" y1="2" x2="12" y2="22"></line>'
            . '<line x1="3.34" y1="7" x2="20.66" y2="17"></line>'
            . '<line x1="20.66" y1="7" x2="3.34" y2="17"></line>'
            . '</svg>';
    }

    /**
     * The meatball itself: the visible control the checkbox's label activates.
     *
     * Both icons ship in the DOM and CSS shows one at a time (the vendored
     * rules already hide `--close` in the closed state), so opening the menu
     * costs no JS and no second request.
     */
    private function menuToggleLabel(bool $hasMenu, string $icon = 'meatball'): string
    {
        if (!$hasMenu) {
            return '';
        }

        $variant = 'burger' === $icon ? ' mo-navbar__toggle--burger' : '';

        return '<label class="mo-navbar__toggle mo-icon-btn' . $variant . '" for="mo-nav-toggle" title="Menu">'
            . '<span class="mo-navbar__toggle-icon mo-navbar__toggle-icon--meatball" aria-hidden="true">'
            . self::meatballIcon() . '</span>'
            . '<span class="mo-navbar__toggle-icon mo-navbar__toggle-icon--burger" aria-hidden="true">'
            . self::burgerIcon() . '</span>'
            . '<span class="mo-navbar__toggle-icon mo-navbar__toggle-icon--close" aria-hidden="true">'
            . self::closeIcon() . '</span>'
            . '</label>';
    }

    /** The opt-in burger: same 22 px box and stroke weight as the meatball. */
    private static function burgerIcon(): string
    {
        return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round">'
            . '<line x1="4" y1="7" x2="20" y2="7"></line>'
            . '<line x1="4" y1="12" x2="20" y2="12"></line>'
            . '<line x1="4" y1="17" x2="20" y2="17"></line>'
            . '</svg>';
    }

    private static function meatballIcon(): string
    {
        return '<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">'
            . '<circle cx="12" cy="5" r="2"></circle>'
            . '<circle cx="12" cy="12" r="2"></circle>'
            . '<circle cx="12" cy="19" r="2"></circle>'
            . '</svg>';
    }

    private static function closeIcon(): string
    {
        return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">'
            . '<path d="M6 6l12 12"></path>'
            . '<path d="M18 6L6 18"></path>'
            . '</svg>';
    }

    private function menuLink(ShellNavItem $item): string
    {
        $link = '<a class="mo-navbar__link' . ($item->active ? ' mo-navbar__link--active' : '') . '"'
            . ' href="' . self::e($item->href) . '"'
            . ($item->external ? ' rel="noopener noreferrer"' : '')
            . '>' . self::e($item->label) . '</a>';

        foreach ($item->children as $child) {
            $link .= $this->menuLink($child);
        }

        return $link;
    }

    /**
     * Locale links as a segmented switch — the idiom this tree already uses for
     * a stateful control (Erik's `.er-locale.m-pubswitch__track`, repeated for
     * its theme toggle), in mono's own class name.
     *
     * The segment label is the SHORT language code (`sk_SK` → `SK`), the form
     * Jednotka ships. The full code made the track 120 px wide, and on a 390 px
     * phone that pushed the control past the right edge of the viewport —
     * measured 2026-10-17 at x 377–497 inside 390 px, clipped by
     * `body{overflow-x:hidden}`, so the language could not be changed at all.
     * `lang` / `hreflang` carry the BCP-47 form (`sk`, not `sk_SK`), so assistive
     * tech pronounces each option in its own language even though the visible
     * word is a code.
     *
     * The active segment is marked from `$activeLocale` — server-side, like
     * every other state in this theme, so the track is correct on first paint
     * and the navbar still ships no JavaScript. The group is a labelled `<nav>`
     * rather than an unlabelled div; `lang.switch` has no path into a theme's
     * shell context (the kernel hands over `availableLocales`, not copy), so the
     * label is the same literal "Language" Jednotka uses.
     */
    private function localeLinks(ShellContext $context): string
    {
        if (count($context->availableLocales) < 2) {
            return '';
        }

        $options = '';

        foreach ($context->availableLocales as $language) {
            $href = $context->localeLinks[$language] ?? ContentServices::mapper()->urlPrefix($language) . $context->currentPath;
            $active = $language === $context->activeLocale;
            $tag = self::languageTag($language);

            $options .= '<a class="mo-lang-switch__option'
                . ($active ? ' mo-lang-switch__option--active' : '')
                . '" href="' . self::e($href) . '"'
                . ' hreflang="' . self::e($tag) . '" lang="' . self::e($tag) . '"'
                . ($active ? ' aria-current="true"' : '')
                . '>' . self::e(strtoupper($tag)) . '</a>';
        }

        return '<nav class="mo-lang-switch" aria-label="Language">' . $options . '</nav>';
    }

    /** `sk_SK` → `sk` — the kernel's locale convention, and a valid `hreflang`. */
    private static function languageTag(string $code): string
    {
        return strtolower((string) explode('_', $code)[0]);
    }

    /**
     * @param list<ShellNavItem> $items
     */
    private function footerLinks(array $items): string
    {
        if ([] === $items) {
            return '';
        }

        $links = '';

        foreach ($items as $item) {
            $links .= '<a class="mo-footer__link" href="' . self::e($item->href) . '">'
                . self::e($item->label) . '</a>';
        }

        return '<div>' . $links . '</div>';
    }

    private static function e(mixed $value): string
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
