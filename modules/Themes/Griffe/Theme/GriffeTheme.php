<?php

declare(strict_types = 1);

namespace Modules\Themes\Griffe\Theme;

use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Domain\ThemeSetting;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Themes\ThemeModule;
use Themes\ThemeSettings;

/**
 * GriffeTheme — the webgriffe.com agency idiom as a kernel theme module.
 *
 * Sticky text-nav header, fullscreen home slider (hero slide + case slides),
 * line-mask scroll reveals, case-study grid, minimal legal footer. The CSS is
 * hand-written, framework-free (see assets/critical/griffe.css): the reference
 * runs on Xtend UI, but this port re-expresses only the used behaviours, so
 * Tailwind/JS-framework vending is deliberately absent. Colours are neutral
 * placeholders by decision — layout and motion first.
 *
 * It imports nothing from a feature module. Article shapes are read through
 * `get_object_vars` rather than `use`ing the entity, the established kernel
 * pattern for a theme that must not name the module owning the article.
 */
final class GriffeTheme implements ThemeModule, ThemeSettings
{
    public function name(): string
    {
        return 'griffe';
    }

    public function bundle(): string
    {
        return 'griffe';
    }

    public function jsKey(): string
    {
        return 'griffe';
    }

    /**
     * @return list<string> src/-relative, resolved by InlineCss
     */
    public function criticalCss(): array
    {
        return ['Modules/Griffe/assets/critical/griffe.css'];
    }

    /**
     * Pre-paint colour-mode resolution (the shared dark/light restore,
     * verbatim).
     *
     * The second script used to add the `gr-js` staging signal for the griffe
     * multi-slide hero. That surface is gone — the homepage is the resp-front
     * landing, whose own component stages itself — so the signal, the stylesheet
     * rules it gated and the pre-paint script are all removed with it: one fewer
     * render-blocking `<script>` in `<head>` on every griffe page.
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
            HTML;
    }

    public function prefix(): string
    {
        return 'gr';
    }

    /**
     * Griffe's one admin choice: which glyph the phone menu toggle draws.
     *
     * `auto` keeps the meatball this header was ported with, so an install
     * that never opens the settings screen renders what it always did. Both
     * closed-state glyphs ship in the DOM and CSS shows one — opening the menu
     * still costs no JS and no second request.
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

    /**
     * Sticky text-nav header in the reference's shape: brand left, text links
     * (Work, Services, Blog, About, Contact come from the menu), theme toggle
     * + locale + hamburger right. The hide-on-scroll half is CSS + gr-header.js
     * (pageWrap, ungated — the header always renders); the mobile menu is a
     * checkbox like Mono's meatball, so it opens with no JS at all.
     *
     * DOM order is the `~` contract: the input precedes `.gr-header__inner`,
     * which holds both the menu and the label that drives it.
     */
    public function header(ShellContext $context): string
    {
        // The landing surface is the resp-front `hero-layout`, which owns its
        // own fixed header. Rendering the griffe header too would stack two
        // navigation bars above the same hero, so the homepage opts out
        // (same for the footer below). Every other path keeps the chrome.
        if ('/' === $context->currentPath) {
            return '';
        }

        $hasMenu = [] !== $context->headerNav;
        // `auto` is this theme's own glyph (the meatball the header was ported
        // with); only an explicit `burger` switches the closed icon.
        $icon = 'burger' === ($context->themeSettings['nav_toggle_icon'] ?? 'auto') ? 'burger' : 'meatball';

        return '<header class="gr-header">' . "\n"
            . self::menuToggleInput($hasMenu)
            . '<div class="gr-container gr-header__inner">' . "\n"
            . '<a class="gr-header__brand" href="/">' . self::e($context->siteName) . '</a>' . "\n"
            . $this->menu($context->headerNav)
            . '<div class="gr-header__actions">' . "\n"
            . self::themeToggle()
            . $this->localeLinks($context)
            . self::menuToggleLabel($hasMenu, $icon)
            . '</div>' . "\n"
            . '</div>' . "\n"
            . '</header>' . "\n";
    }

