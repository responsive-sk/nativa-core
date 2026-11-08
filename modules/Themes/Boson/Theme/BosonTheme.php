<?php

declare(strict_types = 1);

namespace Modules\Themes\Boson\Theme;

use Modules\Core\Application\Services\AssetManager;
use Modules\Core\Application\Services\View\InlineJs;
use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Domain\ThemeSetting;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Themes\AuthPageTheme;
use Themes\DocsPageTheme;
use Themes\ThemeModule;
use Themes\ThemeSettings;

/**
 * BosonTheme — the boson-php marketing site's design as a kernel theme module.
 *
 * The upstream reference (the boson-php marketing site — Symfony/Twig with a
 * Lit component body, styles inside shadow roots, artwork in `/images`) is
 * not vendored here. This port keeps the DESIGN and drops the machinery:
 * the markup is server-rendered here, the vocabulary is `bo-` prefixed, and
 * every visual is CSS — no Lit runtime, no @font-face pointing at a vendor
 * path, no image request the tree does not have.
 *
 * ## What the design is
 *
 * A fixed 100 px header that shrinks to 70 px on scroll, dotted rails framing
 * every band, hairline borders instead of shadows, Roboto Condensed display
 * type over Inter body copy, and one brand red (#f93904) on near-black
 * (#0d1119). Sections are full-bleed bands with a slash-marked uppercase
 * label and a large two-column statement; the "how it works" half is a
 * horizontal accordion, the comparison half a ruled table.
 *
 * ## Divergences, each because the reference had something this tree does not
 *
 * 1. **No Lit components.** The reference defines `boson-header`,
 *    `segment-section`, `nativeness-section`, `solves-section`,
 *    `how-it-works-section`, `right-choice-section`,
 *    `mobile-development-section`, `testimonials-section` and a dozen UI
 *    elements. Server-rendered HTML with one vendored script
 *    (`assets/scripts/boson.js`: header shrink, checkbox menu, accordion,
 *    code highlighting) replaces all of them; the page is complete with the
 *    script absent, and nothing downloads a framework to toggle a class.
 * 2. **No artwork vendored from the reference.** `/images/logo.svg`,
 *    `/images/icon.svg`, the platform icons and the screenshots are not in
 *    this repository. The orbit rings, the platform marks and the dotted
 *    rails are CSS drawings; the reference's photographic backgrounds are
 *    gone rather than fetched from a remote host at runtime. The one image
 *    the pages DO load is the site's own mark (`/favicon.svg`, the file the
 *    head already declares as the icon) — the brand does not depend on the
 *    reference's asset set.
 * 3. **Nativa copy, Boson structure.** Headlines, section labels and the
 *    accordion are this project's, with `PageContext::$copy` read first and
 *    the defaults below as the floor — the same shape Erik, Mono and
 *    Jednotka use. A translation provider therefore renames the homepage
 *    without editing this file.
 * 4. **No testimonials with invented names.** The reference's quote slider
 *    carried named endorsements; inventing them here would be a lie dressed
 *    as content. The band survives as three unattributed principles.
 *
 * ## It imports nothing from a feature module
 *
 * Article shapes are read through `get_object_vars`, never the entity class,
 * so the blog surfaces render whatever the kernel hands over and this
 * module's install closure stays empty. The homepage carries no article band
 * at all — the reference's landing is a statement page, and its only path to
 * the blog is the CTA.
 *
 * ## The build, and the fallback it falls back FROM
 *
 * The CSS and the behaviour script are vendored in this module
 * (`assets/critical/boson.css`, `assets/scripts/boson.js`) and stay there: a
 * tree that never runs `make assets` still renders the theme, because
 * `criticalCss()` inlines the stylesheet and `pageWrap()` inlines the script.
 * `Frontend/packages/boson/` bundles those SAME two files into
 * `public/dist/boson/`; once that manifest exists, `FrontendLayout` inlines
 * the minified stylesheet and emits the deferred `<script src>` instead — the
 * build optimises the vendored bytes rather than duplicating them.
 *
 * The script therefore has one source of truth at runtime: the bundle when it
 * resolves, the inline file when it does not. `pageWrap()` asks
 * `AssetManager` before inlining, so a built tree cannot run the same IIFE
 * twice.
 */
final class BosonTheme implements ThemeModule, AuthPageTheme, DocsPageTheme, ThemeSettings
{
    /** The reference's image-less, framework-free copy. */
    private const array DEFAULT_COPY = [
        'home.eyebrow' => 'Modular PHP',
        'home.hero_title_1' => 'Keep PHP.',
        'home.hero_title_2' => 'Drop the framework.',
        'home.hero_subtitle' => 'A modular monolith you can read end to end: per-module SQLite, attribute routing, and no runtime dependencies to audit around your code.',
        'home.cta_blog' => 'Read the blog',
        'home.cta_admin' => 'Open admin',
        'home.discovery' => 'Discover how it works',
        'home.premise_title' => 'Familiar PHP. Now as modules.',
        'home.premise_text' => 'The kernel answers only what every site needs — routing, middleware, a render layout, per-module storage — and a module answers the rest. Remove a module and its routes, tables and theme leave with it.',
        'home.solves_title' => 'What you can do with Nativa?',
        'home.solves_text' => 'Start with the kernel, add only the surfaces a product actually has, and keep every one of them replaceable.',
        'home.works_title' => 'Under the hood of Nativa',
        'home.works_text' => 'A request enters the kernel, passes one middleware pipeline, and lands in a module action. Nothing between those three points is generated, cached or reflected at runtime.',
        'home.api_title' => 'One core, many surfaces',
        'home.api_text' => 'The same ports serve the public site, the admin panel and the CLI. A theme renders what the kernel hands it; the admin is a module; the command line reads the same catalogue.',
        'home.principles_title' => 'Built on a few promises',
        'home.cta_title' => 'If you write PHP, you can already ship a modular application.',
        'home.cta_accent' => 'Get started right now!',
        'home.works_1_title' => 'One request, one pipeline',
        'home.works_1_text' => 'A route resolves to an action through attributes, the middleware runs in the order the kernel declares, and the action receives a typed request. No service locator, no generated map.',
        'home.works_2_title' => 'Per-module SQLite',
        'home.works_2_text' => 'Each module owns its database file and its schema. There is no central migration maze, no ORM, and no shared connection string to configure.',
        'home.works_3_title' => 'Ports, not imports',
        'home.works_3_text' => 'A theme, a shell provider and an auth provider answer through kernel-owned ports. The dependency edge points at the kernel, never at a feature module.',
        'home.works_4_title' => 'A shell that degrades',
        'home.works_4_text' => 'A broken or missing provider falls back to the kernel answer and logs the skip — a thinner page, not a 500 on every surface.',
        'home.works_5_title' => 'Zero runtime dependencies',
        'home.works_5_text' => 'No Composer runtime, no framework package, no build step required to serve a page. The production runtime is PHP and its bundled extensions.',
    ];

    /**
     * The five "under the hood" items, in reading order. Copy keys are derived
     * from the number, so a translator renames each headline and paragraph
     * without touching the structure.
     *
     * @var list<int>
     */
    private const array WORKS = [1, 2, 3, 4, 5];

