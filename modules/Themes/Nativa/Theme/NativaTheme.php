<?php

declare(strict_types = 1);

namespace Modules\Themes\Nativa\Theme;

use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Domain\ThemeSetting;
use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Themes\AuthPageTheme;
use Themes\DocsPageTheme;
use Themes\ThemeModule;
use Themes\ThemeSettings;

/**
 * NativaTheme — the house theme: Griffe's restraint, Boson's coverage.
 *
 * The two catalogue themes this one is drawn from answer different questions.
 * Griffe is the calmer of the two — hairline rules, a warm paper ground, a text
 * nav, hand-written CSS — and it stops at the `ThemeModule` contract, so
 * `/docs` and the auth pages fall back to the kernel's own look. Boson is the
 * only theme that also answers `DocsPageTheme` and `AuthPageTheme`, and the
 * price is a fixed 100 px header shrinking on scroll over 2 962 lines of CSS.
 *
 * This theme takes the first one's vocabulary and the second one's surface
 * list. It is NOT a merge of the two classes: a slide slider and a
 * documentation column cannot share a type scale, so the design is written
 * once, here, and the two references supply only the decisions they were good
 * at.
 *
 * ## The rules it holds to
 *
 * - **No build, no JavaScript.** One critical stylesheet, no `@font-face`, no
 *   bundle: a deployment without Node serves exactly what `php -S` serves.
 *   The one pre-paint script is the kernel-wide `data-theme` restore, copied
 *   verbatim from the other themes.
 * - **One vocabulary, `na-`.** Two stylesheets share a document at `/mark`
 *   (the admin panel loads the theme sheet), so a class without the prefix is
 *   a class that can style the panel.
 * - **It imports nothing from a feature module.** Article shapes are read
 *   through `get_object_vars`; the blog address comes from the kernel's own
 *   `ContentLocaleServices` mapper, never from a Blog class.
 */
final class NativaTheme implements AuthPageTheme, DocsPageTheme, ThemeModule, ThemeSettings
{
    public function name(): string
    {
        return 'nativa';
    }

    public function bundle(): string
    {
        return 'nativa';
    }

    /**
     * No bundle to defer: `AssetManager` finds no manifest and inlines
     * `criticalCss()` instead, which is the whole stylesheet here. The key
     * still names the theme so a future build adds one without renaming it.
     */
    public function jsKey(): string
    {
        return 'nativa';
    }

    /** @return list<string> src/-relative, resolved by InlineCss */
    public function criticalCss(): array
    {
        return ['Modules/Nativa/assets/critical/nativa.css'];
    }

    /**
     * Pre-paint colour-mode resolution (the shared dark/light restore, verbatim
     * from the other themes).
     *
     * The stylesheet keys its tokens on `[data-theme="dark"]`, so this is the
     * only script the theme ships: nothing here waits for a bundle, and the
     * restore cannot cause the flash the attribute exists to prevent — it runs
     * before first paint, in `<head>`.
     */
    public function themeInitScript(): string
    {
        return <<<'HTML'
            <!-- Sync theme init — FOUC prevention: restore the persisted theme
                 (or the OS preference) before first paint. Falls back to the
                 light default when neither is available (Chrome 70 baseline). -->
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
        return 'na';
    }

    /**
     * One admin choice, the shape the other themes use: which glyph the phone
     * menu toggle draws. `auto` is the theme's own default so an install that
     * never opens the settings screen renders what it always rendered.
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
                    'auto'   => 'Auto (theme default: burger)',
                    'burger' => 'Burger (three lines)',
                    'dots'   => 'Dots (three dots)',
                ],
                'auto',
            ),
        ];
    }

    /**
     * Hairline header: brand left, menu links centre, theme toggle + locale +
     * phone toggle right. The phone menu is a checkbox, so it opens with no
     * JavaScript at all; the desktop header is sticky in CSS.
     *
     * DOM order is the `~` contract: the input precedes `.na-header__inner`,
     * which holds the menu and the label that drives it.
     */
    public function header(ShellContext $context): string
    {
        $hasMenu = [] !== $context->headerNav;
        $icon = 'dots' === ($context->themeSettings['nav_toggle_icon'] ?? 'auto') ? 'dots' : 'burger';

        return '<header class="na-header">' . "\n"
            . self::menuToggleInput($hasMenu)
            . '<div class="na-container na-header__inner">' . "\n"
            . '<a class="na-header__brand" href="/">' . self::e($context->siteName) . '</a>' . "\n"
            . $this->menu($context->headerNav)
            . '<div class="na-header__actions">' . "\n"
            . self::themeToggle()
            . $this->localeLinks($context)
            . self::menuToggleLabel($hasMenu, $icon)
            . '</div>' . "\n"
            . '</div>' . "\n"
            . '</header>' . "\n";
    }