    /**
     * Minimal legal footer: © line, footer nav (Privacy/Cookie/Legal come
     * from the menu), locale pair — the reference's `it`/`en` switcher shape.
     */
    public function footer(ShellContext $context): string
    {
        // `hero-layout` ships its own fixed footer on the landing surface.
        if ('/' === $context->currentPath) {
            return '';
        }

        return '<footer class="gr-footer"><div class="gr-container gr-footer__inner">'
            . '<span>© ' . self::e($context->year) . ' ' . self::e($context->siteName) . '</span>'
            . $this->footerLinks($context->footerNav)
            . $this->localeLinks($context)
            . '</div></footer>' . "\n";
    }

    public function pageWrap(string $content): string
    {
        // No inline scripts here: the four behaviours this wrap used to gate
        // per surface (header, slider, reveal, highlight) ship in the frozen
        // `griffe` bundle FrontendLayout defers from the manifest. Each init
        // self-gates on its markup, so a page without a slider runs nothing.
        return '<main class="gr-main">' . "\n"
            . '    ' . $content . "\n"
            . '</main>' . "\n";
    }

    /**
     * The landing page adopted from `Frontend/resp-front`: a fullscreen
     * horizontal-scroll hero (`hero-layout` + `horizontal-scroll-hero`).
     *
     * These are the page's only chrome: `header()` and `footer()` return ''
     * on `/` because the component ships a fixed header and footer of its own.
     *
     * Slides are content-driven — slide 1 is the copy hero, slides 2+ are the
     * latest articles (falling back to published ones), exactly the payload
     * the old griffe slider consumed. They travel as the component's `slides`
     * JSON attribute; the element's own reader turns the string into the
     * array (the attributes are its public contract, not a framework's).
     * Each value is escaped for the double-quoted attribute, so copy
     * containing quotes or `<` cannot break out of it.
     */
    public function homepage(PageContext $context): string
    {
        $articles = [] !== $context->latestArticles ? $context->latestArticles : $context->articles;

        if ([] === $articles) {
            $articles = self::latestFallback($context->locale);
        }

        $siteName = $context->copy['title'] ?? 'Nativa';
        $service = $context->copy['case_service'] ?? 'Case study';

        $slides = [[
            'id' => 'hero',
            'tag' => $context->copy['home.eyebrow'] ?? $context->copy['hero_eyebrow'] ?? '',
            'titleLine1' => $context->copy['home.hero_title'] ?? $context->copy['hero_title'] ?? $siteName,
            'subtitle' => $context->copy['home.hero_subtitle'] ?? $context->copy['lede'] ?? '',
            'link' => $context->blogHref ?? '/blog',
            'backgroundImage' => $context->heroSrc ?? '',
            'themeColor' => '#0d1119',
            'buttonText' => $context->copy['home.cta_blog'] ?? $context->copy['cta'] ?? 'Read the blog',
        ]];

        foreach (self::pairs($articles) as $pair) {
            $slides[] = [
                'id' => $pair['slug'],
                'tag' => $service,
                'titleLine1' => $pair['title'],
                'link' => '/blog/' . $pair['slug'],
                'backgroundImage' => $pair['image'] ?? '',
                'themeColor' => '#0d1119',
                'buttonText' => $context->copy['home.read_article'] ?? 'Read article',
            ];
        }

        // The admin prefix is a policy answer, never a literal: `/mark` is
        // `AdminRoutePolicy`'s to spell (`ADMIN_PATH` can move it).
        $navigation = [
            ['label' => 'Home', 'href' => '/'],
            ['label' => 'Blog', 'href' => '/blog'],
            ['label' => 'Admin', 'href' => AdminRoutePolicy::prefix()],
        ];

        return '<hero-layout'
            . ' logo-text="' . self::e($siteName) . '"'
            . ' logo-link="/"'
            . ' navigation="' . self::e(self::json($navigation)) . '"'
            . ' social-links="' . self::e(self::json([])) . '"'
            . ' copyright="' . self::e('© ' . date('Y') . ' ' . $siteName) . '">'
            . '<horizontal-scroll-hero'
            . ' slides="' . self::e(self::json($slides)) . '"'
            . ' autoplay-interval="6000" show-navigation show-scroll-hint>'
            . '</horizontal-scroll-hero>'
            . '</hero-layout>';
    }

