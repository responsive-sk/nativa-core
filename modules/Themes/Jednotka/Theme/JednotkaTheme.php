<?php

declare(strict_types = 1);

namespace Modules\Themes\Jednotka\Theme;

use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Application\Services\View\InlineJs;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Domain\ThemeSetting;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Themes\ThemeModule;
use Themes\ThemeSettings;

/**
 * JednotkaTheme — the `jednotka` design, carried as a kernel theme module.
 *
 * "Jednotka" is Slovak for a unit, and the theme is built on that: a warm paper
 * ground, ink hairlines instead of shadows, a numbered rail down the left of
 * every region, uppercase mono labels, and hover as a colour change on text
 * links — the ink/paper inversion is reserved for BOXED CONTROLS (buttons,
 * chips, pagination, the locale switch).
 *
 * ## It imports nothing from a feature module
 *
 * The showcase original (`nativa-minimal/src/Themes/jednotka`, 13 files) `use`d
 * `Modules\Blog`, `Modules\Locales` and `Modules\Settings`. A theme that names
 * the module owning an article is the coupling the kernel exists to avoid, so
 * every one of those reads goes one of two ways here — the same two `ErikTheme`
 * and `MonoTheme` already use:
 *
 * - **Shapes and data come from the kernel.** Article objects are read through
 *   `get_object_vars`, never `use`d; nav items are `ShellNavItem`s the kernel
 *   put on `ShellContext` (the showcase read `Settings`' `MenuItemDTO`);
 *   counts and copy arrive on `PageContext`.
 * - **Behaviours that are genuinely Blog's are called through a guarded string
 *   FQCN** (`class_exists` + a literal type name, never an import): the card
 *   grid (`renderCards`), the feed URLs (`feedLinks`), the view counts
 *   (`viewCounts`) and their formatting (`formatCount`). A tree without Blog
 *   still renders — the grid degrades to a link list, the feeds and counts
 *   simply disappear. No edge enters this module's install closure, and a
 *   missing Blog is a thinner page rather than a fatal one.
 *
 * ## Shape of the port
 *
 * One file, like every other theme in this tree (`MonoTheme`, `ErikTheme`): the
 * showcase's `Chrome/*`, `Sections/*` and `JednotkaRenderer` are private methods
 * here, not sibling classes.
 *
 * Three deliberate divergences from the original, each because the kernel hands
 * over less than the showcase did:
 *
 * 1. **The homepage is not gated on a hero image.** The showcase returned the
 *    whole body only `'' !== $context->heroSrc`; nothing in this kernel sets
 *    `heroSrc` (`HomepageAction` passes `copy: ['title' => …]`), so a literal
 *    port would answer 200 with an empty page. The hero renders without its
 *    media plate instead — the same move `ErikTheme` makes when it drops the
 *    WebP underlay the kernel must never fetch.
 * 2. **The home copy is the theme's, as a default.** This kernel's
 *    `HomepageAction` passes `copy: ['title' => $siteName]` — no home copy at
 *    all — so a faithful port would render headings with nothing under them.
 *    The showcase's own strings (its `COPY_DEFAULTS` and seed rows for exactly
 *    these keys) therefore live here as `DEFAULT_COPY`, and the card LAYOUT as
 *    `DEFAULT_LAYERS`, because this kernel passes no `cards` map either. The
 *    kernel's copy wins the moment it resolves some; the theme is the floor,
 *    the same shape `ErikTheme` and `MonoTheme` use for their sections. A unit
 *    with genuinely no heading is still omitted rather than emptied.
 * 3. **No avatar service.** The showcase called `BlogServices::avatar()->img()`;
 *    there is no kernel equivalent, so the profile renders an image only when
 *    the kernel hands a URL over, and otherwise renders no image at all.
 *
 * ## The build it declares
 *
 * `bundle()` is the directory under `public/dist/` (`jednotka`), `jsKey()` is
 * the MANIFEST ENTRY inside it — `main`, because this package's Vite entry is
 * `src/main.ts`. They are different values on purpose: `erik`'s entry happens to
 * be named after its theme, this one is not, and the two are read by different
 * consumers (`ThemeBundleDiscoveryTest` resolves the bundle, the layout inlines
 * the entry's stylesheet).
 */
final class JednotkaTheme implements ThemeModule, ThemeSettings
{
    /** The Blog façade — a string, so no `use` enters the install closure. */
    private const string BLOG_FACADE = 'Modules\\Blog\\Application\\BlogServices';

    /** Static card/feed/count helpers, same reason. */
    private const string BLOG_HELPER = 'Modules\\Blog\\Application\\Services\\BlogArticleHelper';

    /**
     * The entity Blog's own renderer and count helper are typed on.
     *
     * Not a read of the entity — a shape test, so the theme can tell "this is
     * Blog's payload, let Blog draw it" from "this is a foreign payload, draw
     * it by shape". Without it, Blog's typed signature throws on a shape the
     * kernel itself hands themes (`stdClass` article mocks), which is a
     * caught-and-logged error for a situation that is not an error at all.
     */
    private const string BLOG_ARTICLE = 'Modules\\Blog\\Domain\\Entities\\Article';

    /** View-count formatting, same reason. */
    private const string VIEW_COUNTER = 'Modules\\Blog\\Application\\Services\\ViewCounter';

    /** How many series the index rail lists before it hands over to /blog/tags. */
    private const int RAIL_LIMIT = 8;

