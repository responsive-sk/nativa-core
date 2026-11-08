<?php

declare(strict_types = 1);

namespace Modules\Themes\Erik\Theme;

use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Themes\ThemeModule;

/**
 * ErikTheme — the dark-first public theme (er-* vocabulary), trimmed for the
 * kernel tree.
 *
 * The homepage is the nativa-minimal Erik home in miniature: hero with
 * line-art visual, feature grid, steps, news grid, proof quote, comparison
 * table, closing CTA. The showcase Erik renders those from Blog/Locales
 * services; that Erik cannot live here — its imports name modules this tree
 * does not have — so copy arrives via `PageContext::$copy` (the showcase
 * strings are the defaults) and articles via `get_object_vars`, never via
 * a feature-module import. The hero visual keeps the inline line-art SVG
 * but drops the WebP underlay (an external binary the kernel must never
 * fetch at runtime; the gradient stays).
 *
 * It imports nothing from a feature module. Article shapes are read through
 * `get_object_vars` rather than `use`ing the entity: a theme that names the
 * module owning the article is the coupling the kernel exists to avoid.
 */
final class ErikTheme implements ThemeModule
{
    public function name(): string
    {
        return 'erik';
    }

    public function bundle(): string
    {
        return 'erik';
    }

    public function jsKey(): string
    {
        return 'erik';
    }

    /**
     * @return list<string> src/-relative, resolved by InlineCss
     */
    public function criticalCss(): array
    {
        return ['Modules/Erik/assets/critical/erik.css'];
    }

    public function themeInitScript(): string
    {
        return '';
    }

    public function prefix(): string
    {
        return 'er';
    }

    public function header(ShellContext $context): string
    {
        return $this->chrome('header', [
            '<a class="er-header__brand" href="/">' . self::e($context->siteName) . '</a>',
            $this->nav($context->headerNav),
            $this->localeSwitcher($context),
        ]);
    }

    public function footer(ShellContext $context): string
    {
        return $this->chrome('footer', [
            '<span class="er-footer__year">© ' . self::e($context->year) . ' ' . self::e($context->siteName) . '</span>',
            $this->nav($context->footerNav),
        ]);
    }

    public function pageWrap(string $content): string
    {
        return '<main class="er-main">' . "\n"
            . '    ' . $content . "\n"
            . '</main>' . "\n";
    }

    public function homepage(PageContext $context): string
    {
        // No pageWrap here: FrontendLayout owns the single wrap (it calls
        // pageWrap($content) itself) — wrapping here nests <main> and was
        // the double-wrap the other surfaces still carry.
        $articles = [] !== $context->latestArticles ? $context->latestArticles : $context->articles;

        if ([] === $articles) {
            $articles = self::latestFallback($context->locale);
        }

        return $this->hero($context)
            . $this->homeSection('What you get', 'A foundation that gets out of your way.', $this->featureGrid(), 'er-section--features')
            . $this->homeSection('How it works', 'From first route to production artifact.', $this->steps(), 'er-section--steps')
            . $this->homeSection('Latest news', 'Ideas from the build.', $this->newsGrid($articles), 'er-section--news')
            . $this->proof()
            . $this->homeSection('Compare the shape', 'Less machinery. Clearer ownership.', $this->comparison(), 'er-section--comparison')
            . $this->closingCta($context);
    }