    /**
     * JSON for a custom-element attribute. Slashes and unicode stay readable;
     * the caller still runs it through `e()` so `"` becomes `&quot;` inside the
     * attribute.
     *
     * @param array<array-key, mixed> $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Neutral per-slide tints, cycled (the reference tints every slide via
     * `data-light`/`data-dark`; no action passes tints, so the theme owns a
     * quiet rotation — colours stay deprioritised, photography stays optional).
     *
     * @var list<array{light: string, dark: string}>
     */
    private const array SLIDE_TINTS = [
        ['light' => '#eef0f4', 'dark' => '#151a24'],
        ['light' => '#f4efe8', 'dark' => '#1a1611'],
        ['light' => '#e9f0ef', 'dark' => '#101a18'],
        ['light' => '#f0eaf0', 'dark' => '#181420'],
    ];

    /**
     * @param array<array-key, mixed> $articles
     */
    private function caseSlides(PageContext $context, array $articles): string
    {
        $service = $context->copy['case_service'] ?? 'Case study';
        $html = '';
        $i = 1;

        foreach (self::pairs($articles) as $pair) {
            $html .= '<li class="gr-slide"' . self::tint($i++) . '>'
                . self::slideBg($pair['image'], $pair['title'])
                . '<div class="gr-container">'
                . '<p class="gr-slide__kicker">' . self::e($service) . '</p>'
                . '<h2 class="gr-slide__title">' . self::revealLines($pair['title']) . '</h2>'
                . '<a class="gr-btn gr-btn--link" href="/blog/' . self::e($pair['slug']) . '">Read More →</a>'
                . '</div>'
                . '</li>';
        }

        return $html;
    }

    /**
     * Slide 1, the editorial hero in the nativa-minimal Erik hero shape:
     * eyebrow kicker, display title, lede, two actions, line-art visual.
     * Text is copy-driven (showcase strings by default); the primary action
     * goes to the blog, the secondary to the admin surface the kernel owns.
     * The visual is the inline line-art twin (gradient id gr-glow) — the
     * same self-contained geometry as the Erik port, no external asset.
     */
    private function heroSlide(PageContext $context): string
    {
        $eyebrow = $context->copy['hero_eyebrow'] ?? '';
        $title = $context->copy['title'] ?? 'Home';
        $lede = $context->copy['lede'] ?? '';
        $cta = $context->copy['cta'] ?? 'Discover more';
        $href = $context->blogHref ?? '/blog';

        $html = '<li class="gr-slide gr-slide--active"' . self::tint(0) . '>'
            . self::slideBg($context->heroSrc, $title, $context->heroSrcset)
            . self::heroVisual()
            . '<div class="gr-container">';

        if ('' !== $eyebrow) {
            $html .= '<p class="gr-slide__kicker">' . self::e($eyebrow) . '</p>';
        }

        // The branch's composition, master's argument. These are not two answers
        // to one question: the branch adds the decorative stage (visual +
        // kicker), while master changed revealLines() to take a second argument
        // so the hero can pre-paint revealed. Taking the branch's one-argument
        // call would silently undo master's change, which is the whole reason
        // the conflict is here.
        $html .= '<h1 class="gr-slide__title">' . self::revealLines($title, true) . '</h1>';

        if ('' !== $lede) {
            $html .= '<p class="gr-slide__lede">' . self::e($lede) . '</p>';
        }

        return $html
            . '<div class="gr-slide__actions">'
            . '<a class="gr-btn" href="' . self::e($href) . '">' . self::e($cta) . '</a>'
            . '<a class="gr-btn gr-btn--link" href="/mark">Open admin</a>'
            . '</div>'
            . '</div>'
            . '</li>';
    }

