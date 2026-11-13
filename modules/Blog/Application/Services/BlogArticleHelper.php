<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Blog\Domain\Entities\Article;
use Modules\Blog\Domain\Entities\ArticleAuthor;
use Modules\Core\Infrastructure\Http\Request;
use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Themes\FrontendLayout;

/**
 * BlogArticleHelper — shared data resolution for article pages.
 *
 * Extracted from BlogArticleAction to keep the action thin and to share
 * logic between themes. Each method is a pure data transformation — no
 * rendering, no request handling, no side effects.
 */
final readonly class BlogArticleHelper
{
    /**
     * Normalize front-matter tags into list<string>.
     *
     * @param array<string, mixed> $meta
     *
     * @return list<string>
     */
    public static function tagList(array $meta): array
    {
        return is_array($meta['tags'] ?? null)
            ? array_values(array_filter(array_map(
                static fn (mixed $tag): string => is_scalar($tag) ? (string) $tag : '',
                $meta['tags'],
            ), static fn (string $tag): bool => '' !== $tag))
            : [];
    }

    /**
     * Render tag chips footer — starter theme contract.
     *
     * @param array<string, mixed> $meta
     */
    public static function tagChips(array $meta): string
    {
        $tags = self::tagList($meta);

        if ([] === $tags) {
            return '';
        }

        $chips = [];
        foreach ($tags as $tag) {
            $href = '/blog/tags/' . urlencode(strtolower($tag));
            $chips[] = '<a class="st-article__tag" href="' . FrontendLayout::e($href) . '">' . FrontendLayout::e($tag) . '</a>';
        }

        return '<footer class="st-article__tags">' . implode('', $chips) . '</footer>';
    }

    /**
     * Prev/next neighbours of an article in the sorted (newest-first) list.
     *
     * @param list<Article> $articles
     *
     * @return array{Article|null, Article|null}
     */
    public static function prevNext(string $slug, array $articles): array
    {
        $index = -1;

        foreach ($articles as $i => $article) {
            if ($article->slug === $slug) {
                $index = $i;

                break;
            }
        }

        if (-1 === $index) {
            return [null, null];
        }

        $prev = $index > 0 ? $articles[$index - 1] : null;
        $next = $index < count($articles) - 1 ? $articles[$index + 1] : null;

        return [$prev, $next];
    }

    /**
     * Related articles — share at least one tag with the current article
     * (excluding itself), max 3.
     *
     * @param list<string>  $tags
     * @param list<Article> $articles
     *
     * @return list<Article>
     */
    public static function related(string $slug, array $tags, array $articles): array
    {
        $related = [];

        foreach ($articles as $article) {
            if ($article->slug === $slug) {
                continue;
            }
            if ([] === $tags || [] === array_intersect($tags, $article->tags)) {
                continue;
            }
            $related[] = $article;

            if (count($related) >= 3) {
                break;
            }
        }

        return $related;
    }

    /**
     * Resolve author profile from front-matter meta, returning an
     * ArticleAuthor VO or null when no email is set or the user doesn't exist.
     *
     * @param array<string, mixed> $meta
     */
    public static function resolveProfile(array $meta, BlogAuthorResolver $resolver): ?ArticleAuthor
    {
        $email = is_string($meta['author_email'] ?? null) ? $meta['author_email'] : '';
        $profileArr = '' !== $email ? $resolver->profile($email) : null;

        return null !== $profileArr ? ArticleAuthor::fromArray($profileArr) : null;
    }

    /**
     * Author line HTML: linked avatar + name when author_email resolves to an
     * active user, else the escaped plain author string.
     *
     * @param array<string, mixed> $meta
     */
    public static function authorHtml(array $meta, BlogAuthorResolver $resolver, AuthorAvatar $avatar): string
    {
        $fallback = is_string($meta['author'] ?? null) ? $meta['author'] : '';
        $email = is_string($meta['author_email'] ?? null) ? $meta['author_email'] : '';

        if ('' === $email) {
            return FrontendLayout::e($fallback);
        }

        return $avatar->authorLine($resolver->profile($email), $fallback);
    }

    /**
     * Date/category/author meta line, non-empty values only.
     *
     * @param array<string, mixed> $meta
     */
    public static function metaLine(array $meta, string $authorHtml): string
    {
        $bits = array_values(array_filter([
            is_string($meta['date'] ?? null) ? FrontendLayout::e($meta['date']) : '',
            is_string($meta['category'] ?? null) ? FrontendLayout::e($meta['category']) : '',
            $authorHtml,
        ], static fn (string $bit): bool => '' !== $bit));

        return implode(' · ', $bits);
    }

    /**
     * Cover image HTML — fixed 720×360 aspect to prevent layout shift.
     *
     * @param array<string, mixed> $meta
     */
    public static function coverImage(string $slug, array $meta, BlogArticlesService $articles, string $locale = 'sk'): string
    {
        $image = $articles->resolveCover($slug, $meta, $locale);

        return '<img class="st-article__cover" src="' . FrontendLayout::e($image)
            . '" alt="' . FrontendLayout::e($meta['title'] ?? $slug) . '" width="720" height="360"'
            . ' loading="eager" fetchpriority="high" decoding="async">';
    }

    /**
     * Author profile link HTML for every wired theme — linked name
     * pointing to /authors/{slug} when a profile exists.
     */
    public static function authorLink(?ArticleAuthor $profile, string $authorName): string
    {
        if (null === $profile) {
            return FrontendLayout::e($authorName);
        }

        return '<a href="/authors/' . FrontendLayout::e($profile->slug) . '">'
            . FrontendLayout::e($authorName) . '</a>';
    }

    /**
     * Normalize a request attribute into list<string>.
     *
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * Normalize a request attribute into array<string, string>.
     *
     * @return array<string, string>
     */
    public static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }

    /**
     * RSS/Atom feed auto-discovery links for <head>.
     *
     * @return array{atom: string, rss: string}
     */
    public static function feedLinks(string $locale): array
    {
        $path = self::blogPath($locale);

        return [
            'atom' => $path . '/feed.atom',
            'rss' => $path . '/feed.xml',
        ];
    }

    /**
     * The blog index path for a locale (`/blog`, `/en/blog`).
     *
     * One owner for a path three surfaces now need: the index itself, the feed
     * links beside it, and the category trail whose parent entry is this URL.
     */
    public static function blogPath(string $locale): string
    {
        return ContentLocaleServices::mapper()->urlPrefix($locale) . '/blog';
    }

    /**
     * Get view counts for a list of articles.
     *
     * @param list<Article> $articles
     *
     * @return array<string, int> slug → count
     */
    public static function viewCounts(array $articles): array
    {
        $slugs = array_map(static fn (Article $a): string => $a->slug, $articles);

        return ViewCounter::instance()->counts($slugs);
    }

    /**
     * Slice an article list down to the requested page.
     *
     * Every listing surface that renders article cards goes through here so
     * the page size and the out-of-range clamp can never drift apart per
     * action. The requested page is clamped to the last page, so `?page=999`
     * renders the final page instead of an empty grid.
     *
     * @param list<Article> $articles
     *
     * @return array{int, int, list<Article>} [page, totalPages, pageArticles]
     */
    public static function paginate(array $articles, int $page, int $perPage): array
    {
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int) ceil(\count($articles) / $perPage));
        $page = min(max(1, $page), $totalPages);

        return [
            $page,
            $totalPages,
            \array_slice($articles, ($page - 1) * $perPage, $perPage),
        ];
    }

    /**
     * Read the `page` query parameter as a positive integer.
     */
    public static function requestedPage(Request $request): int
    {
        $raw = $request->query('page');
        $page = \is_string($raw) ? (int) $raw : 1;

        return max(1, $page);
    }
}