    /**
     * The home copy, in the showcase's own words.
     *
     * These are NOT invented: they are the strings the showcase's
     * `HomepageAction::COPY_DEFAULTS` and its `locales.db` seed carry for the
     * same keys, so this theme's home reads as the design it was ported from.
     *
     * They are DEFAULTS, not a second source of truth — `t()` reads
     * `PageContext::$copy` first, so the moment this kernel resolves home copy
     * (the move `HomepageAction`'s docblock calls the `M` answer) the kernel's
     * wording wins and these stay as the floor. That is the same shape
     * `ErikTheme` uses for its sections and `MonoTheme` for its slides.
     *
     * @var array<string, string>
     */
    private const array DEFAULT_COPY = [
        'home.hero_title' => 'Minimal PHP 8.5 Starter Kit',
        'home.hero_subtitle' => 'A tiny, auditable foundation: modular monolith, per-module SQLite, attribute-based routing and strict security — built from scratch.',
        'home.layer1_title' => 'Fewer Layers',
        'home.layer1_card1_title' => 'Zero dependencies',
        'home.layer1_card1_text' => 'No Composer runtime, no framework. A hand-rolled PSR-4 autoloader boots the app even without vendor/ — the whole production runtime is 5 PHP extensions.',
        'home.layer1_card2_title' => 'Readable surface',
        'home.layer1_card2_text' => 'C-pattern: no magic methods, no service locators, no generated maps. Every line is readable and auditable — no black box.',
        'home.layer1_card3_title' => 'Per-module SQLite',
        'home.layer1_card3_text' => 'One database file per module — users.db, config.db, locales.db. No infrastructure, no ORM, no migration maze.',
        'home.layer2_title' => 'Super Fast PHP',
        'home.layer2_card1_title' => 'PHP 8.5 + OPcache/JIT',
        'home.layer2_card1_text' => 'Language-level precompilation: OPcache keeps opcodes in memory, JIT compiles hot paths to native machine code.',
        'home.layer2_card2_title' => 'Data through C',
        'home.layer2_card2_text' => 'SQLite is pure C — the fastest path to data with no ORM layer. PHP built-ins are precompiled C libraries.',
        'home.layer2_card3_title' => 'No cache layer',
        'home.layer2_card3_text' => 'We dropped the cache layer and hit 100/100 PageSpeed. Speed comes from removing layers, not from caching.',
        'home.ratio_value' => '215 MB dev → 1.7 MB prod',
        'home.ratio_label' => '~126:1 — the entire production runtime is smaller than one phone photo.',
        'home.read_article' => 'Read article',
        'home.cta_blog' => 'Read the blog',
        'home.cta_admin' => 'Open admin',
    ];

    /**
     * The two feature layers and their cards, again as the showcase laid them
     * out. `PageContext::$cards` wins when the kernel fills it.
     *
     * @var array<string, list<string>>
     */
    private const array DEFAULT_LAYERS = [
        'layer1' => ['layer1_card1', 'layer1_card2', 'layer1_card3'],
        'layer2' => ['layer2_card1', 'layer2_card2', 'layer2_card3'],
    ];

    public function name(): string
    {
        return 'jednotka';
    }

    public function bundle(): string
    {
        return 'jednotka';
    }

    /**
     * The manifest entry, not the bundle: this package builds `src/main.ts`
     * (see the class docblock).
     */
    public function jsKey(): string
    {
        return 'main';
    }

    /**
     * The shared critical set, exactly as the showcase declared it.
     *
     * Not a recolour of anything and not theme-owned CSS in disguise: with the
     * manifest present the layout inlines this theme's own build, and this list
     * is only the degraded path when no build exists. Inventing a hand-written
     * subset here would be a second stylesheet to drift from the real one.
     *
     * @return list<string>
     */
    public function criticalCss(): array
    {
        return FrontendLayout::BASE_CRITICAL_CSS;
    }

    /**
     * Pre-paint colour mode, from the theme's own script.
     *
     * jednotka is DARK-FIRST by construction: `:root` in its tokens IS the dark
     * palette. The shared layout init resolves a missing preference with
     * `matchMedia(...)` inside a try/catch, so where the query cannot be asked
     * nothing is written and the theme lands on whatever `:root` is — which for
     * the other themes is their business, and here is a design decision. The
     * theme therefore owns the resolution order (choice → OS → dark) and ships
     * it as an asset, as the showcase did.
     *
     * `''` — the shared bytes — when the asset is missing, which is the
     * kernel's contract and keeps a broken file from rendering a bare script tag.
     */
    public function themeInitScript(): string
    {
        $js = InlineJs::file('Modules/Jednotka/assets/scripts/theme-init.js');

        if ('' === $js) {
            return '';
        }

        return '<script nonce="__CSP_NONCE_PLACEHOLDER__">' . $js . '</script>';
    }

    public function prefix(): string
    {
        return 'je';
    }

    /**
     * Jednotka's one admin choice: which glyph the phone menu toggle draws.
     *
     * `auto` keeps the burger this header was ported with, so an untouched
     * install renders what it always did. The meatball ships as a second SVG
     * (filled, not stroked) and only the closed glyph changes — the close
     * icon and the checkbox stay exactly where the open-state CSS expects.
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
                    'auto'     => 'Auto (theme default: burger)',
                    'burger'   => 'Burger (three lines)',
                    'meatball' => 'Meatball (three dots)',
                ],
                'auto',
            ),
        ];
    }

    public function header(ShellContext $context): string
    {
        $brand = self::e($context->siteName);

        // The home surface is the one page whose content opens with a
        // full-bleed band, and a rule under the header would cut exactly what
        // the bleed is there to create. The class string stays byte-identical
        // (`class="je-header"` is what the stylesheet and the DOM-parity tests
        // key on); the surface marker is a separate attribute.
        $surface = self::isHomePath($context->currentPath) ? ' data-surface="home"' : '';

        // `auto` is this theme's own glyph: the burger the header was ported
        // with. Only an explicit `meatball` swaps the closed icon.
        $meatball = 'meatball' === ($context->themeSettings['nav_toggle_icon'] ?? 'auto');
        $openIcon = $meatball ? $this->meatball() : $this->burger();
        $openModifier = $meatball ? ' je-nav__toggle-icon--meatball' : '';

        return <<<HTML
<header class="je-header"{$surface}>
    <div class="je-header__inner">
        <input type="checkbox" id="je-nav-toggle" class="je-nav__toggle-input" aria-label="Toggle menu">
        <a class="je-header__brand" href="/">
            <span class="je-header__unit">00</span>
            <span class="je-header__wordmark">{$brand}</span>
        </a>
        {$this->nav($context)}
        <div class="je-header__actions">
            {$this->chrome($context)}
        </div>
        <label for="je-nav-toggle" class="je-nav__toggle" title="Toggle menu">
            <span class="je-nav__toggle-icon je-nav__toggle-icon--open{$openModifier}" aria-hidden="true">
                {$openIcon}
            </span>
            <span class="je-nav__toggle-icon je-nav__toggle-icon--close" aria-hidden="true">
                {$this->close()}
            </span>
        </label>
        <label for="je-nav-toggle" class="je-nav__overlay" aria-hidden="true"></label>
    </div>
</header>

HTML;
    }

    public function footer(ShellContext $context): string
    {
        $siteName = self::e($context->siteName);
        $nav = $this->footerNav($context);
        $year = $context->year;

        return <<<HTML
<footer class="je-footer">
    <div class="je-footer__inner">
        <span class="je-footer__colophon">&copy; {$year} {$siteName}</span>
        {$nav}
        <span class="je-footer__stamp">PHP 8.5 &mdash; zero dependencies</span>
    </div>
</footer>
HTML;
    }

    public function pageWrap(string $content): string
    {
        return "<main class=\"je-main\">\n"
            . '    ' . $content . "\n"
            . "</main>\n";
    }

    /**
     * The home surface, assembled as numbered units.
     *
     * Every unit is copy-driven; a unit with no copy is omitted entirely rather
     * than rendered empty (see divergence 2 in the class docblock).
     */
    public function homepage(PageContext $context): string
    {
        $title = self::t($context, 'home.hero_title');

        if ('' === $title) {
            // A tree whose copy map is empty still has a page: the kernel's own
            // site name is the honest heading, and it is what
            // `HomepageAction` passes today.
            $title = self::t($context, 'title');
        }

        if ('' === $title) {
            return '';
        }

        return self::hero($context, $title)
            . self::featureSection($context, 'layer1')
            . self::featureSection($context, 'layer2')
            . self::ratioStrip($context);
    }