    /**
     * Full-stage line-art behind the hero copy: glow disc, terrain
     * polyline, two rails, two nodes. Decorative by contract
     * (aria-hidden visual container), content stays in `.gr-container`.
     */
    private static function heroVisual(): string
    {
        return '<div class="gr-slide__visual" aria-hidden="true">'
            . '<svg class="gr-slide__svg" viewBox="0 0 900 620" focusable="false">'
            . '<defs><linearGradient id="gr-glow" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="#d9f99d" stop-opacity=".9"/>'
            . '<stop offset="1" stop-color="#bef264" stop-opacity=".05"/>'
            . '</linearGradient></defs>'
            . '<circle cx="680" cy="170" r="150" fill="url(#gr-glow)" opacity=".35"/>'
            . '<path d="M80 470 310 240l110 110 180-210 220 240" fill="none" stroke="currentColor" stroke-width="2" opacity=".5"/>'
            . '<path d="M80 510h720M80 550h560" fill="none" stroke="currentColor" stroke-width="1" opacity=".3"/>'
            . '<circle cx="310" cy="240" r="7" fill="#d9f99d"/><circle cx="600" cy="140" r="7" fill="#d9f99d"/>'
            . '</svg></div>';
    }

    private static function tint(int $index): string
    {
        $tint = self::SLIDE_TINTS[$index % count(self::SLIDE_TINTS)];

        return sprintf(
            ' style="--gr-slide-bg: %s; --gr-slide-bg-dark: %s;"',
            $tint['light'],
            $tint['dark'],
        );
    }

    /**
     * Full-bleed slide image + legibility shade, '' when the action provides
     * no source. `alt` carries the slide headline — a background with no
     * `<img>` alternative is invisible to assistive tech.
     */
    private static function slideBg(?string $src, string $alt, ?string $srcset = null): string
    {
        if (null === $src || '' === $src) {
            return '';
        }

        $set = null !== $srcset && '' !== $srcset
            ? ' srcset="' . self::e($srcset) . '"'
            : '';

        return '<img class="gr-slide__bg" src="' . self::e($src) . '"' . $set
            . ' alt="' . self::e($alt) . '">' . "\n"
            . '<div class="gr-slide__shade" aria-hidden="true"></div>';
    }

    private function sliderPag(int $count): string
    {
        if ($count < 2) {
            return '';
        }

        $buttons = '';

        for ($i = 0; $i < $count; $i++) {
            $active = 0 === $i ? ' gr-pag--active' : '';
            $current = 0 === $i ? ' aria-current="true"' : '';
            $buttons .= '<button type="button" class="gr-pag' . $active . '"'
                . ' aria-label="Go to slide ' . ($i + 1) . '"' . $current . '>'
                . '<span class="gr-pag__fill"></span></button>';
        }

        return '<div class="gr-slider__pag">' . $buttons . '</div>';
    }

    private function sliderArrows(int $count): string
    {
        if ($count < 2) {
            return '';
        }

        return '<button type="button" class="gr-icon-btn gr-slider__arrow gr-slider__arrow--prev"'
            . ' aria-label="Previous slide">←</button>'
            . '<button type="button" class="gr-icon-btn gr-slider__arrow gr-slider__arrow--next"'
            . ' aria-label="Next slide">→</button>';
    }