    /** One rule above the line: © line, footer nav, locale pair. */
    public function footer(ShellContext $context): string
    {
        return '<footer class="na-footer"><div class="na-container na-footer__inner">'
            . '<span>© ' . self::e($context->year) . ' ' . self::e($context->siteName) . '</span>'
            . $this->footerLinks($context->footerNav)
            . $this->localeLinks($context)
            . '</div></footer>' . "\n";
    }

    public function pageWrap(string $content): string
    {
        return '<main class="na-main">' . "\n"
            . '    ' . $content . "\n"
            . '</main>' . "\n";
    }

    /**
     * The landing surface: an editorial hero and the latest articles as a
     * hairline card grid.
     *
     * The hero is asymmetric on purpose — the display title holds the left
     * two-thirds, the lede and the two actions answer from the right, with a
     * hairline rule between them. That is the composition neither reference
     * uses: Boson centres its hero in a fixed header's shadow, Griffe puts
     * everything on a fullscreen slider. One payload, no custom element.
     */
    public function homepage(PageContext $context): string
    {
        $eyebrow = $context->copy['home.eyebrow'] ?? $context->copy['hero_eyebrow'] ?? '';
        $title = $context->copy['home.title'] ?? $context->copy['title'] ?? 'Home';
        $lede = $context->copy['home.message'] ?? $context->copy['lede'] ?? '';
        $cta = $context->copy['home.cta_start'] ?? $context->copy['cta'] ?? 'Read the blog';
        $blog = self::blogBase($context);

        $hero = '<section class="na-hero"><div class="na-container na-hero__inner">'
            . '<div class="na-hero__lead">';

        if ('' !== $eyebrow) {
            $hero .= '<p class="na-hero__eyebrow">' . self::e($eyebrow) . '</p>';
        }

        $hero .= '<h1 class="na-hero__title">' . self::e($title) . '</h1>'
            . '</div>'
            . '<div class="na-hero__aside">';

        if ('' !== $lede) {
            $hero .= '<p class="na-hero__lede">' . self::e($lede) . '</p>';
        }

        $hero .= '<div class="na-hero__actions">'
            . '<a class="na-btn" href="' . self::e($blog) . '">' . self::e($cta) . '</a>'
            . '<a class="na-btn na-btn--link" href="' . self::e(AdminRoutePolicy::prefix()) . '">Open admin</a>'
            . '</div></div></div></section>';

        $articles = [] !== $context->latestArticles ? $context->latestArticles : $context->articles;

        return $hero . self::section(
            $context->copy['home.latest'] ?? 'Latest',
            self::cards($articles, $context),
        );
    }

    public function blogIndex(PageContext $context): string
    {
        return self::section(
            'Blog',
            self::searchForm($context, '') . self::cards($context->articles, $context) . self::pager($context),
        );
    }

    public function blogSearch(PageContext $context): string
    {
        return self::section(
            'Search: ' . $context->query,
            self::searchForm($context, $context->query) . self::links($context->results, $context),
        );
    }

    /**
     * Article anatomy: kicker, H1, meta line, cover, prose body, tag chips,
     * views, comments, and a sticky contents rail beside the column.
     *
     * Raw HTML fields (`bodyHtml`, `metaLine`, `tagChips`, `commentsHtml`,
     * `inlineEditHtml`) pass through by contract — the producers escape them.
     * Everything else goes through `e()`.
     */
    public function blogArticle(PageContext $context): string
    {
        $article = '<article class="na-article">'
            . '<p class="na-kicker">Article</p>'
            . '<h1 class="na-article__title">' . self::e($context->title) . '</h1>';

        if ('' !== $context->metaLine) {
            $article .= '<p class="na-article__meta">' . $context->metaLine . '</p>';
        }

        $cover = '' !== $context->coverUrl ? $context->coverUrl : $context->coverImage;

        if ('' !== $cover) {
            $article .= '<img class="na-article__cover" src="' . self::e($cover) . '" alt="">';
        }

        $article .= '<div class="na-article__body">' . $context->bodyHtml . '</div>';

        if ('' !== $context->tagChips) {
            $article .= '<div class="na-chips">' . $context->tagChips . '</div>';
        }

        if (0 < $context->views) {
            $article .= '<p class="na-article__meta">' . $context->views . ' views</p>';
        }

        $article .= $context->inlineEditHtml . $context->commentsHtml . '</article>';

        $toc = self::articleToc($context->headings);

        return '<section class="na-section"><div class="na-container na-reader">'
            . $article . $toc
            . '</div></section>';
    }