    /**
     * The blog index: the reading column plus the outer series rail.
     */
    public function blogIndex(PageContext $context): string
    {
        $articles = $context->articles;
        $perPage = $context->perPage > 0 ? $context->perPage : 9;
        $totalPages = max(1, (int) ceil(count($articles) / $perPage));
        $page = max(1, min($context->page, $totalPages));
        $pageArticles = array_slice($articles, ($page - 1) * $perPage, $perPage);
        $counts = self::viewCounts($context, $pageArticles);

        return '<section class="je-blog-index">'
            . '<header class="je-blog-index__header">'
            . '<span class="je-label">Unit 02 — Index</span>'
            . '<h1 class="je-blog-index__title">Blog</h1>'
            . '<p class="je-blog-index__subtitle">Plain Markdown, zero dependencies.</p>'
            . '</header>'
            . self::searchBox($context, '')
            . self::feedLinks($context)
            . '<div class="je-blog-index__layout">'
            . self::blogMain($pageArticles, $counts, self::pagination(count($articles), $page, $perPage, $context->locale, '', 'Blog pages'), 1 === $page, $context->locale)
            . self::seriesAside($articles)
            . '</div>'
            . '</section>';
    }

    public function blogSearch(PageContext $context): string
    {
        $query = $context->query;
        $results = $context->results;

        $list = [] === $results
            ? '<div class="je-blog-index__empty"><p>No articles match "' . self::e($query) . '".</p></div>'
            : self::grid($results, $context->viewCounts, $context->locale);

        $perPage = $context->perPage > 0 ? $context->perPage : 9;
        $totalPages = max(1, (int) ceil(count($results) / $perPage));
        $page = max(1, min($context->page, $totalPages));

        return '<section class="je-unit je-blog-index">'
            . '<header class="je-blog-index__header">'
            . '<span class="je-label">Unit 02 — Search</span>'
            . '<h1 class="je-blog-index__title">Search: ' . self::e($query) . '</h1>'
            . '</header>'
            . self::searchBox($context, $query)
            . '<div class="je-blog-index__layout">'
            . '<div class="je-blog-index__main">'
            . $list
            . self::pagination(count($results), $page, $perPage, $context->locale, $query, 'Search results pages', $totalPages)
            . '</div>'
            . '</div>'
            . '</section>';
    }

    public function blogArticle(PageContext $context): string
    {
        $views = self::formatCount($context->views);
        $viewsHtml = '' !== $views
            ? '<span class="je-article__views">' . self::e($views) . '</span>'
            : '';
        $metaLine = $context->metaLine;
        $fullMetaLine = '' !== $viewsHtml && '' !== $metaLine
            ? $metaLine . ' · ' . $viewsHtml
            : $metaLine . $viewsHtml;

        return self::articlePage(
            $context->title,
            $fullMetaLine,
            $context->tagChips,
            $context->coverImage,
            $context->bodyHtml,
            $context->headings,
            $context->editUrl,
            $context->editLabel,
            $context->inlineEditHtml,
        );
    }

    public function articleTail(string $html): string
    {
        return $html;
    }

    public function blogTags(PageContext $context): string
    {
        $chips = '';

        foreach ($context->tags as $tag) {
            $chips .= '<a class="je-article__tag" href="/blog/tags/' . self::e(MarkdownRenderer::slugify($tag)) . '">'
                . self::e($tag) . ' (' . ($context->counts[$tag] ?? 0) . ')</a>';
        }

        $filtered = $context->tag;

        if (null !== $filtered) {
            return $this->listing($context, 'Tag', $filtered, $chips);
        }

        return '<section class="je-unit je-blog-index">'
            . '<header class="je-blog-index__header">'
            . '<span class="je-label">Unit 02 — Tags</span>'
            . '<h1 class="je-blog-index__title">Tags</h1>'
            . '<p class="je-blog-index__subtitle">Browse articles by tag.</p>'
            . '</header>'
            . ('' === $chips
                ? '<p class="je-blog-index__empty">No tags found.</p>'
                : '<p class="je-article__tags">' . $chips . '</p>')
            . '</section>';
    }

    public function blogCategories(PageContext $context): string
    {
        $chips = '';

        foreach ($context->categories as $category) {
            $chips .= '<a class="je-article__tag" href="/blog/categories/' . self::e(MarkdownRenderer::slugify($category)) . '">'
                . self::e($category) . ' (' . ($context->counts[$category] ?? 0) . ')</a>';
        }

        $filtered = $context->category;

        if (null !== $filtered) {
            return $this->listing($context, 'Category', $filtered, $chips);
        }

        return '<section class="je-unit je-blog-index">'
            . '<header class="je-blog-index__header">'
            . '<span class="je-label">Unit 02 — Categories</span>'
            . '<h1 class="je-blog-index__title">Categories</h1>'
            . '<p class="je-blog-index__subtitle">Browse articles by category.</p>'
            . '</header>'
            . ('' === $chips
                ? '<p class="je-blog-index__empty">No categories found.</p>'
                : '<p class="je-article__tags">' . $chips . '</p>')
            . '</section>';
    }

