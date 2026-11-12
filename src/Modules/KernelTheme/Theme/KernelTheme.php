<?php

declare(strict_types = 1);

namespace Modules\KernelTheme\Theme;

use Shell\ShellNavItem;
use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Shell\PageContext;
use Shell\ShellContext;
use Modules\Layout\ThemeModule;

/**
 * KernelTheme — the trimmed design the kernel-only tree renders with.
 *
 * The kernel ships a theme of its own for the same reason it owns a router:
 * `FrontendLayout` asks a `ThemeModule` for the chrome and the page wrapper of
 * EVERY public surface, and a kernel whose layout can only render when a module
 * happens to be installed is not a kernel. This one is deliberately much
 * smaller than a real theme — it owns the shell and the kernel's own surfaces
 * (home, the SEO text surfaces, `/docs`, the error pages) and renders the blog
 * family plainly if a blog module is ever installed alongside it.
 *
 * Everything it needs for CONTENT arrives in `ShellContext` (which the kernel
 * fills through `ShellContextProvider`) and `PageContext`. A property read on
 * an object the kernel handed it is not an import — see `links()`.
 *
 * It does name two module symbols, and this is the only tracked file under
 * `src/` that imports a module a kernel-only export does not carry (measured
 * 2026-11-12; `tests/Architecture/KernelThemeBoundaryTest.php` pins the list):
 *
 *  - `Modules\Layout\ThemeModule` is the contract a theme implements. The
 *    module owns the theme surface (`FrontendLayout`, `ThemeKernel`, theme
 *    discovery), so the contract travels with the module that resolves it; the
 *    kernel theme is the fallback IMPLEMENTOR, not the reverse. Moving the
 *    contract into the kernel is the source repository's decision.
 *  - `ContentLocaleServices` is asked only when it is on disk, behind
 *    `class_exists` in `localeHref()` — a tree without locales derives the
 *    short prefix from the locale code, as this theme did before the mapping
 *    moved.
 *
 * Neither is a content dependency, and neither is silent: PHPStan and mago
 * scope exclude this slice because a kernel-only checkout cannot load what it
 * implements, so the boundary test reads it instead.
 *
 * No asset bundle ships with it and none is needed: the whole design is one
 * hand-written file (`assets/critical/kernel.css`, inlined by `InlineCss`),
 * plain CSS with no build step. A theme that needs Vite to render is a theme
 * the kernel cannot serve.
 */
final class KernelTheme implements ThemeModule
{
    public function name(): string
    {
        return 'kernel';
    }

    public function bundle(): string
    {
        return 'kernel';
    }

    public function jsKey(): string
    {
        return 'kernel';
    }

    /**
     * The whole design, one file, no build — see the file header.
     *
     * @return list<string>
     */
    public function criticalCss(): array
    {
        return ['Modules/KernelTheme/assets/critical/kernel.css'];
    }

    /**
     * Empty on purpose — `FrontendLayout` falls back to the shared pre-paint
     * bytes when a theme supplies none, and a copy here would be a second copy
     * to drift away from them.
     */
    public function themeInitScript(): string
    {
        return '';
    }

    public function prefix(): string
    {
        return 'k';
    }

    public function header(ShellContext $context): string
    {
        return $this->chrome('header', [
            '<a class="k-header__brand" href="/">' . self::e($context->siteName) . '</a>',
            $this->nav($context->headerNav),
            $this->localeSwitcher($context),
        ]);
    }

    public function footer(ShellContext $context): string
    {
        return $this->chrome('footer', [
            '<span class="k-footer__year">© ' . self::e($context->year) . ' ' . self::e($context->siteName) . '</span>',
            $this->nav($context->footerNav),
        ]);
    }

    public function pageWrap(string $content): string
    {
        return '<main class="k-main">' . "\n"
            . '    ' . $content . "\n"
            . '</main>' . "\n";
    }

    public function homepage(PageContext $context): string
    {
        return $this->pageWrap($this->section($context->copy['title'] ?? 'Home', ''));
    }

    public function blogIndex(PageContext $context): string
    {
        return $this->pageWrap($this->section('Blog', $this->links($context->articles)));
    }

    public function blogSearch(PageContext $context): string
    {
        return $this->pageWrap(
            $this->section('Search: ' . $context->query, $this->links($context->results)),
        );
    }

    public function blogArticle(PageContext $context): string
    {
        // `bodyHtml` is rendered by MarkdownRenderer and passed through, never
        // escaped — escaping markup the kernel itself produced would show the
        // reader the source instead of the article.
        return $this->pageWrap(
            '<article class="k-article">'
            . '<h1 class="k-section__title">' . self::e($context->title) . '</h1>'
            . '<div class="k-article__body">' . $context->bodyHtml . '</div>'
            . '</article>',
        );
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
        $title = $context->category ?? 'Categories';

        return $this->pageWrap($this->section($title, $this->links($context->articles)));
    }