    public function articleTail(string $html): string
    {
        return $html;
    }

    public function blogTags(PageContext $context): string
    {
        $title = null !== $context->tag ? 'Tag: ' . $context->tag : 'Tags';

        return self::section(
            $title,
            self::cloud($context->tags, $context->counts, 'tag', $context->tag)
            . self::links($context->articles, $context),
        );
    }

    public function blogCategories(PageContext $context): string
    {
        $title = null !== $context->category ? $context->category : 'Categories';

        return self::section(
            $title,
            self::cloud($context->categories, $context->counts, 'category', $context->category)
            . self::links($context->articles, $context),
        );
    }

    public function authorProfile(PageContext $context): string
    {
        $author = $context->author ?? [];
        $name = $author['name'] ?? null;
        $name = is_string($name) && '' !== $name ? $name : 'Author';
        $avatar = $author['avatar'] ?? null;

        $head = '<h1 class="na-section__title">' . self::e($name) . '</h1>';

        if (is_string($avatar) && '' !== $avatar) {
            $head .= '<img class="na-author__avatar" src="' . self::e($avatar) . '" alt="">';
        }

        if ('' !== $context->memberSince) {
            $head .= '<p class="na-article__meta">Member since ' . self::e($context->memberSince) . '</p>';
        }

        return '<section class="na-section"><div class="na-container">' . $head
            . self::cards($context->articles, $context) . '</div></section>';
    }

    /**
     * The guide list. The catalogue boundary is the action's (`DocsCatalog`):
     * every entry here is a published key, so the cards cannot link a page
     * that would 404.
     *
     * @param list<array{key: string, title: string, description: string}> $entries
     */
    public function docsIndex(array $entries): string
    {
        if ([] === $entries) {
            return '<section class="na-section"><div class="na-container">'
                . '<h1 class="na-section__title">Docs</h1>'
                . '<p class="na-empty">No guides are published in this deployment.</p>'
                . '</div></section>';
        }

        $cards = '';

        foreach ($entries as $entry) {
            $cards .= '<article class="na-card">'
                . '<h2 class="na-card__title"><a href="/docs/' . self::e($entry['key']) . '">'
                . self::e($entry['title']) . '</a></h2>';

            if ('' !== $entry['description']) {
                $cards .= '<p class="na-card__excerpt">' . self::e($entry['description']) . '</p>';
            }

            $cards .= '<a class="na-card__more" href="/docs/' . self::e($entry['key']) . '">Read →</a>'
                . '</article>';
        }

        return '<section class="na-section"><div class="na-container">'
            . '<h1 class="na-section__title">Docs</h1>'
            . '<div class="na-grid">' . $cards . '</div>'
            . '</div></section>';
    }

    /**
     * One guide. `$bodyHtml` is raw by contract (`MarkdownRenderer` output).
     *
     * No contents rail is composed here: the action wraps this in the kernel's
     * reader layout unless the document already carries one, so the guide gets
     * the reading surface without a second rail — and without a rule in this
     * stylesheet that a heading change could outgrow.
     */
    public function docsPage(string $key, string $title, string $bodyHtml): string
    {
        return '<article class="na-article na-docs__body">'
            . '<p class="na-kicker">Guide</p>'
            . '<h1 class="na-article__title">' . self::e($title) . '</h1>'
            . $bodyHtml
            . '</article>';
    }