    public function authorProfile(PageContext $context): string
    {
        $author = $context->author ?? ['name' => '', 'avatar' => null];
        $name = is_string($author['name'] ?? null) ? $author['name'] : '';
        $avatar = $author['avatar'] ?? null;
        $count = $context->totalArticles;
        $meta = $context->memberSince . ' · ' . $count . ' '
            . (1 === $count ? 'article' : 'articles');

        // No avatar service exists in the kernel (see divergence 3): an image
        // is rendered when a URL was handed over, and nothing when none was.
        $avatarHtml = is_string($avatar) && '' !== $avatar
            ? '<img class="je-author__avatar" src="' . self::e($avatar) . '" alt="" width="64" height="64" loading="lazy">'
            : '';

        $grid = [] === $context->articles
            ? '<p class="je-blog-index__empty">No articles yet.</p>'
            : self::grid($context->articles, $context->viewCounts, $context->locale);

        return '<section class="je-unit">'
            . '<header class="je-author">'
            . $avatarHtml
            . '<div class="je-author__body">'
            . '<span class="je-label">Unit 02 — Author</span>'
            . '<h1 class="je-author__name">' . self::e($name) . '</h1>'
            . '<p class="je-author__meta">' . self::e($meta) . '</p>'
            . '</div>'
            . '</header>'
            . $grid
            . self::taxonomyPagination($context, $this->authorPath($context, $name))
            . '</section>';
    }

    /**
     * Error content — required by this kernel's contract and absent from the
     * showcase class (its interface had no such member). In this theme's
     * vocabulary, so a 404 wears jednotka's paper rather than a sibling's.
     */
    public function errorContent(int $status, string $title, string $detail): string
    {
        return '<section class="je-unit je-section">'
            . '<span class="je-label">Error</span>'
            . '<h1 class="je-section__title">' . $status . ' ' . self::e($title) . '</h1>'
            . '<p class="je-card__text">' . self::e($detail) . '</p>'
            . '<p class="je-card__link"><a href="/">Back to the homepage</a></p>'
            . '</section>';
    }

    /**
     * Is this the home surface?
     *
     * The path arrives with the locale prefix already stripped (the locale
     * middleware runs before routing), so both locales answer '/'; the raw
     * shapes are accepted too in case a caller hands over an unprefixed path.
     */
    private static function isHomePath(string $path): bool
    {
        return \in_array($path, ['/', '', '/en', '/en/'], true);
    }

    /**
     * The opening statement. The media plate appears only when the kernel
     * configured one — see divergence 1 in the class docblock.
     */
    private static function hero(PageContext $context, string $title): string
    {
        $subtitle = self::t($context, 'home.hero_subtitle');
        $media = null !== $context->heroSrc && '' !== $context->heroSrc
            ? '<div class="je-hero__visual" aria-hidden="true">'
                . '<img class="je-hero__media" src="' . self::e($context->heroSrc) . '"'
                . ' srcset="' . self::e($context->heroSrcset ?? '') . '"'
                . ' sizes="(min-width: 768px) 720px, 100vw"'
                . ' alt="" loading="eager" fetchpriority="high" decoding="async" width="720" height="360">'
                . '</div>'
            : '';

        return '<section class="je-unit je-hero">'
            . '<span class="je-label je-hero__unit">Unit 01 — Premise</span>'
            . '<article class="je-hero__card">'
            . '<h1 class="je-hero__title">' . self::e($title) . '</h1>'
            . ('' === $subtitle ? '' : '<p class="je-hero__subtitle">' . self::e($subtitle) . '</p>')
            . self::heroActions($context)
            . '</article>'
            . $media
            . '</section>';
    }

    /**
     * The hero actions. A missing label renders NO button rather than an
     * unlabelled link, which is a trap rather than a placeholder.
     */
    private static function heroActions(PageContext $context): string
    {
        $actions = '';
        $primary = self::t($context, 'home.cta_blog');
        $secondary = self::t($context, 'home.cta_admin');

        if ('' !== $primary) {
            $href = '' !== (string) $context->blogHref ? (string) $context->blogHref : '/blog';
            $actions .= '<a class="je-button je-button--primary" href="' . self::e($href) . '">'
                . self::e($primary) . '</a>';
        }

        if ('' !== $secondary) {
            $actions .= '<a class="je-button" href="/mark">' . self::e($secondary) . '</a>';
        }

        return '' === $actions ? '' : '<div class="je-hero__actions">' . $actions . '</div>';
    }

    /**
     * One feature unit. Omitted only when there is genuinely no heading to
     * give it — the default copy supplies one, so this is the degraded case.
     */
    private static function featureSection(PageContext $context, string $layer): string
    {
        $title = self::t($context, 'home.' . $layer . '_title');

        if ('' === $title) {
            return '';
        }

        // The kernel's layout when it hands one over, the showcase's own when
        // it does not — this kernel's `HomepageAction` passes no `cards`.
        $cards = '';

        foreach ($context->cards[$layer] ?? self::DEFAULT_LAYERS[$layer] ?? [] as $cardKey) {
            $cards .= self::featureCard($context, (string) $cardKey);
        }

        return '<section class="je-unit je-section">'
            . '<span class="je-label">Feature</span>'
            . '<h2 class="je-section__title">' . self::e($title) . '</h2>'
            . '<div class="je-features" data-cq data-cq-bp="560,860">' . $cards . '</div>'
            . '</section>';
    }

    /**
     * One feature note — title, text and a link to the article behind it. A
     * card with neither title nor text is skipped for the same reason the
     * section is.
     */
    private static function featureCard(PageContext $context, string $cardKey): string
    {
        $title = self::t($context, 'home.' . $cardKey . '_title');
        $text = self::t($context, 'home.' . $cardKey . '_text');

        if ('' === $title && '' === $text) {
            return '';
        }

        $href = $context->links[$cardKey] ?? '';
        $readMore = self::t($context, 'home.read_article');

        return '<div class="je-card">'
            . ('' === $title ? '' : '<h3 class="je-card__title">' . self::e($title) . '</h3>')
            . ('' === $text ? '' : '<p class="je-card__text">' . self::e($text) . '</p>')
            . ('' === $readMore || !is_string($href) || '' === $href
                ? ''
                : '<a class="je-card__link" href="' . self::e($href) . '">' . self::e($readMore) . '</a>')
            . '</div>';
    }