    /**
     * Showcase hero: eyebrow + display title + lede + two actions over the
     * line-art visual; the primary action goes to the blog, the secondary to
     * the admin surface the kernel owns.
     *
     * ## Which key each slot reads
     *
     * The home surface's own keys come FIRST: `home.eyebrow`, `home.title`,
     * `home.message`, `home.cta_blog` and `home.cta_admin` are the rows the
     * translation admin edits, and they are what makes a `sk_SK` site render
     * Slovak here. The bare keys (`hero_eyebrow`, `title`, `lede`) stay in the
     * chain behind them — this theme read those first and a caller may still
     * pass one — and the showcase strings are the floor, so a tree with no
     * translation module renders exactly what it rendered before.
     *
     * Measured 2026-10-17: on a `sk_SK` site this hero answered `NATIVA
     * MINIMAL` / the site name / the English lede, while `home.title` held
     * `Postavte Niečo Skvelé` in the same database, unread by every theme.
     */
    private function hero(PageContext $context): string
    {
        $copy = $context->copy;

        $eyebrow = $copy['home.eyebrow'] ?? $copy['hero_eyebrow'] ?? 'NATIVA MINIMAL';
        $title = $copy['home.title'] ?? $copy['title'] ?? 'Fewer layers. More control.';
        $lede = $copy['home.message'] ?? $copy['lede'] ?? 'A minimal PHP foundation for products that should stay understandable as they grow.';
        $href = $context->blogHref ?? '/blog';

        $actions = '<a class="er-button er-button--primary" href="' . self::e($href) . '">'
            . self::e($copy['home.cta_blog'] ?? 'Read the blog') . '</a>'
            . '<a class="er-button er-button--outline" href="/mark">'
            . self::e($copy['home.cta_admin'] ?? 'Open admin') . '</a>';

        return '<section class="er-hero"><div class="er-container">'
            . '<p class="er-hero__eyebrow">' . self::e($eyebrow) . '</p>'
            . '<article class="er-card"><h1 class="er-hero__title">' . self::e($title) . '</h1>'
            . ('<p class="er-hero__desc">' . self::e($lede) . '</p>')
            . '<div class="er-hero__actions">' . $actions . '</div>'
            . '</article>'
            . '<div class="er-hero__visual" aria-hidden="true">' . self::heroVisual() . '</div>'
            . '</div></section>';
    }

    /**
     * The line-art half of the showcase visual, inline and self-contained:
     * glow disc, terrain polyline, two rails, two nodes. The WebP terrain
     * underlay is deliberately absent (see the class docblock).
     */
    private static function heroVisual(): string
    {
        return '<svg class="er-hero__svg" viewBox="0 0 900 620" role="presentation" focusable="false">'
            . '<defs><linearGradient id="er-glow" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="#d9f99d" stop-opacity=".9"/>'
            . '<stop offset="1" stop-color="#bef264" stop-opacity=".05"/>'
            . '</linearGradient></defs>'
            . '<circle cx="680" cy="170" r="150" fill="url(#er-glow)" opacity=".35"/>'
            . '<path d="M80 470 310 240l110 110 180-210 220 240" fill="none" stroke="currentColor" stroke-width="2" opacity=".5"/>'
            . '<path d="M80 510h720M80 550h560" fill="none" stroke="currentColor" stroke-width="1" opacity=".3"/>'
            . '<circle cx="310" cy="240" r="7" fill="#d9f99d"/><circle cx="600" cy="140" r="7" fill="#d9f99d"/>'
            . '</svg>';
    }

    /**
     * @var list<array{number: string, title: string, text: string}>
     */
    private const array FEATURES = [
        ['number' => '01', 'title' => 'Fewer layers', 'text' => 'A transparent PHP runtime with explicit seams and no hidden framework magic.'],
        ['number' => '02', 'title' => 'Fast by default', 'text' => 'SQLite, native PHP and a small production surface keep the path to first paint short.'],
        ['number' => '03', 'title' => 'Ready to shape', 'text' => 'Modules, themes and admin screens stay small enough to understand and change.'],
    ];

    /**
     * @var list<array{number: string, title: string, text: string}>
     */
    private const array STEPS = [
        ['number' => '1', 'title' => 'Choose a surface', 'text' => 'Start with a public page, a blog article or the Mark admin panel.'],
        ['number' => '2', 'title' => 'Compose a module', 'text' => 'Keep routes, services, persistence and UI close to the capability they serve.'],
        ['number' => '3', 'title' => 'Ship the small path', 'text' => 'Run the gates, build the dist and deploy without a production dependency pile.'],
    ];

    private function featureGrid(): string
    {
        $cards = '';

        foreach (self::FEATURES as $item) {
            $cards .= '<article class="er-feature-card"><span class="er-feature-card__number">'
                . self::e($item['number']) . '</span><h3>'
                . self::e($item['title']) . '</h3><p>'
                . self::e($item['text']) . '</p></article>';
        }

        return '<div class="er-feature-grid">' . $cards . '</div>';
    }

    private function steps(): string
    {
        $items = '';

        foreach (self::STEPS as $item) {
            $items .= '<article class="er-step"><span>' . self::e($item['number'])
                . '</span><div><h3>' . self::e($item['title']) . '</h3><p>'
                . self::e($item['text']) . '</p></div></article>';
        }

        return '<div class="er-steps">' . $items . '</div>';
    }