    /**
     * The auth surface (login, password reset): heading, errors, form, footnote.
     *
     * `$hiddenHtml` and `$footnote` are trusted HTML by contract (CSRF +
     * `return_to` inputs, a static link line); `$alert` is plain text and is
     * escaped here, which is the reason the parameter is typed `?string` rather
     * than `?string` of markup.
     *
     * @param list<array{name: string, type?: string, label?: string, value?: mixed, required?: bool, autofocus?: bool}> $fields
     * @param list<string>                                                                                               $errors
     */
    public function authPage(
        string $title,
        string $formAction,
        string $submitLabel,
        array $fields,
        string $hiddenHtml,
        array $errors,
        ?string $footnote = null,
        ?string $alert = null,
    ): string {
        $html = '<section class="na-section"><div class="na-container na-auth">'
            . '<h1 class="na-section__title">' . self::e($title) . '</h1>';

        if (null !== $alert && '' !== $alert) {
            $html .= '<p class="na-auth__alert" role="alert">' . self::e($alert) . '</p>';
        }

        if ([] !== $errors) {
            $html .= '<ul class="na-auth__errors">';

            foreach ($errors as $error) {
                $html .= '<li class="na-auth__error">' . self::e($error) . '</li>';
            }

            $html .= '</ul>';
        }

        $html .= '<form class="na-auth__form" method="post" action="' . self::e($formAction) . '">'
            . $hiddenHtml;

        foreach ($fields as $field) {
            $name = $field['name'];
            $type = $field['type'] ?? 'text';
            $label = $field['label'] ?? ucfirst($name);
            $value = $field['value'] ?? '';
            $id = 'na-auth-' . $name;

            $html .= '<p class="na-auth__field">'
                . '<label class="na-auth__label" for="' . self::e($id) . '">' . self::e($label) . '</label>'
                . '<input class="na-auth__input" id="' . self::e($id) . '" name="' . self::e($name) . '"'
                . ' type="' . self::e($type) . '" value="' . self::e($value) . '"'
                . (($field['required'] ?? false) ? ' required' : '')
                . (($field['autofocus'] ?? false) ? ' autofocus' : '')
                . '>' . '</p>';
        }

        $html .= '<button class="na-btn" type="submit">' . self::e($submitLabel) . '</button>'
            . '</form>';

        if (null !== $footnote && '' !== $footnote) {
            $html .= '<p class="na-auth__footnote">' . $footnote . '</p>';
        }

        return $html . '</div></section>';
    }

    /** The inner error section; the caller wraps it in the layout. */
    public function errorContent(int $status, string $title, string $detail): string
    {
        return '<section class="na-section"><div class="na-container na-error">'
            . '<p class="na-kicker">Error ' . $status . '</p>'
            . '<h1 class="na-section__title">' . self::e($title) . '</h1>'
            . '<p>' . self::e($detail) . '</p>'
            . '<p><a class="na-btn" href="/">Back to homepage</a></p>'
            . '</div></section>';
    }

    /**
     * The blog address for a locale — the kernel's mapper, never a literal and
     * never a Blog class: the route prefix is the locale system's to spell.
     */
    private static function blogBase(PageContext $context): string
    {
        return ContentLocaleServices::mapper()->urlPrefix($context->locale) . '/blog';
    }

    /**
     * The card grid: numbered, hairline-separated, with a lead card once there
     * are enough articles to fill a row.
     *
     * The number is the grid's own rhythm — it makes a list of unrelated
     * titles read as an issue's contents — and it costs one span, not an
     * image: the whole row grid stays text-first on a tree with no artwork.
     *
     * @param array<array-key, mixed> $articles
     */
    private static function cards(array $articles, PageContext $context): string
    {
        $pairs = self::pairs($articles);

        if ([] === $pairs) {
            return '<p class="na-empty">Nothing published yet.</p>';
        }

        $base = self::blogBase($context);
        $total = count($pairs);
        $cards = '';
        $index = 0;

        foreach ($pairs as $pair) {
            $index++;
            $href = $base . '/' . $pair['slug'];
            // A lead card only when a second row exists to be led.
            $lead = 1 === $index && $total >= 4 ? ' na-card--lead' : '';

            $cards .= '<article class="na-card' . $lead . '">'
                . '<span class="na-card__index">' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . '</span>';

            if ('' !== $pair['meta']) {
                $cards .= '<p class="na-card__meta">' . self::e($pair['meta']) . '</p>';
            }

            $cards .= '<h2 class="na-card__title"><a href="' . self::e($href) . '">'
                . self::e($pair['title']) . '</a></h2>';

            if (null !== $pair['image']) {
                $cards .= '<img class="na-card__image" loading="lazy" src="' . self::e($pair['image']) . '" alt="">';
            }

            if ('' !== $pair['excerpt']) {
                $cards .= '<p class="na-card__excerpt">' . self::e($pair['excerpt']) . '</p>';
            }

            $cards .= '<a class="na-card__more" href="' . self::e($href) . '">Read →</a>'
                . '</article>';
        }

        return '<div class="na-grid">' . $cards . '</div>';
    }