    /**
     * The measure unit, set as a figure caption rather than a stat tile.
     */
    private static function ratioStrip(PageContext $context): string
    {
        $value = self::t($context, 'home.ratio_value');

        if ('' === $value) {
            return '';
        }

        $label = self::t($context, 'home.ratio_label');

        return '<section class="je-unit je-stats">'
            . '<span class="je-label">Unit 04 — Measure</span>'
            . '<p class="je-stat__value">' . self::e($value) . '</p>'
            . ('' === $label ? '' : '<p class="je-stat__label">' . self::e($label) . '</p>')
            . '</section>';
    }

    /**
     * The index's reading column and its pager.
     *
     * `$featured` marks page 1 — the only page with a lead. It is a class on the
     * WRAPPER, never a different card: the spotlight has to stay a styling
     * decision, because a second card renderer is a second thing to drift.
     *
     * @param array<array-key, mixed> $articles
     * @param array<string, int>      $viewCounts
     */
    private static function blogMain(array $articles, array $viewCounts, string $pagination, bool $featured, string $locale): string
    {
        if ([] === $articles) {
            return '<p class="je-blog-index__empty">No articles yet — drop a .md file into storage/blog.</p>';
        }

        $wrapper = $featured ? ' je-blog-index__main--lead' : '';

        return '<div class="je-blog-index__main' . $wrapper . '">'
            . self::grid($articles, $viewCounts, $locale)
            . $pagination
            . '</div>';
    }

    /**
     * Full-text search control (the `?q=` entry point on /blog).
     */
    private static function searchBox(PageContext $context, string $query): string
    {
        $action = ContentServices::mapper()->urlPrefix($context->locale) . '/blog';

        return '<form class="je-blog-search" method="get" action="' . $action . '" role="search">'
            . '<label class="je-sr-only" for="je-search-input">Search</label>'
            . '<div class="je-blog-search__form">'
            . '<input class="je-blog-search__input" id="je-search-input" name="q"'
            . ' placeholder="Search articles..." value="' . self::e($query) . '"'
            . ' autocomplete="off" type="search">'
            . '<button class="je-blog-search__submit" type="submit">Search</button>'
            . '</div>'
            . '</form>';
    }

    /**
     * Atom + RSS subscription links, or nothing at all when Blog is absent —
     * the URLs are Blog's, and a feed link that 404s is worse than none.
     */
    private static function feedLinks(PageContext $context): string
    {
        if (!class_exists(self::BLOG_HELPER)) {
            return '';
        }

        $helper = self::BLOG_HELPER;

        try {
            /** @var array{atom: string, rss: string} $links */
            $links = $helper::feedLinks($context->locale);
        } catch (\Throwable $e) {
            error_log('[JednotkaTheme] feed links failed: ' . $e->getMessage());

            return '';
        }

        return '<p class="je-blog-feed">'
            . 'Subscribe: '
            . '<a class="je-blog-feed__link" href="' . self::e($links['atom']) . '" type="application/atom+xml">Atom</a> · '
            . '<a class="je-blog-feed__link" href="' . self::e($links['rss']) . '" type="application/rss+xml">RSS</a>'
            . '</p>';
    }

    /**
     * Article cards through Blog's own renderer when it is installed — the
     * showcase's grid, byte for byte — and the same grid built here from the
     * shapes the kernel handed over when it is not.
     *
     * @param array<array-key, mixed> $articles
     * @param array<string, int>      $viewCounts
     */
    private static function grid(array $articles, array $viewCounts, string $locale): string
    {
        if (class_exists(self::BLOG_FACADE) && self::isBlogPayload($articles)) {
            $facade = self::BLOG_FACADE;

            try {
                return (string) $facade::articles()->renderCards($articles, $viewCounts);
            } catch (\Throwable $e) {
                error_log('[JednotkaTheme] card grid failed: ' . $e->getMessage());
            }
        }

        return self::cardGrid($articles, $locale);
    }

    /**
     * Is this payload Blog's own, or one the kernel handed over by shape?
     *
     * One element decides it: a list is rendered by one renderer, and a mixed
     * list is not a thing either side produces.
     *
     * @param array<array-key, mixed> $articles
     */
    private static function isBlogPayload(array $articles): bool
    {
        $first = $articles[array_key_first($articles)] ?? null;

        return is_object($first) && is_a($first, self::BLOG_ARTICLE);
    }

    /**
     * The Blog-free grid — Blog's own markup contract, built from the shapes
     * the kernel handed over.
     *
     * Written class-for-class as `BlogArticlesService::renderCard()` writes it
     * (`st-grid` + `st-blog-index__card…`), NOT with this theme's `je-card`
     * vocabulary: that class is the HOMEPAGE feature card, a different
     * component, and a theme-invented class here would be markup nothing
     * styles. Those `st-` grid classes are what every theme's stylesheet
     * carries for the blog grid, so a Blog-less tree still gets cards that look
     * like cards. Only the fields the kernel actually sent are emitted — no
     * invented date, author or cover.
     *
     * @param array<array-key, mixed> $articles
     */
    private static function cardGrid(array $articles, string $locale): string
    {
        $prefix = 'en_US' === $locale ? '/en' : '';
        $cards = '';

        foreach ($articles as $article) {
            if (!is_object($article)) {
                continue;
            }

            $fields = get_object_vars($article);
            $slug = $fields['slug'] ?? null;
            $title = $fields['title'] ?? null;

            if (!is_string($slug) || !is_string($title)) {
                continue;
            }

            $text = static function (string $key) use ($fields): string {
                $value = $fields[$key] ?? '';

                return is_string($value) ? $value : '';
            };

            $meta = implode(' · ', array_filter([
                $text('date'),
                $text('category'),
                $text('author'),
            ], static fn (string $bit): bool => '' !== $bit));

            $image = $text('image');
            $cover = '' !== $image
                ? '<img class="st-blog-index__card-image" src="' . self::e($image) . '" alt="'
                    . self::e($title) . '" loading="lazy" decoding="async" width="720" height="360">'
                : '';
            $excerpt = $text('excerpt');

            $cards .= '<article class="st-blog-index__card">'
                . $cover
                . '<h2 class="st-blog-index__card-title"><a href="' . $prefix . '/blog/'
                . self::e($slug) . '">' . self::e($title) . '</a></h2>'
                . ('' === $meta ? '' : '<p class="st-blog-index__card-meta">' . $meta . '</p>')
                . ('' === $excerpt ? '' : '<p class="st-blog-index__card-text">' . self::e($excerpt) . '</p>')
                . '</article>';
        }

        if ('' === $cards) {
            return '<p class="je-blog-index__empty">No articles yet — drop a .md file into storage/blog.</p>';
        }

        return '<div class="st-blog-index__list st-grid">' . $cards . '</div>';
    }