    /**
     * Line-mask reveal spans: server-side cannot know the rendered line
     * breaks, so copy is chunked into short masked units that reveal one
     * after another — the reference's line-by-line shape without measuring
     * text. Five words per unit keeps display headings near two units.
     *
     * `$revealed` is the opening hero's opt-out: its slide is already on
     * stage in the first paint, so its lines ship `gr-in` and never depend
     * on the bundle or the observer's first tick — no script timing (defer
     * position included) can leave an empty hero behind. Everywhere else the
     * mask stays: hidden slides unmask on activation, scrolled-to blocks on
     * intersection. The entrance animation is intentionally lost on the hero
     * alone — guaranteed visibility beats it.
     */
    private static function revealLines(string $text, bool $revealed = false): string
    {
        $words = preg_split('/\s+/u', trim($text));

        if (false === $words || [] === $words || '' === $words[0]) {
            return '';
        }

        $units = array_chunk($words, 5);
        $html = '';
        $open = $revealed ? 'gr-anim gr-in' : 'gr-anim';

        foreach ($units as $unit) {
            // A newline between the masked units: the spans are blocks, so it
            // paints nothing — but without a text boundary the last word of
            // one unit and the first of the next copy as one
            // ("ktoré"+"dokáže,"), and assistive tech loses the pause.
            $html .= "\n" . '<span class="' . $open . '"><span class="gr-anim__line">'
                . self::e(implode(' ', $unit))
                . '</span></span>';
        }

        return $html;
    }

    /**
     * Latest published articles when the action passed none. The kernel
     * HomepageAction carries no Blog import by explicit contract, so it never
     * passes any — without this fallback the Selected-work section would stay
     * empty on every tree that actually publishes. Guarded by class_exists:
     * a tree without Blog renders the hero alone, exactly as before. A
     * failure here degrades to empty, never to a white homepage — and it is
     * logged, so the silence stays visible.
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
            error_log('[GriffeTheme] latest fallback failed: ' . $e->getMessage());

            return [];
        }

        return array_map(static fn (array $card): object => (object) $card, $cards);
    }

    private static function caseImg(?string $src, string $alt): string
    {
        if (null === $src || '' === $src) {
            return '';
        }

        return '<img class="gr-case__img" loading="lazy" src="' . self::e($src) . '"'
            . ' alt="' . self::e($alt) . '">';
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
     * The case-study grid below the slider, reusing the slide mapping.
     *
     * @param array<array-key, mixed> $articles
     */
    private function caseGrid(PageContext $context, array $articles): string
    {
        $pairs = self::pairs($articles);
        $service = $context->copy['case_service'] ?? 'Case study';

        $html = '<section class="gr-cases"><div class="gr-container">'
            . '<h2 class="gr-section__title">' . self::e($context->copy['cases_title'] ?? 'Selected work') . '</h2>'
            . '<p class="gr-section__sub">' . self::e($context->copy['cases_sub'] ?? '') . '</p>';

        if ([] === $pairs) {
            return $html . '<p class="gr-empty">Nothing published yet.</p></div></section>';
        }

        $cards = '';

        foreach ($pairs as $pair) {
            $cards .= '<article class="gr-case">'
                . self::caseImg($pair['image'], $pair['title'])
                . '<h3 class="gr-case__client">' . self::e($pair['title']) . '</h3>'
                . '<p class="gr-case__service">' . self::e($service) . '</p>'
                . '<a class="gr-case__more" href="/blog/' . self::e($pair['slug']) . '">Read More →</a>'
                . '</article>';
        }

        return $html . '<div class="gr-case-grid">' . $cards . '</div></div></section>';
    }

    public function blogIndex(PageContext $context): string
    {
        return self::section(
            'Blog',
            self::searchForm($context, '') . $this->caseCards($context->articles) . self::pager($context),
        );
    }

    public function blogSearch(PageContext $context): string
    {
        return self::section(
            'Search: ' . $context->query,
            self::searchForm($context, $context->query) . self::links($context->results),
        );
    }