    /**
     * Slug + title are required; everything else is opportunistic — the same
     * shape the payload already carries, read through `get_object_vars` and
     * never by importing the module that produces it.
     *
     * `$meta` is the one-line date/category label under the number: the cards
     * payload names them `date` and `category`, an entity may carry only one,
     * and the join is skipped when neither is there rather than printing a
     * separator with nothing around it.
     *
     * @param array<array-key, mixed> $articles
     * @return list<array{slug: string, title: string, image: ?string, meta: string, excerpt: string}>
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

            if (!is_string($slug) || !is_string($title)) {
                continue;
            }

            $image = $fields['image'] ?? $fields['cover'] ?? $fields['coverImage'] ?? null;
            $excerpt = $fields['excerpt'] ?? $fields['description'] ?? null;

            $labels = [];

            foreach (['date', 'category'] as $key) {
                $value = $fields[$key] ?? null;

                if (is_string($value) && '' !== $value) {
                    $labels[] = $value;
                }
            }

            $pairs[] = [
                'slug' => $slug,
                'title' => $title,
                'image' => is_string($image) && '' !== $image ? $image : null,
                'meta' => implode(' · ', $labels),
                'excerpt' => is_string($excerpt) ? trim($excerpt) : '',
            ];
        }

        return $pairs;
    }

    /**
     * @param array<array-key, mixed> $articles
     */
    private static function links(array $articles, PageContext $context): string
    {
        $pairs = self::pairs($articles);

        if ([] === $pairs) {
            return '<p class="na-empty">Nothing published yet.</p>';
        }

        $base = self::blogBase($context);
        $items = '';

        foreach ($pairs as $pair) {
            $items .= '<li><a href="' . self::e($base . '/' . $pair['slug']) . '">'
                . self::e($pair['title']) . '</a></li>';
        }

        return '<ul class="na-list">' . $items . '</ul>';
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

            $chips .= '<a class="na-chip' . ($term === $active ? ' na-chip--active' : '') . '"'
                . ' href="/blog/' . $kind . '/' . self::e($term) . '">'
                . self::e($label) . '</a>';
        }