    /**
     * The outer rail: articles grouped by tag, biggest first, capped.
     *
     * A real corpus returns one entry per tag; rendering all of them makes the
     * band a wall of text, which is neither a rail nor a document. The cap is
     * announced — a silently truncated list reads as "that is all of them",
     * which is a different and wrong answer.
     *
     * @param array<array-key, mixed> $articles
     */
    private static function seriesAside(array $articles): string
    {
        $series = self::detectSeries($articles);

        if ([] === $series) {
            return '';
        }

        $total = count($series);
        $shown = array_slice($series, 0, self::RAIL_LIMIT, true);

        return '<aside class="je-widget je-widget--series" aria-label="Series">'
            . '<span class="je-label">Unit 03 — Series</span>'
            . '<h2 class="je-widget__title">Series</h2>'
            . '<ul class="je-widget__list">'
            . self::seriesItems($shown)
            . '</ul>'
            . ($total <= self::RAIL_LIMIT
                ? ''
                : '<p class="je-widget__more"><a class="je-widget__more-link" href="/blog/tags">All '
                    . $total . ' tags</a></p>')
            . '</aside>';
    }

    /**
     * Group articles by tag, biggest series first. Shapes are read through
     * `get_object_vars` — a theme that names the entity owning an article is
     * the coupling this port exists to avoid.
     *
     * @param array<array-key, mixed> $articles
     *
     * @return array<string, list<object>>
     */
    private static function detectSeries(array $articles): array
    {
        $series = [];

        foreach ($articles as $article) {
            if (!is_object($article)) {
                continue;
            }

            $tags = get_object_vars($article)['tags'] ?? [];

            if (!is_array($tags)) {
                continue;
            }

            foreach ($tags as $tag) {
                if (is_string($tag) && '' !== $tag) {
                    $series[$tag][] = $article;
                }
            }
        }

        uasort($series, static fn (array $a, array $b): int => count($b) <=> count($a));

        return $series;
    }

    /**
     * @param array<string, list<object>> $series
     */
    private static function seriesItems(array $series): string
    {
        $items = '';

        foreach ($series as $tag => $articles) {
            $lead = $articles[0];
            $excerpt = get_object_vars($lead)['excerpt'] ?? '';
            $excerpt = is_string($excerpt) ? $excerpt : '';

            $items .= '<li class="je-widget__item">'
                . '<a class="je-widget__link" href="/blog/tags/' . self::e(MarkdownRenderer::slugify($tag)) . '">'
                . '<span class="je-widget__tag">' . self::e(ucfirst($tag)) . '</span>'
                . '<span class="je-widget__count">' . count($articles) . '</span>'
                . '<span class="je-widget__lead">'
                . self::e(mb_strimwidth($excerpt, 0, 60, '…'))
                . '</span>'
                . '</a></li>';
        }

        return $items;
    }

    /**
     * A filtered taxonomy listing: header, chips, grid, pager.
     */
    private function listing(PageContext $context, string $kind, string $term, string $chips): string
    {
        $count = $context->totalArticles;
        $path = '/blog/' . strtolower('Tag' === $kind ? 'tags' : 'categories') . '/' . rawurlencode($term);

        return '<section class="je-unit je-blog-index">'
            . '<header class="je-blog-index__header">'
            . '<span class="je-label">Unit 02 — ' . $kind . '</span>'
            . '<h1 class="je-blog-index__title">' . self::e($term) . '</h1>'
            . '<p class="je-blog-index__subtitle">' . $count . ' article'
            . (1 === $count ? '' : 's') . ' found.</p>'
            . '</header>'
            . ('' === $chips ? '' : '<p class="je-article__tags">' . $chips . '</p>')
            . self::grid($context->articles, $context->viewCounts, $context->locale)
            . self::taxonomyPagination($context, $path)
            . '</section>';
    }

    /**
     * The article page: prose column and a contents rail as its SIBLING.
     *
     * A table of contents is complementary navigation, so it is a complementary
     * landmark — burying it inside the article put it in `<main>` only by
     * accident of nesting.
     *
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    private static function articlePage(
        string $title,
        string $metaLine,
        string $tagChips,
        string $coverImage,
        string $bodyHtml,
        array $headings,
        string $editUrl,
        string $editLabel,
        string $inlineEditHtml,
    ): string {
        return '<div class="je-blog-index__layout je-article-layout">'
            . '<div class="je-blog-index__main">'
            . '<article class="je-unit je-article">'
            . '<header class="je-article__header">'
            . $coverImage
            . '<h1 class="je-article__title">' . self::e($title) . '</h1>'
            . '<p class="je-article__meta">' . $metaLine . '</p>'
            . self::editLink($editUrl, $editLabel)
            . '</header>'
            . $inlineEditHtml
            . '<div class="je-prose">' . $bodyHtml . '</div>'
            . $tagChips
            . '</article>'
            . '</div>'
            . self::tocAside($headings)
            . '</div>';
    }

    /**
     * The owner-edit affordance, or nothing at all.
     *
     * Rendered only when the kernel handed over a URL — the action resolves
     * ownership, because policy belongs to the kernel and markup to the theme.
     * Rendering it for a visitor who would get a 403 makes a rule read as a
     * broken page.
     */
    private static function editLink(string $editUrl, string $label): string
    {
        if ('' === $editUrl) {
            return '';
        }

        return '<p class="je-article__edit"><a class="je-article__edit-link" href="'
            . self::e($editUrl) . '">' . self::e($label) . '</a></p>';
    }