    /**
     * News cards from the same slug+title shape the blog family reads;
     * date/category/author/excerpt/image ride along only when the producer
     * carries them (the kernel HomepageAction passes no articles at all —
     * see latestFallback — so every field beyond slug+title is optional).
     *
     * @param array<array-key, mixed> $articles
     */
    private function newsGrid(array $articles): string
    {
        $cards = '';

        foreach ($articles as $article) {
            $card = self::newsCard($article);

            if (null !== $card) {
                $cards .= $card;
            }
        }

        if ('' === $cards) {
            return '<div class="er-placeholder"><p>Nothing published yet.</p></div>';
        }

        return '<div class="er-news-grid">' . $cards . '</div>';
    }

    /**
     * @return array{slug: string, title: string, excerpt: string, date: string, category: string, author: string, image: ?string}|null
     */
    private static function newsFields(object $article): ?array
    {
        $fields = get_object_vars($article);
        $slug = $fields['slug'] ?? null;
        $title = $fields['title'] ?? null;

        if (!is_string($slug) || !is_string($title)) {
            return null;
        }

        $text = static function (string $key) use ($fields): string {
            $value = $fields[$key] ?? '';

            return is_string($value) ? $value : '';
        };

        $image = $fields['image'] ?? $fields['cover'] ?? $fields['coverImage'] ?? null;

        return [
            'slug' => $slug,
            'title' => $title,
            'excerpt' => $text('excerpt'),
            'date' => $text('date'),
            'category' => $text('category'),
            'author' => $text('author'),
            'image' => is_string($image) && '' !== $image ? $image : null,
        ];
    }

    private static function newsCard(mixed $article): ?string
    {
        if (!is_object($article)) {
            return null;
        }

        $fields = self::newsFields($article);

        if (null === $fields) {
            return null;
        }

        $media = null !== $fields['image']
            ? '<img src="' . self::e($fields['image']) . '" alt="" loading="lazy">'
            : '<span class="er-news-card__placeholder" aria-hidden="true"></span>';

        $meta = implode(' · ', array_filter([
            $fields['category'],
            self::formatDate($fields['date']),
            $fields['author'],
        ], static fn (string $bit): bool => '' !== $bit));

        $body = '' !== $meta
            ? '<p class="er-news-card__meta">' . self::e($meta) . '</p>'
            : '';

        $body .= '<h3><a href="/blog/' . self::e($fields['slug']) . '">'
            . self::e($fields['title']) . '</a></h3>';

        if ('' !== $fields['excerpt']) {
            $body .= '<p>' . self::e($fields['excerpt']) . '</p>';
        }

        return '<article class="er-news-card">' . $media
            . '<div class="er-news-card__body">' . $body . '</div></article>';
    }

    /**
     * Locale-neutral date (numeric — no English month names on Slovak
     * pages); the raw value survives when unparseable.
     */
    private static function formatDate(string $date): string
    {
        if ('' === $date) {
            return '';
        }

        $ts = strtotime($date);

        return false === $ts ? $date : date('j. n. Y', $ts);
    }

    /**
     * Latest published articles when the action passed none. The kernel
     * HomepageAction carries no Blog import by explicit contract, so it
     * never passes any — the Griffe twin of this fallback proved the shape.
     * Guarded by class_exists: a tree without Blog renders the sections
     * around an honest placeholder, and a failure degrades to empty,
     * never to a white homepage — logged, so the silence stays visible.
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
            $cards = $facade::articles()->latestCards(3, $locale);
        } catch (\Throwable $e) {
            error_log('[ErikTheme] latest fallback failed: ' . $e->getMessage());

            return [];
        }

        return array_map(static fn (array $card): object => (object) $card, $cards);
    }

    private function proof(): string
    {
        return '<aside class="er-proof"><p class="er-proof__quote">“'
            . 'The shortest path is often the one you can still explain six months later.'
            . '”</p><p class="er-proof__author">Nativa engineering principle</p></aside>';
    }

    /**
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const array COMPARISON = [
        ['Layers', 'Fewer by default', 'Many by default'],
        ['Runtime', 'PHP + SQLite', 'Service stack'],
        ['Control', 'Explicit seams', 'Framework magic'],
    ];

    private function comparison(): string
    {
        $rows = '';

        foreach (self::COMPARISON as [$criterion, $mine, $other]) {
            $rows .= '<div class="er-comparison__row"><strong>' . self::e($criterion)
                . '</strong><span>' . self::e($mine) . '</span><span>' . self::e($other) . '</span></div>';
        }

        return '<div class="er-comparison"><div class="er-comparison__head">'
            . '<span>Criteria</span><b>Nativa</b><span>Typical stack</span></div>'
            . $rows . '</div>';
    }

    /**
     * The closing CTA. Its eyebrow, headline and paragraph are the theme's own
     * design copy and have no rows in the `home` group, so they stay as written;
     * the BUTTON is the same slot the hero's primary action reads, so it reads
     * `home.cta_blog` too rather than a second English label for one link.
     */
    private function closingCta(PageContext $context): string
    {
        $href = $context->blogHref ?? '/blog';
        $label = $context->copy['home.cta_blog'] ?? 'Explore the blog';

        return '<section class="er-closing-cta"><div><p class="er-section__eyebrow">Start simple</p>'
            . '<h2>Build the part that matters.</h2>'
            . '<p>Start with a small runtime and let the product earn every extra layer.</p></div>'
            . '<a class="er-button er-button--primary" href="' . self::e($href) . '">' . self::e($label) . '</a></section>';
    }