    /**
     * The blog's search control, under the title and above the cards.
     *
     * This theme's shell carries no field, so without this the blog would be
     * searchable only by typing `?q=` by hand. The action is the locale's blog
     * base, never `getPathInfo()`: LocaleMiddleware strips the `/en` prefix
     * before routing, so the path here is `/blog` even for an English reader,
     * and posting to it would search the default corpus. Boson's shell builds
     * the same address from the same mapper. The button reuses `gr-btn`, the
     * theme's own control.
     */
    private static function searchForm(PageContext $context, string $query): string
    {
        return '<form role="search" method="get" action="'
            . self::e(ContentServices::mapper()->urlPrefix($context->locale) . '/blog') . '">'
            . '<label for="gr-blog-search">Search articles</label>'
            . '<input id="gr-blog-search" name="q" type="search"'
            . ' placeholder="Search articles" value="' . self::e($query) . '" autocomplete="off">'
            . '<button class="gr-btn" type="submit">Search</button>'
            . '</form>';
    }

    /**
     * Article anatomy (Mono's shape, renamed): H1 + meta line + cover +
     * body + tag chips + TOC aside + views + comments. Raw HTML fields
     * (`bodyHtml`, `metaLine`, `tagChips`, `commentsHtml`) pass through by
     * contract; everything else escapes through `e()`.
     */
    public function blogArticle(PageContext $context): string
    {
        $article = '<article class="gr-article">'
            . '<p class="gr-slide__kicker">Article</p>'
            . '<h1 class="gr-section__title">' . self::revealLines($context->title, true) . '</h1>';

        if ('' !== $context->metaLine) {
            // Raw by contract: BlogArticleHelper::metaLine() escapes the date
            // and category itself and embeds the pre-built author link — the
            // same bytes the starter renders unescaped in st-article__meta.
            // Escaping here would print the author anchor as visible tag text.
            $article .= '<p class="gr-article__meta">' . $context->metaLine . '</p>';
        }

        $cover = '' !== $context->coverUrl ? $context->coverUrl : $context->coverImage;

        if ('' !== $cover) {
            $article .= '<p><img src="' . self::e($cover) . '" alt=""></p>';
        }

        $article .= '<div class="gr-article__body">' . $context->bodyHtml . '</div>';

        if ('' !== $context->tagChips) {
            $article .= '<div class="gr-cloud">' . $context->tagChips . '</div>';
        }

        if (0 < $context->views) {
            $article .= '<p class="gr-article__meta">' . $context->views . ' views</p>';
        }

        $article .= $context->commentsHtml . '</article>';
        $toc = self::articleToc($context->headings);

        if ('' !== $toc) {
            $article .= $toc;
        }

        return '<section class="gr-cases"><div class="gr-container">' . $article . '</div></section>';
    }

    /**
     * The "On this page" aside from `MarkdownRenderer::extractHeadings()` —
     * server-side anchors only, '' when there is nothing to navigate. The
     * `data-toc-link` / `data-heading` opt-ins mirror the shared contract.
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

            $itemClass = (3 === ($heading['level'] ?? 0)) ? ' gr-toc__item--sub' : '';
            $items .= '<li class="gr-toc__item' . $itemClass . '">'
                . '<a class="gr-toc__link" data-toc-link data-heading="' . self::e($anchor) . '"'
                . ' href="#' . self::e($anchor) . '">' . self::e($text) . '</a></li>';
        }

        if ('' === $items) {
            return '';
        }

        return '<aside class="gr-toc"><nav aria-label="On this page">'
            . '<p class="gr-toc__title">On this page</p>'
            . '<ul class="gr-toc__list">' . $items . '</ul>'
            . '</nav></aside>';
    }

    public function articleTail(string $html): string
    {
        return $html;
    }

    public function blogTags(PageContext $context): string
    {
        $title = null !== $context->tag ? 'Tag: ' . $context->tag : 'Tags';

        return self::section($title, self::cloud($context->tags, $context->counts, 'tag', $context->tag)
            . self::links($context->articles));
    }

    public function blogCategories(PageContext $context): string
    {
        $title = null !== $context->category ? $context->category : 'Categories';

        return self::section($title, self::cloud($context->categories, $context->counts, 'category', $context->category)
            . self::links($context->articles));
    }

    /**
     * @param list<string>       $terms
     * @param array<string, int> $counts
     */
    private static function cloud(array $terms, array $counts, string $kind, ?string $active): string
    {
        if ([] === $terms) {
            return '';
        }

        $chips = '';

        foreach ($terms as $term) {
            $label = $term;
            $count = $counts[$term] ?? null;

            if (null !== $count) {
                $label .= ' (' . $count . ')';
            }

            $chips .= '<a class="gr-chip' . ($term === $active ? ' gr-chip--active' : '') . '"'
                . ' href="/blog/' . $kind . '/' . self::e($term) . '">'
                . self::e($label) . '</a>';
        }

        return '<div class="gr-cloud">' . $chips . '</div>';
    }