    /**
     * The contents rail: always open on wide screens, a native disclosure on
     * narrow ones.
     *
     * The `open` attribute is LOAD-BEARING. A closed `<details>` cannot be
     * forced open from CSS — Chrome hides its slot through `::details-content`,
     * so `display: flex` on the list does nothing — and the summary that would
     * let a reader open it is hidden at wide sizes by the very media query that
     * is meant to make the rail permanent. A rail without `open` is therefore
     * an empty column beside the prose: the links in the DOM and on no screen.
     *
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    private static function tocAside(array $headings): string
    {
        if ([] === $headings) {
            return '';
        }

        return '<aside class="je-article-toc" aria-label="On this page">'
            . '<details class="je-article-toc__disclosure" open>'
            . '<summary class="je-article-toc__label je-label">On this page</summary>'
            . '<ul class="je-article-toc__list">' . self::tocItems($headings) . '</ul>'
            . '</details>'
            . '</aside>';
    }

    /**
     * Contents markup. The `data-toc-link` / `data-heading` attributes are the
     * shared scrollspy's contract — it reads the block off the element at
     * runtime, so the same helper drives this rail and every other theme's.
     *
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    private static function tocItems(array $headings): string
    {
        $items = '';

        foreach ($headings as $heading) {
            $sub = 3 === $heading['level'] ? ' je-article-toc__item--sub' : '';
            $items .= '<li class="je-article-toc__item' . $sub . '">'
                . '<a class="je-article-toc__link" href="#' . self::e($heading['anchor'])
                . '" data-toc-link data-heading="' . self::e($heading['anchor']) . '">'
                . self::e($heading['text'])
                . '</a>'
                . '</li>';
        }

        return $items;
    }

    /**
     * The pager, computed from `PageContext` rather than from a result object:
     * this kernel hands over `page`, `perPage` and the article arrays, and the
     * showcase's `SearchResult` is a Blog DTO a theme may not name.
     *
     * The URL shape replicates `SearchResult::pageUrl` — the query preserved,
     * `page=1` left off so the first page has one canonical URL.
     */
    private static function pagination(int $total, int $page, int $perPage, string $locale, string $query, string $label, ?int $givenTotalPages = null): string
    {
        $totalPages = $givenTotalPages ?? max(1, (int) ceil($total / max(1, $perPage)));

        if ($totalPages <= 1) {
            return '';
        }

        $basePath = 'en_US' === $locale ? '/en/blog' : '/blog';
        $nav = '<nav class="je-blog-pagination" aria-label="' . self::e($label) . '">';

        if ($page > 1) {
            $nav .= '<a class="je-blog-pagination__link" href="' . self::pageUrl($page - 1, $basePath, $query) . '">‹ Newer</a>';
        }

        for ($i = 1; $i <= $totalPages; $i++) {
            $isCurrent = $i === $page;
            $class = $isCurrent
                ? ' class="je-blog-pagination__page je-blog-pagination__page--current"'
                : ' class="je-blog-pagination__page"';
            // The page you are on is announced, not only painted: a screen
            // reader hears "current page" rather than a number that looks
            // different and reads identically to every other number.
            $ariaCurrent = $isCurrent ? ' aria-current="page"' : '';
            $nav .= '<span' . $class . '><a' . $ariaCurrent . ' href="' . self::pageUrl($i, $basePath, $query) . '">' . $i . '</a></span>';
        }

        if ($page < $totalPages) {
            $nav .= '<a class="je-blog-pagination__link" href="' . self::pageUrl($page + 1, $basePath, $query) . '">Older ›</a>';
        }

        return $nav . '</nav>';
    }

    /**
     * The tag / category / author pager.
     *
     * Those surfaces are noindex and have no canonical, so unlike the blog
     * index there is nothing for the pager to preserve but the page number.
     */
    private static function taxonomyPagination(PageContext $context, string $basePath): string
    {
        if ($context->totalPages <= 1) {
            return '';
        }

        $current = $context->page;
        $total = $context->totalPages;
        $path = self::e($basePath);

        $nav = '<nav class="je-blog-pagination" aria-label="Listing pages">';

        if ($current > 1) {
            $nav .= '<a class="je-blog-pagination__link" href="' . $path . '?page=' . ($current - 1) . '">‹ Newer</a>';
        }

        for ($i = 1; $i <= $total; $i++) {
            $isCurrent = $i === $current;
            $class = $isCurrent
                ? ' class="je-blog-pagination__page je-blog-pagination__page--current"'
                : ' class="je-blog-pagination__page"';
            $ariaCurrent = $isCurrent ? ' aria-current="page"' : '';
            $nav .= '<span' . $class . '><a' . $ariaCurrent . ' href="' . $path . '?page=' . $i . '">'
                . $i . '</a></span>';
        }

        if ($current < $total) {
            $nav .= '<a class="je-blog-pagination__link" href="' . $path . '?page=' . ($current + 1) . '">Older ›</a>';
        }

        return $nav . '</nav>';
    }

    private static function pageUrl(int $page, string $basePath, string $query): string
    {
        $params = http_build_query(array_filter([
            'q' => '' !== $query ? $query : null,
            'page' => $page > 1 ? $page : null,
        ]));

        return $basePath . ('' !== $params ? '?' . $params : '');
    }

    /**
     * Locale-prefixed author path for the profile pager.
     */
    private function authorPath(PageContext $context, string $name): string
    {
        return ('en_US' === $context->locale ? '/en' : '') . '/authors/'
            . rawurlencode(MarkdownRenderer::slugify($name));
    }