    private function homeSection(string $eyebrow, string $title, string $content, string $modifier): string
    {
        return '<section class="er-section ' . $modifier . '">'
            . '<p class="er-section__eyebrow">' . self::e($eyebrow) . '</p>'
            . '<h2 class="er-section__title">' . self::e($title) . '</h2>'
            . $content . '</section>';
    }

    public function blogIndex(PageContext $context): string
    {
        return $this->pageWrap(
            $this->section('Blog', $this->searchForm($context, '') . $this->links($context->articles)),
        );
    }

    public function blogSearch(PageContext $context): string
    {
        return $this->pageWrap(
            $this->section(
                'Search: ' . $context->query,
                $this->searchForm($context, $context->query) . $this->links($context->results),
            ),
        );
    }

    /**
     * The blog's search control, under the title and above the list.
     *
     * This theme's shell carries no field, so without this the blog would be
     * searchable only by typing `?q=` by hand. The action is the locale's blog
     * base, never `getPathInfo()`: LocaleMiddleware strips the `/en` prefix
     * before routing, so the path here is `/blog` even for an English reader,
     * and posting to it would search the default corpus. Boson's shell builds
     * the same address from the same mapper. The button reuses `er-button`, the
     * theme's own control vocabulary.
     */
    private function searchForm(PageContext $context, string $query): string
    {
        return '<form role="search" method="get" action="'
            . self::e(ContentServices::mapper()->urlPrefix($context->locale) . '/blog') . '">'
            . '<label for="er-blog-search">Search articles</label>'
            . '<input id="er-blog-search" name="q" type="search"'
            . ' placeholder="Search articles" value="' . self::e($query) . '" autocomplete="off">'
            . '<button class="er-button" type="submit">Search</button>'
            . '</form>';
    }

    /**
     * The article surface, read the way Boson reads one: the prose column
     * beside a sticky "On this page" rail, both inside the theme's own
     * `er-article-layout` grid.
     *
     * The rail is built from `PageContext::$headings` — the anchors
     * `MarkdownRenderer` itself generated — so a link can never point at an id
     * the body does not carry. The links carry `data-toc-link`/`data-heading`,
     * which is the opt-in `toc-scrollspy.ts` in this bundle already looks for;
     * before this, erik shipped the rail's stylesheet and none of its markup,
     * and the scrollspy's own header comment recorded it as the one theme that
     * matched nothing.
     *
     * The kernel wraps article output in `FrontendLayout::readerLayout()`, which
     * is a no-op when the document already answers `aria-label="On this page"`
     * — that marker is how one rail is guaranteed rather than two.
     */
    public function blogArticle(PageContext $context): string
    {
        $article = '<article class="er-article">'
            . '<h1 class="er-section__title">' . self::e($context->title) . '</h1>'
            . '<div class="er-article__body">' . $context->bodyHtml . '</div>'
            . '</article>';

        $toc = self::toc($context->headings);

        // No rail, no grid: one entry is furniture, and a 15rem column holding
        // a single link would narrow the article to show it.
        if ('' === $toc) {
            return $this->pageWrap($article);
        }

        return $this->pageWrap(
            '<div class="er-article-layout">' . $article . $toc . '</div>',
        );
    }