    public function authorProfile(PageContext $context): string
    {
        $author = $context->author ?? [];
        $name = $author['name'] ?? null;
        $name = is_string($name) && '' !== $name ? $name : 'Author';
        $avatar = $author['avatar'] ?? null;

        $head = '<h1 class="gr-section__title">' . self::e($name) . '</h1>';

        if (is_string($avatar) && '' !== $avatar) {
            $head .= '<p><img src="' . self::e($avatar) . '" alt=""></p>';
        }

        if ('' !== $context->memberSince) {
            $head .= '<p class="gr-article__meta">Member since ' . self::e($context->memberSince) . '</p>';
        }

        return '<section class="gr-cases"><div class="gr-container">' . $head
            . $this->caseCards($context->articles) . '</div></section>';
    }

    public function errorContent(int $status, string $title, string $detail): string
    {
        return '<section class="gr-cases"><div class="gr-container">'
            . '<p class="gr-slide__kicker">Error ' . $status . '</p>'
            . '<h1 class="gr-section__title">' . self::e($title) . '</h1>'
            . '<p>' . self::e($detail) . '</p>'
            . '<p><a class="gr-btn" href="/">Back to homepage</a></p>'
            . '</div></section>';
    }

    /**
     * Article cards in the case idiom (client → title, Read More → slug).
     *
     * @param array<array-key, mixed> $articles
     */
    private function caseCards(array $articles): string
    {
        $pairs = self::pairs($articles);

        if ([] === $pairs) {
            return '<p class="gr-empty">Nothing published yet.</p>';
        }

        $cards = '';

        foreach ($pairs as $pair) {
            $cards .= '<article class="gr-case">'
                . '<h2 class="gr-case__client">' . self::e($pair['title']) . '</h2>'
                . '<a class="gr-case__more" href="/blog/' . self::e($pair['slug']) . '">Read More →</a>'
                . '</article>';
        }

        return '<div class="gr-case-grid">' . $cards . '</div>';
    }

    private static function pager(PageContext $context): string
    {
        if ($context->totalPages < 2) {
            return '';
        }

        $html = '<nav class="gr-cloud" aria-label="Pages">';

        for ($i = 1; $i <= $context->totalPages; $i++) {
            $html .= '<a class="gr-chip' . ($i === $context->page ? ' gr-chip--active' : '') . '"'
                . ' href="?page=' . $i . '">' . $i . '</a>';
        }

        return $html . '</nav>';
    }

    private static function section(string $title, string $body): string
    {
        return '<section class="gr-cases"><div class="gr-container">'
            . '<h1 class="gr-section__title">' . self::e($title) . '</h1>'
            . $body
            . '</div></section>';
    }

    /**
     * @param array<array-key, mixed> $articles
     */
    private static function links(array $articles): string
    {
        $pairs = self::pairs($articles);

        if ([] === $pairs) {
            return '<p class="gr-empty">Nothing published yet.</p>';
        }

        $items = '';

        foreach ($pairs as $pair) {
            $items .= '<li><a href="/blog/' . self::e($pair['slug']) . '">'
                . self::e($pair['title']) . '</a></li>';
        }

        return '<ul class="gr-list">' . $items . '</ul>';
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

        return '<nav class="gr-nav" aria-label="Primary">' . $links . '</nav>' . "\n";
    }