    public function name(): string
    {
        return 'boson';
    }

    public function bundle(): string
    {
        return 'boson';
    }

    public function jsKey(): string
    {
        return 'boson';
    }

    /**
     * @return list<string> src/-relative, resolved by InlineCss
     */
    public function criticalCss(): array
    {
        return ['Modules/Boson/assets/critical/boson.css'];
    }

    /**
     * The shared dark/light restore (`''` keeps the kernel's exact bytes).
     *
     * boson is a dark design by construction — `:root` IS the dark palette and
     * no rule reads `[data-theme]` — so the restore changes no colour here. It
     * is still left to the shared block rather than overridden: a persisted
     * choice must keep working for the pages that later grow a light variant,
     * and duplicating the script would be a second thing to drift.
     */
    public function themeInitScript(): string
    {
        return '';
    }

    public function prefix(): string
    {
        return 'bo';
    }

    /**
     * The one choice Boson offers its admin: which glyph the mobile menu
     * toggle draws.
     *
     * `auto` is the default and means THIS theme's own icon (the three lines
     * the design was ported with), so an install that never opens the settings
     * screen renders exactly what it rendered before the choice existed. The
     * variant is a class on the same label, not a second markup path: the
     * checkbox, the `~` selectors that open the panel and the scroll lock are
     * all keyed off the DOM the label lives in, and a second label would be a
     * second place for that contract to drift.
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

    /**
     * The icon the header actually draws, resolved from the stored choice.
     *
     * `auto` and anything the schema would have rejected land on the burger —
     * the theme's own default. Unknown values cannot really arrive (the kernel
     * vets them against the schema first), but a theme that trusts its input
     * completely is one rename away from a header with no icon at all.
     */
    private static function navIcon(ShellContext $context): string
    {
        return 'meatball' === ($context->themeSettings['nav_toggle_icon'] ?? 'auto') ? 'meatball' : 'burger';
    }

    /**
     * The reference's fixed header: dotted rails at both ends, the wordmark,
     * the nav, the search field and the action cluster, shrinking from 100 to
     * 70 px once the page scrolls.
     *
     * The mobile menu is a checkbox like Griffe's, so it opens without the
     * script; the script only syncs `bo-nav-open` for the scroll lock and
     * Escape. The input is the header's FIRST child, because the panel and the
     * label are both reached with `~` — the order is a contract, pinned by the
     * test.
     */
    public function header(ShellContext $context): string
    {
        $hasNav = [] !== $context->headerNav;
        $blog = self::urlPrefix($context->activeLocale) . '/blog';
        $search = '<form class="bo-search" method="get" action="' . self::e($blog) . '" role="search">'
            . '<label class="bo-sr-only" for="bo-search-input">Search</label>'
            . '<input class="bo-search__input" id="bo-search-input" type="search" name="q"'
            . ' placeholder="Search" autocomplete="off">'
            . '</form>';

        return '<header class="bo-header" data-bo-header>' . "\n"
            . self::burgerInput($hasNav)
            . '<div class="bo-header__bar">' . "\n"
            . '<span class="bo-header__dots" aria-hidden="true"></span>' . "\n"
            . '<a class="bo-header__brand" href="/">'
            . '<span class="bo-header__mark"><img class="bo-header__mark-img" src="' . self::e(self::logo()) . '" alt="" width="22" height="22"></span>'
            . '<span class="bo-header__wordmark">' . self::e($context->siteName) . '</span>'
            . '</a>' . "\n"
            . $this->nav($context->headerNav)
            . $search
            . '<div class="bo-actions">' . "\n"
            . $this->localeLinks($context)
            . '<a class="bo-btn bo-btn--ghost bo-actions__admin" href="'
            . self::e(AdminRoutePolicy::prefix()) . '">Admin</a>' . "\n"
            . self::burgerLabel($hasNav, self::navIcon($context))
            . '</div>' . "\n"
            . '<span class="bo-header__dots" aria-hidden="true"></span>' . "\n"
            . '</div>' . "\n"
            . $this->mobileMenu($context) . "\n"
            . '</header>' . "\n";
    }

    /**
     * The reference's ruled footer: two rows between dotted rails, with the
     * social/aside cells reduced to the links this tree actually has (blog,
     * admin, home) — no GitHub or Discord icon pointing at another project.
     */
    public function footer(ShellContext $context): string
    {
        $admin = AdminRoutePolicy::prefix();
        $secondary = '';

        foreach ($context->footerNav as $item) {
            $secondary .= '<a class="bo-footer__link" href="' . self::e($item->href) . '"'
                . ($item->external ? ' rel="noopener noreferrer"' : '') . '>'
                . self::e($item->label) . '</a>';
        }

        return '<footer class="bo-footer">' . "\n"
            . '<div class="bo-footer__content">' . "\n"
            . '<div class="bo-footer__top">' . "\n"
            . '<span class="bo-footer__holder" aria-hidden="true"></span>' . "\n"
            . '<a class="bo-footer__social" href="/blog">Blog</a>' . "\n"
            . '<a class="bo-footer__social" href="' . self::e($admin) . '">Admin</a>' . "\n"
            . '<span class="bo-footer__dots" aria-hidden="true"></span>' . "\n"
            . '<a class="bo-footer__aside" href="/">Home</a>' . "\n"
            . $this->localeLinks($context, 'bo-footer__aside')
            . '<span class="bo-footer__holder" aria-hidden="true"></span>' . "\n"
            . '</div>' . "\n"
            . '<div class="bo-footer__bottom">' . "\n"
            . '<span class="bo-footer__holder" aria-hidden="true"></span>' . "\n"
            . '<span class="bo-footer__copyright">&copy; ' . $context->year . ' '
            . self::e($context->siteName) . '</span>' . "\n"
            . $secondary
            . '<span class="bo-footer__holder" aria-hidden="true"></span>' . "\n"
            . '</div>' . "\n"
            . '</div>' . "\n"
            . '</footer>' . "\n";
    }

    /**
     * The page body plus the one vendored behaviour script — inline only when
     * there is no build for `FrontendLayout` to defer.
     *
     * `jsTag()` already emits the bundle as `<script src="/dist/boson/…"
     * defer>` once `public/dist/boson/manifest.json` resolves. Inlining the
     * vendored file as well would run every init twice — duplicate scroll
     * listeners, duplicate copy buttons — so the inline copy is the fallback
     * for a tree without the build, the same shape `criticalCss()` has for the
     * stylesheet. The script sits after `</main>` so the header it reads is
     * already parsed, and every init self-gates on its markup, so a page
     * without an accordion or a fenced block runs nothing.
     */
    public function pageWrap(string $content): string
    {
        $built = null !== (new AssetManager($this->bundle()))->jsUrl($this->jsKey());
        $js = $built ? '' : InlineJs::file('Modules/Boson/assets/scripts/boson.js');

        return '<main class="bo-main">' . "\n"
            . '    ' . $content . "\n"
            . '</main>' . "\n"
            . ('' === $js
                ? ''
                : '<script nonce="__CSP_NONCE_PLACEHOLDER__">' . "\n" . $js . "\n" . '</script>' . "\n");
    }

