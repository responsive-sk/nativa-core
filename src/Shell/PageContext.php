<?php

declare(strict_types = 1);

namespace Shell;

use Modules\Core\Infrastructure\Http\Request;

/**
 * Per-surface payload for the theme module's page rendering methods.
 *
 * The action computes the data (articles, search result, view counts, …);
 * the theme module owns the markup. Fields not produced by a surface's
 * factory stay null — themes only read the fields their surface provides,
 * so PHPStan L9 sees through every read. Construction goes exclusively
 * through the typed named constructors below (not readonly: each factory
 * initializes a different subset at construction time).
 *
 * Article lists travel as plain objects (`slug`, `title`), read through
 * `get_object_vars` — the Blog entity is never imported: a kernel payload
 * naming the module that owns the article is the coupling the kernel exists
 * to avoid.
 */
final class PageContext
{
    /**
     * @param array<string, string>                                 $copy           translation key → resolved copy
     * @param array<string, string>                                 $links          feature-card key → article href
     * @param array<string, list<string>>                           $cards          section key → card keys
     * @param list<object>                                          $latestArticles pre-fetched articles for the theme's latest section
     * @param list<object>                                          $articles       full article list (pagination slice happens in the theme)
     * @param array<string, int>                                    $viewCounts     slug → view count
     * @param list<object>                                          $results        search results (ordered)
     * @param list<array{level: int, text: string, anchor: string}> $headings       article TOC headings
     * @param array<string, mixed>                                  $meta           article front matter
     * @param list<string>                                          $tags           tag cloud terms
     * @param list<string>                                          $categories     category cloud terms
     * @param array<string, int>                                    $counts         term → article count
     * @param array{id: string, name: string, avatar: ?string}|null $author         resolved author profile
     */
    private function __construct(
        public Request $request,
        public string $theme,
        public string $locale,
        public ?string $blogHref = null,
        public ?string $adminHref = null,
        public ?string $heroSrc = null,
        public ?string $heroSrcset = null,
        public array $copy = [],
        public array $links = [],
        public array $cards = [],
        public array $latestArticles = [],
        public array $articles = [],
        public array $viewCounts = [],
        public int $page = 1,
        public int $perPage = 9,
        public string $query = '',
        public array $results = [],
        public string $slug = '',
        public string $title = '',
        public string $bodyHtml = '',
        public string $metaLine = '',
        public string $tagChips = '',
        public string $coverImage = '',
        public string $coverUrl = '',
        public array $headings = [],
        public array $meta = [],
        public int $views = 0,
        public string $commentsHtml = '',
        public string $editUrl = '',
        public string $editLabel = 'Edit',
        public string $inlineEditHtml = '',
        public array $tags = [],
        public array $categories = [],
        public array $counts = [],
        public int $totalArticles = 0,
        public int $totalPages = 1,
        public ?string $tag = null,
        public ?string $category = null,
        public ?array $author = null,
        public string $memberSince = '',
    ) {
    }

    /**
     * Homepage payload: translated copy resolved by the action (the
     * translation system stays kernel-owned), feature-card article links
     * and the pre-fetched article lists per theme need.
     *
     * `$blogHref` and `$adminHref` are the hero's ADVERTISEMENTS, so they are
     * nullable on purpose: the layout passes the URL only when a route answers
     * it, and a theme renders no button for a null. A default of `/blog` would
     * have every theme teach a kernel-only tree a 404 — the same rule the menu
     * seed follows.
     *
     * @param array<string, string>       $copy           translation key → resolved copy
     * @param array<string, string>       $links          feature-card key → article href
     * @param array<string, list<string>> $cards          section key → card keys
     * @param list<object>                $latestArticles pre-fetched articles for the theme's latest section
     * @param string|null                 $blogHref       null when `/blog` is not routed
     * @param string|null                 $adminHref      null when the admin prefix is not routed
     */
    public static function forHomepage(
        Request $request,
        string $theme,
        string $locale,
        array $copy = [],
        array $links = [],
        array $cards = [],
        ?string $blogHref = null,
        ?string $adminHref = null,
        string $heroSrc = '',
        string $heroSrcset = '',
        array $latestArticles = [],
    ): self {
        return new self(
            request: $request,
            theme: $theme,
            locale: $locale,
            blogHref: $blogHref,
            adminHref: $adminHref,
            heroSrc: $heroSrc,
            heroSrcset: $heroSrcset,
            copy: $copy,
            links: $links,
            cards: $cards,
            latestArticles: $latestArticles,
        );
    }