    public function authorProfile(PageContext $context): string
    {
        return $this->pageWrap($this->section($this->authorName($context), $this->links($context->articles)));
    }

    public function errorContent(int $status, string $title, string $detail): string
    {
        return '<section class="k-error">'
            . '<p class="k-error__code">' . $status . '</p>'
            . '<h1 class="k-error__title">' . self::e($title) . '</h1>'
            . '<p class="k-error__detail">' . self::e($detail) . '</p>'
            . '<a class="k-error__link" href="/">Back to homepage</a>'
            . '</section>';
    }

    /**
     * A titled section. The body is optional because a surface can have a title
     * and nothing under it yet — an empty paragraph invented here would be a
     * lie about the content.
     */
    private function section(string $title, string $body): string
    {
        return '<section class="k-section">'
            . '<h1 class="k-section__title">' . self::e($title) . '</h1>'
            . $body
            . '</section>';
    }

    /**
     * Links to the articles a surface was handed.
     *
     * The shape (`slug`, `title`) is read through `get_object_vars` instead of
     * importing the entity: a kernel theme that `use`s the module owning the
     * article is the coupling the kernel exists to avoid. Reading the shape
     * rather than the type also means an item that does not carry both fields is
     * SKIPPED rather than fataling on a property that is not there, and only
     * public properties are readable from outside the entity's scope — which is
     * the second reason this works at all.
     *
     * An empty result is a fact about the content ("nothing published yet"),
     * not an error.
     *
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
            return '<p class="k-section__empty">Nothing published yet.</p>';
        }

        $items = '';

        foreach ($pairs as $pair) {
            $items .= '<li><a href="/blog/' . self::e($pair['slug']) . '">'
                . self::e($pair['title']) . '</a></li>';
        }

        return '<ul class="k-list">' . $items . '</ul>';
    }

    /** The author's display name, or a plain label when the surface had none. */
    private function authorName(PageContext $context): string
    {
        $author = $context->author ?? [];
        $name = $author['name'] ?? null;

        return is_string($name) && '' !== $name ? $name : 'Author';
    }

    /**
     * A chromed band with every part it was given, dropping the ones that are
     * empty — a header with no menu renders the brand alone rather than an
     * empty `<nav>`.
     *
     * @param list<string> $parts
     */
    private function chrome(string $tag, array $parts): string
    {
        $html = '<' . $tag . ' class="k-' . $tag . '">' . "\n";

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

        return '<nav class="k-nav">' . $links . '</nav>';
    }

    /** One nav entry; its children follow it flat (a module owns the depth). */
    private function link(ShellNavItem $item): string
    {
        $link = '<a class="k-nav__link" href="' . self::e($item->href) . '"'
            . ($item->active ? ' aria-current="page"' : '')
            . ($item->external ? ' rel="noopener noreferrer"' : '')
            . '>' . self::e($item->label) . '</a>';

        foreach ($item->children as $child) {
            $link .= $this->link($child);
        }

        return $link;
    }

    /**
     * Locale links, rendered only when the site has more than one language — a
     * switcher with a single entry is furniture.
     *
     * The default locale owns the BARE path, and only the module that owns
     * locales can say which language that is. The kernel therefore renders
     * prefixed links and lets that module override them through
     * `ShellContext::$localeLinks` (the explicit map wins, the same precedence
     * the shell has always had).
     */
    private function localeSwitcher(ShellContext $context): string
    {
        if (count($context->availableLocales) < 2) {
            return '';
        }

        $links = '';

        foreach ($context->availableLocales as $language) {
            $href = $context->localeLinks[$language] ?? self::localeHref($language, $context->currentPath);

            $links .= '<a class="k-locale__link" href="' . self::e($href) . '"'
                . ($language === $context->activeLocale ? ' aria-current="true"' : '')
                . '>' . self::e($language) . '</a>';
        }

        return '<div class="k-locale">' . $links . '</div>';
    }

    /**
     * The kernel theme ships with a tree that may not have ContentLocale
     * installed. Ask it when it is there; otherwise render the short prefix
     * from the locale code, as the kernel did before the mapping moved.
     */
    private static function localeHref(string $language, string $path): string
    {
        if (class_exists(ContentLocaleServices::class)) {
            return ContentLocaleServices::mapper()->urlPrefix($language) . $path;
        }

        return '/' . strtolower(substr($language, 0, 2)) . $path;
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