    /**
     * The landing: hero, premise, the two ruled bands, the accordion, the
     * comparison, the API split, the principles and the closing call.
     */
    public function homepage(PageContext $context): string
    {
        return $this->hero($context)
            . self::segment(
                'Premise',
                self::t($context, 'home.premise_title'),
                '<p>' . self::e(self::t($context, 'home.premise_text')) . '</p>',
            )
            . self::surfaces()
            . self::startingPoints()
            . self::segment(
                'Solves',
                self::t($context, 'home.solves_title'),
                '<p>' . self::e(self::t($context, 'home.solves_text')) . '</p>'
                . self::bullets([
                    'Launch a public site and a blog from the same kernel.',
                    'Add an admin panel as a module, not as a second application.',
                    'Replace a module — or a theme — without editing the kernel.',
                ]),
            )
            . self::columns()
            . self::segment(
                'How it works',
                self::t($context, 'home.works_title'),
                '<p>' . self::e(self::t($context, 'home.works_text')) . '</p>',
            )
            . self::accordion($context)
            . self::comparison()
            . self::split($context)
            . self::principles($context)
            . self::closing($context);
    }

    /**
     * The blog index: ruled header, search, card grid, pager.
     */
    public function blogIndex(PageContext $context): string
    {
        $perPage = $context->perPage > 0 ? $context->perPage : 9;
        $totalPages = max(1, (int) ceil(count($context->articles) / $perPage));
        $page = max(1, min($context->page, $totalPages));
        $slice = array_slice($context->articles, ($page - 1) * $perPage, $perPage);

        return self::page(
            'Index',
            'Blog',
            'Plain Markdown, one module, no build required.',
            self::cards($slice, $context->locale)
            . self::pager($context, $totalPages, $page),
        );
    }

    /**
     * Search results share the index's grid; the heading carries the query.
     *
     * No search form: the shell already carries one. The header's field posts to
     * `/blog` (and to `/docs` from inside the guides) and the menu holds a
     * second copy for a narrow viewport, so a form here would put the same
     * input named `q` on the page a third time, all three pointing at one URL.
     * The query stays visible in the heading, so a miss is narrowed from the
     * field above it rather than from a box of its own.
     */
    public function blogSearch(PageContext $context): string
    {
        return self::page(
            'Search',
            'Search: ' . $context->query,
            count($context->results) . ' ' . (1 === count($context->results) ? 'result' : 'results'),
            self::cards($context->results, $context->locale)
            . self::pager($context),
        );
    }

    /**
     * The article: prose column beside a contents rail.
     *
     * Raw HTML fields (`bodyHtml`, `metaLine`, `tagChips`, `commentsHtml`,
     * `inlineEditHtml`) pass through by contract; everything else escapes.
     */
    public function blogArticle(PageContext $context): string
    {
        $cover = '' !== $context->coverUrl ? $context->coverUrl : $context->coverImage;
        $views = $context->views > 0
            ? '<span class="bo-article__views">' . $context->views . ' views</span>'
            : '';

        $article = '<article class="bo-article">'
            . '<header class="bo-article__head">'
            . '<p class="bo-page__label">Article</p>'
            . '<h1 class="bo-article__title">' . self::e($context->title) . '</h1>'
            . ('' === $context->metaLine
                ? ''
                : '<p class="bo-article__meta">' . $context->metaLine . $views . '</p>')
            . ('' === $context->editUrl
                ? ''
                : '<p class="bo-article__edit"><a class="bo-article__edit-link" href="'
                    . self::e($context->editUrl) . '">' . self::e($context->editLabel) . '</a></p>')
            . ('' === $cover
                ? ''
                : '<img class="bo-article__cover" src="' . self::e($cover) . '" alt="" loading="eager" decoding="async">')
            . '</header>'
            . $context->inlineEditHtml
            . '<div class="bo-article__body">' . $context->bodyHtml . '</div>'
            . ('' === $context->tagChips ? '' : '<div class="bo-article__tags">' . $context->tagChips . '</div>')
            . $context->commentsHtml
            . '</article>';

        $toc = self::toc($context->headings);

        return '<section class="bo-page bo-page--article"><div class="bo-article-layout">'
            . '<div class="bo-article-layout__main">' . $article . '</div>'
            . $toc
            . '</div></section>';
    }

    /**
     * Comments and reader questions belong under the article's reading column,
     * not after the page container: an action appends them to the composed
     * page, so without this they sit outside `.bo-page` and run edge to edge.
     */
    public function articleTail(string $html): string
    {
        if ('' === $html) {
            return '';
        }

        return '<section class="bo-page bo-page--article-tail"><div class="bo-article-layout">'
            . '<div class="bo-article-layout__main">' . $html . '</div>'
            . '</div></section>';
    }

    public function blogTags(PageContext $context): string
    {
        $title = null !== $context->tag ? 'Tag: ' . $context->tag : 'Tags';

        return self::page(
            'Taxonomy',
            $title,
            'Browse articles by tag.',
            self::cloud($context->tags, $context->counts, 'tags', $context->tag, $context->locale)
            . self::cards($context->articles, $context->locale)
            . self::pager($context),
        );
    }

    public function blogCategories(PageContext $context): string
    {
        $title = null !== $context->category ? $context->category : 'Categories';

        return self::page(
            'Taxonomy',
            $title,
            'Browse articles by category.',
            self::cloud($context->categories, $context->counts, 'categories', $context->category, $context->locale)
            . self::cards($context->articles, $context->locale)
            . self::pager($context),
        );
    }

    public function authorProfile(PageContext $context): string
    {
        $author = $context->author ?? [];
        $name = $author['name'] ?? null;
        $name = is_string($name) && '' !== $name ? $name : 'Author';
        $avatar = $author['avatar'] ?? null;

        $head = '<header class="bo-author">'
            . (is_string($avatar) && '' !== $avatar
                ? '<img class="bo-author__avatar" src="' . self::e($avatar) . '" alt="" width="64" height="64" loading="lazy">'
                : '')
            . '<div class="bo-author__body">'
            . '<p class="bo-page__label">Author</p>'
            . '<h1 class="bo-author__name">' . self::e($name) . '</h1>'
            . ('' === $context->memberSince
                ? ''
                : '<p class="bo-author__meta">Member since ' . self::e($context->memberSince) . '</p>')
            . '</div></header>';

        return '<section class="bo-page">' . $head
            . self::cards($context->articles, $context->locale)
            . self::pager($context)
            . '</section>';
    }

