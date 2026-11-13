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
 * Blog feed action — RSS/Atom subscriptions for storage/blog articles.
 *
 * Both formats are generated from the shared BlogArticlesService so the
 * feeds always agree with the blog index and article pages. Feed links are
 * absolute (scheme + host from the request) so subscribers can resolve them.
 */
#[Route(path: '/blog/feed.atom', method: 'GET', name: 'blog.feed.atom')]
#[Route(path: '/blog/feed.xml', method: 'GET', name: 'blog.feed.rss', handlerMethod: 'rss')]
final class BlogFeedAction
{
    public function handle(Request $request): Response
    {
        $feed = BlogServices::feed();

        return Response::xml(
            $feed->atom($this->articles($request), $this->baseUrl($request)),
            'application/atom+xml; charset=UTF-8',
        );
    }

    public function rss(Request $request): Response
    {
        $feed = BlogServices::feed();

        return Response::xml(
            $feed->rss($this->articles($request), $this->baseUrl($request)),
            'application/rss+xml; charset=UTF-8',
        );
    }

    /**
     * All articles in the active locale via the shared discovery service.
     *
     * @return list<Article>
     */
    private function articles(Request $request): array
    {
        $locale = BlogArticlesService::requestLocale($request);
        $service = BlogServices::articles();

        return $service->all($locale);
    }

    /**
     * Absolute base URL (scheme + host) from the request — shared
     * Request::absoluteUrl() helper, loopback-safe fallback so the feed
     * works on the built-in dev server too.
     */
    private function baseUrl(Request $request): string
    {
        return $request->absoluteUrl('');
    }
}
