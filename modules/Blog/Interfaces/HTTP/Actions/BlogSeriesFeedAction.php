<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogArticlesService;
use Modules\Blog\Domain\Entities\Article;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;

/**
 * Blog series feed — RSS/Atom for a specific article series.
 *
 * Filters articles by the "security-series" tag and generates a
 * dedicated feed for subscribers who only want the series content.
 *
 * Routes:
 *   /blog/series/security-infra/feed.atom  (Atom 1.0)
 *   /blog/series/security-infra/feed.xml   (RSS 2.0)
 */
#[Route(path: '/blog/series/security-infra/feed.atom', method: 'GET', name: 'blog.series.security-infra.atom')]
#[Route(path: '/blog/series/security-infra/feed.xml', method: 'GET', name: 'blog.series.security-infra.rss', handlerMethod: 'rss')]
final class BlogSeriesFeedAction
{
    private const string SERIES_TAG = 'security-series';

    public function handle(Request $request): Response
    {
        $feed = BlogServices::feed();

        return Response::xml(
            $feed->atom($this->seriesArticles($request), $this->baseUrl($request)),
            'application/atom+xml; charset=UTF-8',
        );
    }

    public function rss(Request $request): Response
    {
        $feed = BlogServices::feed();

        return Response::xml(
            $feed->rss($this->seriesArticles($request), $this->baseUrl($request)),
            'application/rss+xml; charset=UTF-8',
        );
    }

    /**
     * Articles tagged with the series tag, in the active locale.
     *
     * @return list<Article>
     */
    private function seriesArticles(Request $request): array
    {
        $locale = BlogArticlesService::requestLocale($request);
        $articles = BlogServices::articles()->all($locale);

        return array_values(array_filter(
            $articles,
            static fn (Article $article): bool => in_array(self::SERIES_TAG, $article->tags, true),
        ));
    }

    /**
     * Absolute base URL from the request.
     */
    private function baseUrl(Request $request): string
    {
        return $request->absoluteUrl('');
    }
}