    /**
     * The auth surfaces — login, register, forgot and reset — in the
     * reference's ruled vocabulary: a statement on the left, one bordered
     * panel on the right.
     *
     * `$hiddenHtml` arrives ready to place inside the form: `AuthTemplate`
     * builds the CSRF field and the validated `return_to` input, so this method
     * cannot omit either. The footnote is trusted HTML (a static sentence from
     * the action); the title, labels, values, errors and alert are escaped
     * here.
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
        $fieldsHtml = '';

        foreach ($fields as $field) {
            $type = $field['type'] ?? 'text';
            $name = $field['name'];
            $label = $field['label'] ?? ucfirst($name);
            $rawValue = $field['value'] ?? '';
            $value = is_string($rawValue) ? $rawValue : '';
            $required = ($field['required'] ?? true) ? ' required' : '';
            $autofocus = ($field['autofocus'] ?? false) ? ' autofocus' : '';
            $id = 'bo-auth-' . $name;

            if ('hidden' === $type) {
                $fieldsHtml .= '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';

                continue;
            }

            if ('checkbox' === $type) {
                $fieldsHtml .= '<div class="bo-field bo-field--check">'
                    . '<input class="bo-field__check" type="checkbox" id="' . self::e($id) . '" name="' . self::e($name) . '"'
                    . $required . '>'
                    . '<label class="bo-field__label bo-field__label--check" for="' . self::e($id) . '">'
                    . self::e($label) . '</label>'
                    . '</div>';

                continue;
            }

            $fieldsHtml .= '<div class="bo-field">'
                . '<label class="bo-field__label" for="' . self::e($id) . '">' . self::e($label) . '</label>'
                . '<input class="bo-field__input" type="' . self::e($type) . '" id="' . self::e($id) . '"'
                . ' name="' . self::e($name) . '" value="' . self::e($value) . '"'
                . $required . $autofocus . '>'
                . '</div>';
        }

        $errorsHtml = '';

        if ([] !== $errors) {
            $items = '';

            foreach ($errors as $error) {
                $items .= '<li class="bo-auth__error">' . self::e($error) . '</li>';
            }

            $errorsHtml = '<div class="bo-auth__errors" role="alert">'
                . '<ul class="bo-auth__error-list">' . $items . '</ul>'
                . '</div>';
        }

        return '<section class="bo-page bo-auth">'
            . '<div class="bo-auth__layout">'
            . '<div class="bo-auth__intro">'
            . '<p class="bo-page__label">Account</p>'
            . '<h1 class="bo-auth__title">' . self::e($title) . '</h1>'
            . '</div>'
            . '<div class="bo-auth__panel">'
            . (null !== $alert && '' !== $alert
                ? '<p class="bo-auth__alert" role="status">' . self::e($alert) . '</p>'
                : '')
            . $errorsHtml
            . '<form class="bo-auth__form" method="post" action="' . self::e($formAction) . '">'
            . $fieldsHtml
            . $hiddenHtml
            . '<button class="bo-btn bo-btn--primary bo-auth__submit" type="submit">'
            . self::e($submitLabel) . '</button>'
            . '</form>'
            . (null !== $footnote && '' !== $footnote
                ? '<p class="bo-auth__footnote">' . $footnote . '</p>'
                : '')
            . '</div>'
            . '</div>'
            . '</section>';
    }

    /**
     * The /docs index: the ruled head and a card grid of the published guides.
     *
     * @param list<array{key: string, title: string, description: string}> $entries
     */
    public function docsIndex(array $entries): string
    {
        $items = '';

        foreach ($entries as $entry) {
            $key = $entry['key'] ?? null;
            $title = $entry['title'] ?? null;
            $description = $entry['description'] ?? null;

            if (!is_string($key) || '' === $key || !is_string($title) || '' === $title) {
                continue;
            }

            $items .= '<li class="bo-docs__item">'
                . '<a class="bo-docs__link" href="/docs/' . self::e($key) . '">' . self::e($title) . '</a>'
                . (is_string($description) && '' !== $description
                    ? '<p class="bo-docs__text">' . self::e($description) . '</p>'
                    : '')
                . '</li>';
        }

        return '<section class="bo-page bo-docs">'
            . '<header class="bo-page__head">'
            . '<p class="bo-page__label">Reference</p>'
            . '<h1 class="bo-page__title">Documentation</h1>'
            . '<p class="bo-page__sub">The guides that ship with the repository, rendered by the site.</p>'
            . '</header>'
            . ('' === $items
                ? '<p class="bo-empty">No guides are published in this deployment.</p>'
                : '<ul class="bo-docs__list">' . $items . '</ul>')
            . '</section>';
    }

    /**
     * One guide: back link, ruled head with its catalogue key, rendered body.
     * `$bodyHtml` is MarkdownRenderer output and passes through by contract.
     */
    public function docsPage(string $key, string $title, string $bodyHtml): string
    {
        return '<section class="bo-page bo-page--article bo-docs">'
            . '<p class="bo-docs__back"><a class="bo-docs__back-link" href="/docs">All guides</a></p>'
            . '<header class="bo-article__head">'
            . '<p class="bo-page__label">Guide</p>'
            . '<h1 class="bo-article__title">' . self::e($title) . '</h1>'
            . '<p class="bo-article__meta">' . self::e($key) . '</p>'
            . '</header>'
            . '<div class="bo-article__body">' . $bodyHtml . '</div>'
            . '</section>';
    }

    public function errorContent(int $status, string $title, string $detail): string
    {
        return '<section class="bo-page bo-page--error">'
            . '<p class="bo-page__label">Error ' . $status . '</p>'
            . '<h1 class="bo-page__title">' . self::e($title) . '</h1>'
            . '<p class="bo-page__sub">' . self::e($detail) . '</p>'
            . '<p><a class="bo-btn bo-btn--primary" href="/">Back to the homepage</a></p>'
            . '</section>';
    }

    /**
     * The hero: copy left, a CSS orbit right, and the discover strip the
     * reference uses to jump to the first ruled band.
     */
    private function hero(PageContext $context): string
    {
        $blog = null !== $context->blogHref && '' !== $context->blogHref
            ? $context->blogHref
            : $this->blogBase($context->locale);

        return '<section class="bo-hero">'
            . '<div class="bo-hero__inner">'
            . '<div class="bo-hero__copy">'
            . '<p class="bo-hero__eyebrow">' . self::e(self::t($context, 'home.eyebrow')) . '</p>'
            . '<h1 class="bo-hero__title">'
            . '<span class="bo-hero__title--brand">' . self::e(self::t($context, 'home.hero_title_1')) . '</span>'
            . '<span class="bo-hero__title--line">' . self::e(self::t($context, 'home.hero_title_2')) . '</span>'
            . '</h1>'
            . '<p class="bo-hero__desc">' . self::e(self::t($context, 'home.hero_subtitle')) . '</p>'
            . '<div class="bo-hero__actions">'
            . '<a class="bo-btn bo-btn--primary" href="' . self::e($blog) . '">'
            . self::e(self::t($context, 'home.cta_blog')) . '</a>'
            . '<a class="bo-btn bo-btn--ghost" href="' . self::e(AdminRoutePolicy::prefix()) . '">'
            . self::e(self::t($context, 'home.cta_admin')) . '</a>'
            . '</div>'
            . '</div>'
            . '<div class="bo-hero__visual" aria-hidden="true">'
            . '<div class="bo-orbit">'
            . '<span class="bo-orbit__ring"></span>'
            . '<span class="bo-orbit__ring bo-orbit__ring--inner"></span>'
            . '<span class="bo-orbit__core"><img class="bo-orbit__mark" src="' . self::e(self::logo()) . '" alt="" width="72" height="72"></span>'
            . '<span class="bo-orbit__dot"></span>'
            . '<span class="bo-orbit__dot bo-orbit__dot--tail"></span>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '<a class="bo-discover" href="#bo-surfaces">'
            . '<span class="bo-discover__inner">'
            . '<span class="bo-discover__text">' . self::e(self::t($context, 'home.discovery')) . '</span>'
            . '<span class="bo-discover__arrow" aria-hidden="true">&darr;</span>'
            . '</span></a>'
            . '</section>';
    }