    /**
     * The floating contents rail, or '' when there is nothing worth listing.
     *
     * @param list<array{level?: int, text?: string, anchor?: string}> $headings
     */
    private static function toc(array $headings): string
    {
        $links = '';
        $listed = 0;

        foreach ($headings as $heading) {
            if (!is_array($heading)) {
                continue;
            }

            $anchor = $heading['anchor'] ?? '';
            $text = $heading['text'] ?? '';

            if (!is_string($anchor) || '' === $anchor || !is_string($text) || '' === trim($text)) {
                continue;
            }

            $listed++;
            $links .= '<a class="er-toc__link" href="#' . self::e($anchor) . '"'
                . ' data-toc-link data-heading="' . self::e($anchor) . '">'
                . self::e(trim($text)) . '</a>';
        }

        if ($listed < 2) {
            return '';
        }

        return '<aside class="er-toc" aria-label="On this page">'
            . '<p class="er-toc__title">On this page</p>'
            . '<nav class="er-toc__nav">' . $links . '</nav>'
            . '</aside>';
    }

    public function articleTail(string $html): string
    {
        return $html;
    }

    public function blogTags(PageContext $context): string
    {
        $title = null !== $context->tag ? 'Tag: ' . $context->tag : 'Tags';

        return $this->pageWrap($this->section($title, $this->links($context->articles)));
    }

    public function blogCategories(PageContext $context): string
    {
        $title = null !== $context->category ? $context->category : 'Categories';

        return $this->pageWrap($this->section($title, $this->links($context->articles)));
    }

    public function authorProfile(PageContext $context): string
    {
        return $this->pageWrap($this->section($this->authorName($context), $this->links($context->articles)));
    }

    public function errorContent(int $status, string $title, string $detail): string
    {
        return '<section class="er-section">'
            . '<h1 class="er-section__title">' . $status . ' ' . self::e($title) . '</h1>'
            . '<p>' . self::e($detail) . '</p>'
            . '<p><a href="/">Back to homepage</a></p>'
            . '</section>';
    }

    private function section(string $title, string $body): string
    {
        return '<section class="er-section">'
            . '<h1 class="er-section__title">' . self::e($title) . '</h1>'
            . $body
            . '</section>';
    }

    /**
     * @param array<array-key, mixed> $articles
     */
    private function links(array $articles): string
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
                $pairs[] = ['slug' => $slug, 'title' => $title];
            }
        }

        if ([] === $pairs) {
            return '<p class="er-section__empty">Nothing published yet.</p>';
        }

        $items = '';

        foreach ($pairs as $pair) {
            $items .= '<li><a href="/blog/' . self::e($pair['slug']) . '">'
                . self::e($pair['title']) . '</a></li>';
        }

        return '<ul class="er-list">' . $items . '</ul>';
    }

    private function authorName(PageContext $context): string
    {
        $author = $context->author ?? [];
        $name = $author['name'] ?? null;

        return is_string($name) && '' !== $name ? $name : 'Author';
    }

    /**
     * @param list<string> $parts
     */
    private function chrome(string $tag, array $parts): string
    {
        $html = '<' . $tag . ' class="er-' . $tag . '">' . "\n";

        foreach ($parts as $part) {
            if ('' !== $part) {
                $html .= '    ' . $part . "\n";
            }
        }

        return $html . '</' . $tag . '>' . "\n";
    }

    /**
     * @param list<ShellNavItem> $items
     */
    private function nav(array $items): string
    {
        if ([] === $items) {
            return '';
        }

        $links = '';

        foreach ($items as $item) {
            $links .= $this->link($item);
        }

        return '<nav class="er-nav">' . $links . '</nav>';
    }

    private function link(ShellNavItem $item): string
    {
        $link = '<a class="er-nav__link" href="' . self::e($item->href) . '"'
            . ($item->active ? ' aria-current="page"' : '')
            . ($item->external ? ' rel="noopener noreferrer"' : '')
            . '>' . self::e($item->label) . '</a>';

        foreach ($item->children as $child) {
            $link .= $this->link($child);
        }

        return $link;
    }

    private function localeSwitcher(ShellContext $context): string
    {
        if (count($context->availableLocales) < 2) {
            return '';
        }

        $links = '';

        foreach ($context->availableLocales as $language) {
            $href = $context->localeLinks[$language] ?? ContentServices::mapper()->urlPrefix($language) . $context->currentPath;

            $links .= '<a class="er-locale__link" href="' . self::e($href) . '"'
                . ($language === $context->activeLocale ? ' aria-current="true"' : '')
                . '>' . self::e($language) . '</a>';
        }

        return '<div class="er-locale">' . $links . '</div>';
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