    /**
     * View counts for the current page's slice, from the kernel when it handed
     * them over and from Blog when it did not.
     *
     * @param array<array-key, mixed> $articles
     *
     * @return array<string, int>
     */
    private static function viewCounts(PageContext $context, array $articles): array
    {
        if ([] !== $context->viewCounts) {
            return $context->viewCounts;
        }

        if (!class_exists(self::BLOG_HELPER) || !self::isBlogPayload($articles)) {
            return [];
        }

        $helper = self::BLOG_HELPER;

        try {
            /** @var array<string, int> $counts */
            $counts = $helper::viewCounts($articles);

            return $counts;
        } catch (\Throwable $e) {
            error_log('[JednotkaTheme] view counts failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * A view count as the reader sees it, through Blog's formatter when it is
     * installed and as a plain number when it is not.
     */
    private static function formatCount(int $views): string
    {
        if ($views <= 0) {
            return '';
        }

        if (class_exists(self::VIEW_COUNTER)) {
            $counter = self::VIEW_COUNTER;

            try {
                return (string) $counter::formatCount($views);
            } catch (\Throwable $e) {
                error_log('[JednotkaTheme] view formatting failed: ' . $e->getMessage());
            }
        }

        return number_format($views);
    }

    /**
     * The masthead navigation: a run of uppercase mono labels in a single ruled
     * band, parents opening a ruled list directly beneath them.
     */
    private function nav(ShellContext $context): string
    {
        if ([] === $context->headerNav) {
            return '';
        }

        $links = '';

        foreach ($context->headerNav as $item) {
            $links .= $this->navItem($item);
        }

        return '<nav class="je-nav" aria-label="Main">'
            . '<span class="je-nav__label je-label">Index</span>'
            . '<ul class="je-nav__list">' . $links . '</ul>'
            . '</nav>';
    }

    private function navItem(ShellNavItem $item): string
    {
        $active = $item->active ? ' je-nav__link--active' : '';
        $current = $item->active ? ' aria-current="page"' : '';

        $link = '<a class="je-nav__link' . $active . '" href="' . self::e($item->href) . '"'
            . $current . self::externalAttr($item) . '>' . self::e($item->label) . '</a>';

        if (!$item->hasChildren()) {
            return '<li class="je-nav__item">' . $link . '</li>';
        }

        $children = '';

        foreach ($item->children as $child) {
            $children .= '<li class="je-nav__sub-item">' . $this->navItem($child) . '</li>';
        }

        return '<li class="je-nav__item je-nav__item--has-children">'
            . $link
            . '<ul class="je-nav__sub">' . $children . '</ul>'
            . '</li>';
    }

    /**
     * Footer links. Footer items render flat — nested children of a footer item
     * are deliberately not expanded.
     */
    private function footerNav(ShellContext $context): string
    {
        if ([] === $context->footerNav) {
            return '';
        }

        $links = '';

        foreach ($context->footerNav as $item) {
            $links .= '<li class="je-footer__item"><a class="je-footer__link" href="'
                . self::e($item->href) . '"' . self::externalAttr($item) . '>'
                . self::e($item->label) . '</a></li>';
        }

        return '<nav class="je-footer__nav" aria-label="Footer"><ul class="je-footer__list">'
            . $links . '</ul></nav>';
    }

    private function chrome(ShellContext $context): string
    {
        return $this->localeSwitcher($context)
            . "\n" . self::themeToggle()
            . "\n" . $this->userLink($context);
    }

    /**
     * Language links: the locale-prefixed form of the current path, or exactly
     * the links the kernel handed over (article translations), where a missing
     * locale means no link at all.
     */
    private function localeSwitcher(ShellContext $context): string
    {
        $links = '';

        foreach ($context->availableLocales as $code) {
            if (!is_string($code) || '' === $code) {
                continue;
            }

            $href = null !== $context->localeLinks
                ? ($context->localeLinks[$code] ?? null)
                : ContentServices::mapper()->urlPrefix($code) . ('/' === $context->currentPath ? '/' : $context->currentPath);

            if (!is_string($href) || '' === $href) {
                continue;
            }

            $language = self::language($code);
            $active = $code === $context->activeLocale ? ' je-locale__link--active' : '';

            $links .= '<a class="je-locale__link' . $active . '" href="' . self::e($href)
                . '" hreflang="' . self::e($language) . '" lang="' . self::e($language) . '">'
                . self::e(strtoupper($language)) . '</a>';
        }

        return '<nav class="je-locale" aria-label="Language">' . $links . '</nav>';
    }

    /**
     * Sign-in / admin link. Auth awareness arrives through `ShellContext` — a
     * theme never reads a session or a users database itself.
     *
     * The labels are literals: the showcase resolved them through `Locales`,
     * and the kernel's translation surface for a theme is `PageContext::$copy`,
     * which carries no nav labels. `ErikTheme` and `MonoTheme` label their
     * chrome the same way.
     */
    private function userLink(ShellContext $context): string
    {
        $href = $context->signedIn ? '/mark' : '/login';
        $label = $context->signedIn ? 'Admin' : 'Sign in';
        $icon = $context->signedIn
            ? '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'
            : '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>';

        return '<a class="je-login-btn" href="' . self::e($href) . '"'
            . ' aria-label="' . self::e($label) . '" title="' . self::e($label) . '">'
            . '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icon . '</svg>'
            . '<span class="je-login-btn__text">' . self::e($label) . '</span>'
            . '</a>';
    }

    /**
     * The colour-mode toggle. Behaviour ships from the shared theme-toggle
     * script; the markup carries the `data-theme-toggle` hook.
     */
    private static function themeToggle(): string
    {
        return '<button type="button" class="je-theme-toggle" data-theme-toggle aria-label="Toggle theme" title="Toggle theme">'
            . '<span class="je-theme-toggle__icon je-theme-toggle__icon--sun" aria-hidden="true">' . self::sunIcon() . '</span>'
            . '<span class="je-theme-toggle__icon je-theme-toggle__icon--moon" aria-hidden="true">' . self::moonIcon() . '</span>'
            . '</button>';
    }

    private static function sunIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>';
    }

    private static function moonIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>';
    }

    /** The mobile nav toggles, drawn by CSS inside the header's labels. */
    private static function burger(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>';
    }

    /**
     * The opt-in meatball: three filled dots on the same 24-unit grid the
     * burger uses (r=2 at cy 5/12/19, the geometry Mono and Griffe draw).
     *
     * `style` rather than attributes: the theme's shell CSS sets
     * `fill: none; stroke: …` on every toggle SVG, and a presentation
     * attribute loses to a stylesheet — the dots would come out as three
     * invisible outlines. Inline style is the one thing that wins without a
     * second rule in the shared stylesheet.
     */
    private static function meatball(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="fill:currentColor;stroke:none"><circle cx="12" cy="5" r="2"></circle><circle cx="12" cy="12" r="2"></circle><circle cx="12" cy="19" r="2"></circle></svg>';
    }

    private static function close(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
    }

    private function externalAttr(ShellNavItem $item): string
    {
        return $item->external ? ' target="_blank" rel="noopener"' : '';
    }

    /** sk_SK → sk, en_US → en (the same convention as the kernel's locale VO). */
    private static function language(string $code): string
    {
        return strtolower((string) explode('_', $code)[0]);
    }

    /**
     * Copy lookup: the kernel's resolved map first, the theme's own defaults
     * second. See `DEFAULT_COPY` for why both exist.
     */
    private static function t(PageContext $context, string $key): string
    {
        $value = $context->copy[$key] ?? self::DEFAULT_COPY[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * HTML escaping, delegated to the shared kernel helper rather than keeping
     * a second copy of the same four lines.
     */
    private static function e(mixed $value): string
    {
        return FrontendLayout::e($value);
    }
}