    /**
     * A ruled statement band: slash-marked label and a large title on the
     * left, prose on the right (the reference's `segment-section`).
     */
    private static function segment(string $label, string $title, string $body, string $type = ''): string
    {
        $modifier = '' === $type ? '' : ' bo-segment--' . $type;

        return '<section class="bo-segment' . $modifier . '">'
            . '<div class="bo-segment__head">'
            . '<p class="bo-segment__label">' . self::e($label) . '</p>'
            . '<h2 class="bo-segment__title">' . self::e($title) . '</h2>'
            . '</div>'
            . '<div class="bo-segment__body">' . $body . '</div>'
            . '</section>';
    }

    /**
     * The platform band from `nativeness-section`, re-lettered as this tree's
     * surfaces: four ruled cells on a dotted rail, the first one lit.
     */
    private static function surfaces(): string
    {
        $cells = [
            ['WEB', 'Web'],
            ['BLOG', 'Blog'],
            ['ADMIN', 'Admin'],
            ['CLI', 'CLI'],
        ];
        $html = '';

        foreach ($cells as $index => $cell) {
            $html .= '<div class="bo-surface' . (0 === $index ? ' bo-surface--active' : '') . '">'
                . '<span class="bo-surface__mark" aria-hidden="true">' . self::e($cell[0]) . '</span>'
                . '<span class="bo-surface__name">' . self::e($cell[1]) . '</span>'
                . '</div>';
        }

        return '<section class="bo-surfaces" id="bo-surfaces" aria-label="Surfaces">'
            . '<span class="bo-dots bo-dots--left" aria-hidden="true"></span>'
            . '<div class="bo-surfaces__row">' . $html . '</div>'
            . '<span class="bo-dots bo-dots--right" aria-hidden="true"></span>'
            . '</section>';
    }

    /**
     * The three "technology" panels from the same component, here as the three
     * starting points this tree ships.
     */
    private static function startingPoints(): string
    {
        $panels = [
            ['01', 'Bare core', 'The kernel alone: routing, middleware, layout, per-module storage. Every other surface is a module you add.'],
            ['02', 'Blog', 'Markdown files, tags, categories, authors and a reading surface — installed as a module, removable the same way.'],
            ['03', 'Admin', 'A panel module with its own theme and route policy, mounted under the configured admin prefix.'],
        ];
        $html = '';

        foreach ($panels as [$number, $name, $text]) {
            $html .= '<article class="bo-stack">'
                . '<span class="bo-stack__badge">' . self::e($number) . '</span>'
                . '<h3 class="bo-stack__name">' . self::e($name) . '</h3>'
                . '<p class="bo-stack__text">' . self::e($text) . '</p>'
                . '</article>';
        }

        return '<section class="bo-stacks" aria-label="Starting points">' . $html . '</section>';
    }

    /**
     * The three-column band from `solves-section`.
     */
    private static function columns(): string
    {
        $columns = [
            ['For developers', 'One request path to read, one port per concern, and a module boundary you can hold in your head.'],
            ['For teams', 'A module owns its routes, tables, tests and theme. Two people can work on two modules without a shared map to merge.'],
            ['For the long run', 'No runtime dependency to upgrade around. The code you audited is the code that ships.'],
        ];
        $html = '';

        foreach ($columns as [$title, $text]) {
            $html .= '<article class="bo-column">'
                . '<span class="bo-column__mark" aria-hidden="true"></span>'
                . '<h3 class="bo-column__title">' . self::e($title) . '</h3>'
                . '<p class="bo-column__text">' . self::e($text) . '</p>'
                . '</article>';
        }

        return '<section class="bo-columns" aria-label="Who it is for">' . $html . '</section>';
    }

    /**
     * The reference's horizontal accordion: one item open, the rest collapsed
     * to their number. Server-renders item 1 open so the band reads without
     * the script; the script only moves the open state.
     */
    private static function accordion(PageContext $context): string
    {
        $items = '';

        foreach (self::WORKS as $index => $number) {
            $open = 0 === $index;
            $title = self::t($context, 'home.works_' . $number . '_title');
            $text = self::t($context, 'home.works_' . $number . '_text');
            $id = 'bo-works-' . $number;

            $items .= '<div class="bo-accordion__item' . ($open ? ' bo-accordion__item--open' : '') . '"'
                . ' data-bo-accordion-item>'
                . '<button class="bo-accordion__trigger" type="button"'
                . ' aria-expanded="' . ($open ? 'true' : 'false') . '"'
                . ' aria-controls="' . $id . '" id="' . $id . '-trigger">'
                . '<span class="bo-accordion__num">0' . $number . '</span>'
                . '<span class="bo-accordion__title">' . self::e($title) . '</span>'
                . '<span class="bo-accordion__plus" aria-hidden="true"></span>'
                . '</button>'
                . '<div class="bo-accordion__panel" id="' . $id . '" role="region"'
                . ' aria-labelledby="' . $id . '-trigger">'
                . '<p class="bo-accordion__text">' . self::e($text) . '</p>'
                . '</div>'
                . '</div>';
        }

        return '<section class="bo-works" aria-label="Under the hood">'
            . '<div class="bo-accordion" data-bo-accordion>' . $items . '</div>'
            . '</section>';
    }