        return '<div class="na-chips">' . $chips . '</div>';
    }

    /**
     * The search control above the cards. This theme's shell carries no field,
     * so without it the blog would be searchable only by typing `?q=` by hand.
     * The action is the locale's blog base: `LocaleMiddleware` strips the `/en`
     * prefix before routing, so posting to `getPathInfo()` would search the
     * default corpus for an English reader.
     */
    private static function searchForm(PageContext $context, string $query): string
    {
        return '<form class="na-search" role="search" method="get" action="'
            . self::e(self::blogBase($context)) . '">'
            . '<label class="na-search__label" for="na-blog-search">Search articles</label>'
            . '<input class="na-search__input" id="na-blog-search" name="q" type="search"'
            . ' placeholder="Search articles" value="' . self::e($query) . '" autocomplete="off">'
            . '<button class="na-btn" type="submit">Search</button>'
            . '</form>';
    }

    private static function pager(PageContext $context): string
    {
        if ($context->totalPages < 2) {
            return '';
        }

        $html = '<nav class="na-pager" aria-label="Pages">';

        for ($i = 1; $i <= $context->totalPages; $i++) {
            $html .= '<a class="na-chip' . ($i === $context->page ? ' na-chip--active' : '') . '"'
                . ' href="?page=' . $i . '">' . $i . '</a>';
        }

        return $html . '</nav>';
    }

    private static function section(string $title, string $body): string
    {
        return '<section class="na-section"><div class="na-container">'
            . '<h1 class="na-section__title">' . self::e($title) . '</h1>'
            . $body
            . '</div></section>';
    }

    /**
     * The "On this page" rail from `MarkdownRenderer::extractHeadings()` —
     * server-side anchors only, '' when there is nothing to navigate. The
     * `data-toc-link` / `data-heading` opt-ins mirror the shared contract, and
     * the rail is the reason this stylesheet declares `position: sticky`.
     *
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    private static function articleToc(array $headings): string
    {
        $items = '';

        foreach ($headings as $heading) {
            $anchor = $heading['anchor'] ?? '';
            $text = $heading['text'] ?? '';

            if (!is_string($anchor) || '' === $anchor || !is_string($text) || '' === trim($text)) {
                continue;
            }

            $itemClass = 3 === ($heading['level'] ?? 0) ? ' na-toc__item--sub' : '';
            $items .= '<li class="na-toc__item' . $itemClass . '">'
                . '<a class="na-toc__link" data-toc-link data-heading="' . self::e($anchor) . '"'
                . ' href="#' . self::e($anchor) . '">' . self::e($text) . '</a></li>';
        }

        if ('' === $items) {
            return '';
        }

        return '<aside class="na-toc"><nav aria-label="On this page">'
            . '<p class="na-toc__title">On this page</p>'
            . '<ul class="na-toc__list">' . $items . '</ul>'
            . '</nav></aside>';
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

        return '<nav class="na-nav" aria-label="Primary">' . $links . '</nav>' . "\n";
    }

    private function menuLink(ShellNavItem $item): string
    {
        $link = '<a class="na-nav__link" href="' . self::e($item->href) . '"'
            . ($item->active ? ' aria-current="page"' : '')
            . ($item->external ? ' rel="noopener noreferrer"' : '')
            . '>' . self::e($item->label) . '</a>';

        foreach ($item->children as $child) {
            $link .= $this->menuLink($child);
        }

        return $link;
    }

    /**
     * The checkbox the phone label toggles: the input is first so one `~`
     * selector reaches the menu and the label alike. Rendered only when there
     * is a menu to open.
     */
    private static function menuToggleInput(bool $hasMenu): string
    {
        if (!$hasMenu) {
            return '';
        }

        return '<input type="checkbox" id="na-nav-toggle" class="na-nav__toggle-input" aria-label="Open navigation">' . "\n";
    }

    /** Both closed-state glyphs and the close glyph ship; CSS shows one. */
    private static function menuToggleLabel(bool $hasMenu, string $icon = 'burger'): string
    {
        if (!$hasMenu) {
            return '';
        }

        $variant = 'dots' === $icon ? ' na-nav__toggle--dots' : '';

        return '<label class="na-nav__toggle na-icon-btn' . $variant . '" for="na-nav-toggle" title="Menu">'
            . '<span class="na-nav__toggle-icon--burger" aria-hidden="true">'
            . '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round">'
            . '<line x1="4" y1="7" x2="20" y2="7"></line>'
            . '<line x1="4" y1="12" x2="20" y2="12"></line>'
            . '<line x1="4" y1="17" x2="20" y2="17"></line>'
            . '</svg></span>'
            . '<span class="na-nav__toggle-icon--dots" aria-hidden="true">'
            . '<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">'
            . '<circle cx="12" cy="5" r="2"></circle>'
            . '<circle cx="12" cy="12" r="2"></circle>'
            . '<circle cx="12" cy="19" r="2"></circle>'
            . '</svg></span>'
            . '<span class="na-nav__toggle-icon--close" aria-hidden="true">'
            . '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round">'
            . '<path d="M6 6l12 12"></path><path d="M18 6L6 18"></path>'
            . '</svg></span>'
            . '</label>' . "\n";
    }

    /**
     * The shared dark/light switcher (sun in dark mode, moon in light mode).
     * The click is owned by the theme-toggle handler `FrontendLayout` inlines
     * for every theme, which wires each `[data-theme-toggle]`.
     */
    private static function themeToggle(): string
    {
        return '<button type="button" class="na-icon-btn na-theme-toggle" data-theme-toggle aria-label="Toggle theme">'
            . '<svg class="na-theme-toggle__sun" width="20" height="20" viewBox="0 0 24 24" fill="none"'
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
            . '<svg class="na-theme-toggle__moon" width="20" height="20" viewBox="0 0 24 24" fill="none"'
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
            $href = $context->localeLinks[$language] ?? ContentLocaleServices::mapper()->urlPrefix($language) . $context->currentPath;

            $links .= '<a class="na-locale__link" href="' . self::e($href) . '"'
                . ($language === $context->activeLocale ? ' aria-current="true"' : '')
                . '>' . self::e($language) . '</a>';
        }

        return '<div class="na-locale">' . $links . '</div>' . "\n";
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
            $links .= '<a href="' . self::e($item->href) . '">' . self::e($item->label) . '</a>';
        }

        return '<div class="na-footer__links">' . $links . '</div>';
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