    private function menuLink(ShellNavItem $item): string
    {
        $link = '<a class="gr-nav__link" href="' . self::e($item->href) . '"'
            . ($item->active ? ' aria-current="page"' : '')
            . ($item->external ? ' rel="noopener noreferrer"' : '')
            . '>' . self::e($item->label) . '</a>';

        foreach ($item->children as $child) {
            $link .= $this->menuLink($child);
        }

        return $link;
    }

    /**
     * The checkbox the hamburger label toggles on a phone (Mono meatball
     * idiom): the input is first so one `~` selector reaches the menu and
     * the label alike. Rendered only when there is a menu to open.
     */
    private static function menuToggleInput(bool $hasMenu): string
    {
        if (!$hasMenu) {
            return '';
        }

        return '<input type="checkbox" id="gr-nav-toggle" class="gr-nav__toggle-input" aria-label="Open navigation">' . "\n";
    }

    /**
     * The menu label: both closed-state glyphs and the close glyph ship, CSS
     * shows one at a time.
     *
     * The choice is a modifier on the LABEL (`--burger`), not a second markup
     * path: the checkbox, the `~` selectors that open the menu and the close
     * glyph are the contract, and only the closed glyph changes.
     */
    private static function menuToggleLabel(bool $hasMenu, string $icon = 'meatball'): string
    {
        if (!$hasMenu) {
            return '';
        }

        $variant = 'burger' === $icon ? ' gr-nav__toggle--burger' : '';

        return '<label class="gr-nav__toggle gr-icon-btn' . $variant . '" for="gr-nav-toggle" title="Menu">'
            . '<span class="gr-nav__toggle-icon--meatball" aria-hidden="true">'
            . '<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">'
            . '<circle cx="12" cy="5" r="2"></circle>'
            . '<circle cx="12" cy="12" r="2"></circle>'
            . '<circle cx="12" cy="19" r="2"></circle>'
            . '</svg></span>'
            . '<span class="gr-nav__toggle-icon--burger" aria-hidden="true">'
            . '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round">'
            . '<line x1="4" y1="7" x2="20" y2="7"></line>'
            . '<line x1="4" y1="12" x2="20" y2="12"></line>'
            . '<line x1="4" y1="17" x2="20" y2="17"></line>'
            . '</svg></span>'
            . '<span class="gr-nav__toggle-icon--close" aria-hidden="true">'
            . '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round">'
            . '<path d="M6 6l12 12"></path><path d="M18 6L6 18"></path>'
            . '</svg></span>'
            . '</label>' . "\n";
    }

    /**
     * The shared dark/light switcher (sun in dark mode, moon in light mode).
     * No Griffe JS needed — the click is owned by the theme-toggle handler
     * FrontendLayout inlines for every theme, which wires every
     * `[data-theme-toggle]`; the pre-paint restore comes from the same place.
     */
    private static function themeToggle(): string
    {
        return '<button type="button" class="gr-icon-btn gr-theme-toggle" data-theme-toggle aria-label="Toggle theme">'
            . '<svg class="gr-theme-toggle__sun" width="20" height="20" viewBox="0 0 24 24" fill="none"'
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
            . '<svg class="gr-theme-toggle__moon" width="20" height="20" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>'
            . '</svg>'
            . '</button>' . "\n";
    }

    private function localeLinks(ShellContext $context): string
    {
        if (count($context->availableLocales) < 2) {
            return '';
        }

        $links = '';

        foreach ($context->availableLocales as $language) {
            $href = $context->localeLinks[$language] ?? ContentServices::mapper()->urlPrefix($language) . $context->currentPath;

            $links .= '<a class="gr-locale__link" href="' . self::e($href) . '"'
                . ($language === $context->activeLocale ? ' aria-current="true"' : '')
                . '>' . self::e($language) . '</a>';
        }

        return '<div class="gr-locale">' . $links . '</div>' . "\n";
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
            $links .= '<a href="' . self::e($item->href) . '">'
                . self::e($item->label) . '</a>';
        }

        return '<div class="gr-footer__links">' . $links . '</div>';
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