    /**
     * The reference's scroll comparison, frozen into a ruled table: the
     * argument does not need a two-screen animation to be read.
     */
    private static function comparison(): string
    {
        $rows = [
            ['Layers between the request and your code', 'One pipeline', 'Framework, container, ORM'],
            ['Runtime dependencies', 'None', 'Dozens'],
            ['Data ownership', 'Per-module SQLite', 'Shared connection'],
            ['Module removal', 'Routes, tables and theme leave together', 'Manual cleanup'],
        ];

        $body = '';

        foreach ($rows as [$label, $ours, $theirs]) {
            $body .= '<div class="bo-compare__row" role="row">'
                . '<span class="bo-compare__label" role="cell">' . self::e($label) . '</span>'
                . '<span class="bo-compare__cell bo-compare__cell--yes" role="cell">'
                . '<span class="bo-compare__mark" aria-hidden="true">+</span>' . self::e($ours) . '</span>'
                . '<span class="bo-compare__cell bo-compare__cell--no" role="cell">'
                . '<span class="bo-compare__mark" aria-hidden="true">&minus;</span>' . self::e($theirs) . '</span>'
                . '</div>';
        }

        // Every role here is load-bearing: `role="table"` may only own
        // `rowgroup`/`row`, and a `row` may only own `cell`/`columnheader`.
        // The body rows shipped without roles, so the table had rows whose
        // children were plain spans — the audit's "required children are
        // missing", and a screen reader that announced nothing. A native
        // `<table>` would say it without ARIA, but `.bo-compare__row` is a
        // grid, and `display: block` on table elements drops their table
        // semantics in some engines — the explicit roles are what survive the
        // CSS this band is drawn with.
        return '<section class="bo-compare" aria-label="Comparison">'
            . '<div class="bo-compare__head">'
            . '<p class="bo-segment__label">The right choice</p>'
            . '<h2 class="bo-segment__title">Less machinery.<br>Clearer ownership.</h2>'
            . '</div>'
            . '<div class="bo-compare__table" role="table" aria-label="Nativa compared with a typical framework">'
            . '<div role="rowgroup">'
            . '<div class="bo-compare__row bo-compare__row--head" role="row">'
            . '<span class="bo-compare__label" role="columnheader">Question</span>'
            . '<span class="bo-compare__cell bo-compare__cell--yes" role="columnheader">Nativa</span>'
            . '<span class="bo-compare__cell bo-compare__cell--no" role="columnheader">Typical framework</span>'
            . '</div>'
            . '</div>'
            . '<div role="rowgroup">'
            . $body
            . '</div>'
            . '</div>'
            . '</section>';
    }

    /**
     * `mobile-development-section`'s split shape: the argument pins on the
     * left while the four API notes scroll on the right. Here both halves are
     * static; the sticky left column is CSS only.
     */
    private static function split(PageContext $context): string
    {
        $notes = [
            ['Routes as attributes', 'A controller declares its path, method and name next to the method. The router reads the attribute; nothing is generated.'],
            ['Ports as contracts', 'Themes, shell providers and auth providers answer through kernel-owned interfaces, so a feature module never becomes a theme dependency.'],
            ['Themes as modules', 'A theme arrives with its module and leaves with it. Discovery scans the installed tree; the whitelist is not edited by hand.'],
            ['Admin as a module', 'The panel, its routes and its own theme install as one module under one policy prefix.'],
        ];
        $html = '';

        foreach ($notes as [$title, $text]) {
            $html .= '<article class="bo-split__item">'
                . '<span class="bo-split__badge" aria-hidden="true"></span>'
                . '<div class="bo-split__body">'
                . '<h3 class="bo-split__title">' . self::e($title) . '</h3>'
                . '<p class="bo-split__text">' . self::e($text) . '</p>'
                . '</div></article>';
        }

        $blog = null !== $context->blogHref && '' !== $context->blogHref
            ? $context->blogHref
            : self::blogBaseFor($context->locale);

        return '<section class="bo-split">'
            . '<div class="bo-split__left">'
            . '<div class="bo-split__sticky">'
            . '<p class="bo-segment__label">Rich API</p>'
            . '<h2 class="bo-segment__title">' . self::e(self::t($context, 'home.api_title')) . '</h2>'
            . '<p class="bo-split__text">' . self::e(self::t($context, 'home.api_text')) . '</p>'
            . '<p><a class="bo-btn bo-btn--ghost" href="' . self::e($blog) . '">Read the blog</a></p>'
            . '</div></div>'
            . '<div class="bo-split__right">' . $html . '</div>'
            . '</section>';
    }

    /**
     * The testimonials band without testimonials: three promises, unattributed
     * (see divergence 4 in the class docblock).
     */
    private static function principles(PageContext $context): string
    {
        $principles = [
            ['One owner per name', 'If two places can spell the same value, one of them is a copy waiting to drift. The kernel names it once.'],
            ['A module can leave', 'An installed module takes its routes, tables, discovery entries and theme with it — no orphaned row, no dead option.'],
            ['The fallback is honest', 'A missing provider degrades to the kernel answer and logs the skip. Nothing renders another module\'s data and calls it success.'],
        ];
        $html = '';

        foreach ($principles as [$label, $text]) {
            $html .= '<article class="bo-quote">'
                . '<p class="bo-quote__text">&ldquo;' . self::e($text) . '&rdquo;</p>'
                . '<p class="bo-quote__label">' . self::e($label) . '</p>'
                . '</article>';
        }

        return '<section class="bo-principles" aria-label="Principles">'
            . '<p class="bo-segment__label">' . self::e(self::t($context, 'home.principles_title')) . '</p>'
            . '<div class="bo-quotes">' . $html . '</div>'
            . '</section>';
    }

    /**
     * The closing band: one statement, one accent line, two actions.
     */
    private static function closing(PageContext $context): string
    {
        $blog = null !== $context->blogHref && '' !== $context->blogHref
            ? $context->blogHref
            : self::blogBaseFor($context->locale);

        return '<section class="bo-cta">'
            . '<div class="bo-cta__inner">'
            . '<h3 class="bo-cta__title">' . self::e(self::t($context, 'home.cta_title')) . '</h3>'
            . '<p class="bo-cta__accent">' . self::e(self::t($context, 'home.cta_accent')) . '</p>'
            . '<div class="bo-cta__actions">'
            . '<a class="bo-btn bo-btn--primary" href="' . self::e($blog) . '">'
            . self::e(self::t($context, 'home.cta_blog')) . '</a>'
            . '<a class="bo-btn bo-btn--ghost" href="' . self::e(AdminRoutePolicy::prefix()) . '">'
            . self::e(self::t($context, 'home.cta_admin')) . '</a>'
            . '</div>'
            . '</div>'
            . '</section>';
    }

    /**
     * The blog surfaces' shared shell: ruled head, optional sub-line, body.
     */
    private static function page(
        string $label,
        string $title,
        string $sub,
        string $body,
    ): string {
        return '<section class="bo-page">'
            . '<header class="bo-page__head">'
            . '<p class="bo-page__label">' . self::e($label) . '</p>'
            . '<h1 class="bo-page__title">' . self::e($title) . '</h1>'
            . ('' === $sub ? '' : '<p class="bo-page__sub">' . self::e($sub) . '</p>')
            . '</header>'
            . $body
            . '</section>';
    }