    /**
     * @param list<object>       $articles   full article list (pagination slice happens in the theme)
     * @param array<string, int> $viewCounts slug → view count
     */
    public static function forBlogIndex(
        Request $request,
        string $theme,
        string $locale,
        array $articles,
        array $viewCounts = [],
        int $page = 1,
        int $perPage = 9,
    ): self {
        return new self(
            request: $request,
            theme: $theme,
            locale: $locale,
            articles: $articles,
            viewCounts: $viewCounts,
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * @param list<object> $results
     */
    public static function forBlogSearch(
        Request $request,
        string $theme,
        string $locale,
        string $query,
        array $results,
    ): self {
        return new self(
            request: $request,
            theme: $theme,
            locale: $locale,
            query: $query,
            results: $results,
        );
    }

    /**
     * @param list<array{level: int, text: string, anchor: string}> $headings
     * @param array<string, mixed>                                  $meta     article front matter
     */
    public static function forBlogArticle(
        Request $request,
        string $theme,
        string $locale,
        string $slug,
        string $title,
        string $bodyHtml,
        string $metaLine,
        string $tagChips,
        string $coverImage,
        string $coverUrl,
        array $headings,
        array $meta,
        int $views = 0,
        string $commentsHtml = '',
        string $editUrl = '',
        string $editLabel = 'Edit',
        string $inlineEditHtml = '',
    ): self {
        return new self(
            request: $request,
            theme: $theme,
            locale: $locale,
            slug: $slug,
            title: $title,
            bodyHtml: $bodyHtml,
            metaLine: $metaLine,
            tagChips: $tagChips,
            coverImage: $coverImage,
            coverUrl: $coverUrl,
            headings: $headings,
            meta: $meta,
            views: $views,
            commentsHtml: $commentsHtml,
            editUrl: $editUrl,
            editLabel: $editLabel,
            inlineEditHtml: $inlineEditHtml,
        );
    }

    /**
     * @param list<string>       $tags
     * @param array<string, int> $counts
     * @param list<object>       $articles
     */
    /**
     * @param list<string>       $tags     tag cloud terms (index page)
     * @param array<string, int> $counts   term → article count
     * @param string|null        $tag      active tag slug (filtered page)
     * @param list<object>       $articles filtered article list
     */
    public static function forBlogTags(
        Request $request,
        string $theme,
        string $locale,
        array $tags,
        array $counts,
        ?string $tag = null,
        array $articles = [],
        int $page = 1,
        int $perPage = 9,
        int $totalArticles = 0,
        int $totalPages = 1,
    ): self {
        return new self(
            request: $request,
            theme: $theme,
            locale: $locale,
            tags: $tags,
            counts: $counts,
            totalArticles: $totalArticles,
            totalPages: $totalPages,
            tag: $tag,
            articles: $articles,
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * @param list<string>       $categories
     * @param array<string, int> $counts
     * @param list<object>       $articles
     */
    /**
     * @param list<string>       $categories category cloud terms (index page)
     * @param array<string, int> $counts     term → article count
     * @param string|null        $category   active category slug (filtered page)
     * @param list<object>       $articles   filtered article list
     */
    public static function forBlogCategories(
        Request $request,
        string $theme,
        string $locale,
        array $categories,
        array $counts,
        ?string $category = null,
        array $articles = [],
        int $page = 1,
        int $perPage = 9,
        int $totalArticles = 0,
        int $totalPages = 1,
    ): self {
        return new self(
            request: $request,
            theme: $theme,
            locale: $locale,
            categories: $categories,
            counts: $counts,
            totalArticles: $totalArticles,
            totalPages: $totalPages,
            category: $category,
            articles: $articles,
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * @param array{id: string, name: string, avatar: ?string} $author   resolved author profile
     * @param list<object>                                     $articles
     */
    /**
     * @param array{id: string, name: string, avatar: ?string} $author   resolved profile
     * @param list<object>                                     $articles every article by this author
     */
    public static function forAuthorProfile(
        Request $request,
        string $theme,
        string $locale,
        array $author,
        array $articles,
        string $memberSince,
        int $page = 1,
        int $perPage = 9,
        int $totalArticles = 0,
        int $totalPages = 1,
    ): self {
        return new self(
            request: $request,
            theme: $theme,
            locale: $locale,
            author: $author,
            articles: $articles,
            memberSince: $memberSince,
            totalArticles: $totalArticles,
            totalPages: $totalPages,
            page: $page,
            perPage: $perPage,
        );
    }
}