    /**
     * The card grid. Only the fields the kernel hands over are emitted; no
     * invented author, date or cover.
     *
     * @param array<array-key, mixed> $articles
     */
    private static function cards(array $articles, string $locale): string
    {
        $pairs = self::pairs($articles);

        if ([] === $pairs) {
            return '<p class="bo-empty">Nothing published yet.</p>';
        }

        $prefix = self::urlPrefix($locale);
        $cards = '';

        foreach ($pairs as $index => $pair) {
            $meta = implode(' &middot; ', array_map(
                static fn (string $bit): string => self::e($bit),
                array_filter([$pair['date'], $pair['category'], $pair['author']], static fn (string $bit): bool => '' !== $bit),
            ));

            // The first grid row holds the LCP candidate, so those covers load
            // eager and ask for priority; the rest stay lazy below the fold.
            // Measured 2026-10-18 on responsive.sk: Boson's card marked EVERY
            // cover `loading="lazy"`, including the one Lighthouse then named
            // the LCP element, and carried no width/height, so the box was
            // unreserved and no srcset reached the browser. The starter theme
            // has answered all three since Boot 24; this is the same rule, on
            // the grid that has 3 columns of the same minimum width (320px).
            // The 3 is a literal on purpose: `NameOwnershipContractTest` caps
            // the tree's const declarations, and a constant that only names a
            // count the CSS already states is the growth that cap forbids.
            $inFirstRow = $index < 3;
            $loading = $inFirstRow ? 'eager' : 'lazy';
            $priority = $inFirstRow ? ' fetchpriority="high"' : '';

            $cards .= '<article class="bo-card">'
                . (null === $pair['image']
                    ? ''
                    : '<img class="bo-card__cover" src="' . self::e($pair['image']) . '" alt=""'
                        . ' loading="' . $loading . '"' . $priority . ' decoding="async"'
                        . ' width="720" height="360"' . ($pair['imageSrcset'] ?? '') . '>')
                . ('' === $meta ? '' : '<p class="bo-card__meta">' . $meta . '</p>')
                . '<h2 class="bo-card__title"><a href="' . $prefix . '/blog/' . self::e($pair['slug']) . '">'
                . self::e($pair['title']) . '</a></h2>'
                . ('' === $pair['excerpt'] ? '' : '<p class="bo-card__text">' . self::e($pair['excerpt']) . '</p>')
                . '<a class="bo-card__more" href="' . $prefix . '/blog/' . self::e($pair['slug']) . '">Read article &rarr;</a>'
                . '</article>';
        }

        return '<div class="bo-grid">' . $cards . '</div>';
    }

    /**
     * @param array<array-key, mixed> $articles
     * @return list<array{slug: string, title: string, image: ?string, date: string, category: string, author: string, excerpt: string}>
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

            $text = static function (string $key) use ($fields): string {
                $value = $fields[$key] ?? '';

                return is_string($value) ? $value : '';
            };

            $image = $fields['image'] ?? $fields['cover'] ?? $fields['coverImage'] ?? null;

            $pairs[] = [
                'slug' => $slug,
                'title' => $title,
                'image' => is_string($image) && '' !== $image ? $image : null,
                'date' => $text('date'),
                'category' => $text('category'),
                'author' => $text('author'),
                'excerpt' => $text('excerpt'),
            ];
        }

        return $pairs;
    }

    /**
     * The reference's pager shape: chevrons around the numbered pages.
     */
    private static function pager(PageContext $context, ?int $totalPages = null, ?int $page = null): string
    {
        $total = $totalPages ?? $context->totalPages;
        $current = $page ?? $context->page;

        if ($total < 2) {
            return '';
        }

        $base = self::blogBaseFor($context->locale);
        $query = static fn (int $index): string => self::pageUrl($base, $index, $context->query);
        $html = '<nav class="bo-pager" aria-label="Pages">';

        if ($current > 1) {
            $html .= '<a class="bo-pager__link" href="' . $query($current - 1) . '" rel="prev">&larr; Newer</a>';
        }

        for ($i = 1; $i <= $total; $i++) {
            $isCurrent = $i === $current;
            $html .= '<a class="bo-pager__page' . ($isCurrent ? ' bo-pager__page--current' : '') . '"'
                . ($isCurrent ? ' aria-current="page"' : '')
                . ' href="' . $query($i) . '">' . $i . '</a>';
        }

        if ($current < $total) {
            $html .= '<a class="bo-pager__link" href="' . $query($current + 1) . '" rel="next">Older &rarr;</a>';
        }

        return $html . '</nav>';
    }

    private static function pageUrl(string $base, int $page, string $query): string
    {
        $params = [];

        if ('' !== $query) {
            $params[] = 'q=' . rawurlencode($query);
        }

        if ($page > 1) {
            $params[] = 'page=' . $page;
        }

        return $base . ([] === $params ? '' : '?' . implode('&', $params));
    }

    /**
     * @param list<string>       $terms
     * @param array<string, int> $counts
     */
    private static function cloud(array $terms, array $counts, string $kind, ?string $active, string $locale): string
    {
        if ([] === $terms) {
            return '';
        }

        $chips = '';
        $base = self::urlPrefix($locale);

        foreach ($terms as $term) {
            $count = $counts[$term] ?? null;
            $label = $term . (null === $count ? '' : ' (' . $count . ')');

            $chips .= '<a class="bo-chip' . ($term === $active ? ' bo-chip--active' : '') . '"'
                . ' href="' . $base . '/blog/' . self::e($kind) . '/' . rawurlencode($term) . '">'
                . self::e($label) . '</a>';
        }

        return '<div class="bo-cloud">' . $chips . '</div>';
    }

    /**
     * The article's contents rail, from `MarkdownRenderer::extractHeadings()`.
     * `open` is load-bearing: a closed disclosure cannot be forced open from
     * CSS, and its summary is hidden at wide sizes.
     *
     * Level-2 headings become groups and level-3 headings their sublist, so a
     * long article reads as a few sections instead of one flat run. A heading
     * of the `Časť I — Title` shape is split into a quiet kicker and the title:
     * repeating the same prefix on six lines is noise, not navigation.
     *
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    private static function toc(array $headings): string
    {
        /** @var list<array{heading: array{anchor: string, text: string, level?: int}, subs: list<array{anchor: string, text: string, level?: int}>}> $groups */
        $groups = [];

        foreach ($headings as $heading) {
            if (!is_array($heading)) {
                continue;
            }

            $anchor = $heading['anchor'] ?? '';
            $text = $heading['text'] ?? '';

            if (!is_string($anchor) || '' === $anchor || !is_string($text) || '' === trim($text)) {
                continue;
            }

            $heading['anchor'] = $anchor;
            $heading['text'] = trim($text);

            if (3 === ($heading['level'] ?? 0) && [] !== $groups) {
                $groups[count($groups) - 1]['subs'][] = $heading;

                continue;
            }

            $groups[] = ['heading' => $heading, 'subs' => []];
        }

        if ([] === $groups) {
            return '';
        }

        $items = '';

        foreach ($groups as $group) {
            $items .= '<li class="bo-toc__item bo-toc__item--group" data-toc-group>'
                . self::tocLink($group['heading'], true)
                . self::tocSublist($group['subs'])
                . '</li>';
        }

        return '<aside class="bo-toc" aria-label="On this page">'
            . '<details class="bo-toc__disclosure" open>'
            . '<summary class="bo-toc__title">On this page</summary>'
            . '<ul class="bo-toc__list">' . $items . '</ul>'
            . '</details>'
            . '</aside>';
    }

    /**
     * One rail link. Top-level links split a `Prefix — Title` heading into a
     * kicker and the title; sublinks keep their full text so the rail cannot
     * turn into a column of labels.
     *
     * @param array{anchor: string, text: string} $heading
     */
    private static function tocLink(array $heading, bool $split): string
    {
        $anchor = (string) $heading['anchor'];
        $text = (string) $heading['text'];
        $inner = '<span class="bo-toc__text">' . self::e($text) . '</span>';

        if ($split) {
            $parts = preg_split('/\s+[—–]\s+/u', $text, 2);

            if (is_array($parts) && 2 === count($parts)
                && '' !== trim($parts[0]) && '' !== trim($parts[1])
                && strlen(trim($parts[0])) <= 24) {
                $inner = '<span class="bo-toc__kicker">' . self::e(trim($parts[0])) . '</span>'
                    . '<span class="bo-toc__text">' . self::e(trim($parts[1])) . '</span>';
            }
        }

        $class = $split ? 'bo-toc__link' : 'bo-toc__link bo-toc__link--sub';

        return '<a class="' . $class . '" data-toc-link data-heading="' . self::e($anchor) . '"'
            . ' href="#' . self::e($anchor) . '">' . $inner . '</a>';
    }

    /**
     * @param list<array{anchor: string, text: string}> $headings
     */
    private static function tocSublist(array $headings): string
    {
        if ([] === $headings) {
            return '';
        }

        $items = '';

        foreach ($headings as $heading) {
            $items .= '<li class="bo-toc__item bo-toc__item--sub">' . self::tocLink($heading, false) . '</li>';
        }

        return '<ul class="bo-toc__sublist">' . $items . '</ul>';
    }

    /**
     * @param list<string> $items
     */
    private static function bullets(array $items): string
    {
        $html = '';

        foreach ($items as $item) {
            $html .= '<li class="bo-bullets__item">' . self::e($item) . '</li>';
        }

        return '<ul class="bo-bullets">' . $html . '</ul>';
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
            $links .= $this->navItem($item);
        }

        return '<nav class="bo-nav" aria-label="Primary">' . $links . '</nav>' . "\n";
    }

    private function navItem(ShellNavItem $item): string
    {
        $link = '<a class="bo-nav__link' . ($item->active ? ' bo-nav__link--active' : '') . '"'
            . ' href="' . self::e($item->href) . '"'
            . ($item->active ? ' aria-current="page"' : '')
            . ($item->external ? ' rel="noopener noreferrer"' : '')
            . '>' . self::e($item->label) . '</a>';

        if ([] === $item->children) {
            return '<span class="bo-nav__item">' . $link . '</span>';
        }

        $children = '';

        foreach ($item->children as $child) {
            $children .= $this->navItem($child);
        }

        return '<span class="bo-nav__item bo-nav__item--group">' . $link
            . '<span class="bo-nav__sub">' . $children . '</span>'
            . '</span>';
    }

    /**
     * The phone panel: a flat list of every nav item, the search field and the
     * admin link. Rendered only beside a menu; the checkbox shows it.
     */
    private function mobileMenu(ShellContext $context): string
    {
        if ([] === $context->headerNav) {
            return '';
        }

        $links = '';

        foreach ($context->headerNav as $item) {
            $links .= '<a class="bo-mobile__link" href="' . self::e($item->href) . '"'
                . ($item->active ? ' aria-current="page"' : '')
                . ($item->external ? ' rel="noopener noreferrer"' : '') . '>'
                . self::e($item->label) . '</a>';

            foreach ($item->children as $child) {
                $links .= '<a class="bo-mobile__link bo-mobile__link--sub" href="' . self::e($child->href) . '"'
                    . ($child->external ? ' rel="noopener noreferrer"' : '') . '>'
                    . self::e($child->label) . '</a>';
            }
        }

        $blog = $this->blogBaseFor($context->activeLocale);

        return '<div class="bo-mobile" id="bo-mobile-menu">'
            . '<nav class="bo-mobile__nav" aria-label="Mobile">' . $links . '</nav>'
            . '<form class="bo-search-form bo-mobile__search" method="get" action="' . self::e($blog) . '" role="search">'
            . '<label class="bo-sr-only" for="bo-mobile-search">Search articles</label>'
            . '<input class="bo-search-form__input" id="bo-mobile-search" type="search" name="q"'
            . ' placeholder="Search" autocomplete="off">'
            . '<button class="bo-search-form__submit" type="submit">Search</button>'
            . '</form>'
            . '<a class="bo-mobile__admin" href="' . self::e(AdminRoutePolicy::prefix()) . '">Admin</a>'
            . '<div class="bo-mobile__locale">'
            . $this->localeLinks($context, 'bo-mobile__link bo-mobile__link--locale')
            . '</div>'
            . '</div>';
    }

    private static function burgerInput(bool $hasNav): string
    {
        if (!$hasNav) {
            return '';
        }

        return '<input type="checkbox" id="bo-nav-toggle" class="bo-burger-input"'
            . ' aria-label="Open navigation" aria-controls="bo-mobile-menu">' . "\n";
    }

    /**
     * The toggle label. `$icon` is `burger` or `meatball` — already resolved by
     * `navIcon()`, never the raw stored value.
     *
     * The close glyph is shared: an open menu is an X in both variants, so the
     * choice only changes the closed-state glyph. That is why the modifier sits
     * on the label and the CSS targets `__open` inside it.
     */
    private static function burgerLabel(bool $hasNav, string $icon): string
    {
        if (!$hasNav) {
            return '';
        }

        $variant = 'meatball' === $icon ? ' bo-burger--meatball' : '';

        return '<label class="bo-burger' . $variant . '" for="bo-nav-toggle" title="Menu">'
            . '<span class="bo-burger__open" aria-hidden="true"></span>'
            . '<span class="bo-burger__close" aria-hidden="true"></span>'
            . '</label>' . "\n";
    }

    private function localeLinks(ShellContext $context, string $class = 'bo-locale__link'): string
    {
        if (count($context->availableLocales) < 2) {
            return '';
        }

        $links = '';

        foreach ($context->availableLocales as $language) {
            $href = $context->localeLinks[$language]
                ?? ContentServices::mapper()->urlPrefix($language) . $context->currentPath;

            $links .= '<a class="' . $class . ($language === $context->activeLocale ? ' bo-locale__link--active' : '') . '"'
                . ' href="' . self::e($href) . '"'
                . ($language === $context->activeLocale ? ' aria-current="true"' : '')
                . ' hreflang="' . self::e(substr($language, 0, 2)) . '">'
                . self::e(substr($language, 0, 2)) . '</a>';
        }

        return '<span class="bo-locale">' . $links . '</span>' . "\n";
    }

    private function blogBase(string $locale): string
    {
        return self::blogBaseFor($locale);
    }

    private static function blogBaseFor(string $locale): string
    {
        return ContentServices::mapper()->urlPrefix($locale) . '/blog';
    }

    private static function urlPrefix(string $locale): string
    {
        return ContentServices::mapper()->urlPrefix($locale);
    }

    private static function t(PageContext $context, string $key): string
    {
        $value = $context->copy[$key] ?? null;

        if (is_string($value) && '' !== trim($value)) {
            return $value;
        }

        return self::DEFAULT_COPY[$key] ?? '';
    }

    /**
     * The brand mark: the site's own icon file, the same artwork the head
     * declares as `<link rel="icon">` and `apple-touch-icon`. One logo, one
     * place — a deployment that rebrands replaces `public/favicon.svg` and
     * the tab, the touch icon and the header all change together.
     */
    private static function logo(): string
    {
        return '/favicon.svg';
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
