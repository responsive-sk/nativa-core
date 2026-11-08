<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogArticleHelper;
use Modules\Blog\Application\Services\BlogArticlesService;
use Modules\Blog\Application\Services\BlogSearchService;
use Modules\Core\Application\Services\StructuredDataService;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use Modules\Locales\Interfaces\HTTP\LocalesServices;

/** @see BlogIndexTest */
#[Route(path: '/blog', method: 'GET', name: 'blog.index')]
final class BlogIndexAction
{
    /** Articles per page on the paginated index (matches search per-page). */
    private const int PER_PAGE = 9;

    public function handle(Request $request): Response
    {
        $service = BlogServices::articles();
        $query = $this->query($request);
        $page = $this->page($request);
        $locale = BlogArticlesService::requestLocale($request);
        $theme = (new ThemeResolver())->resolve($request);

        LocalesServices::locale()->set($locale);

        // One ThemeKernel call per surface — page composition lives in the
        // theme module (theme-as-module); the action only fetches data.
        $module = ThemeKernel::for($theme);
        if ('' !== $query) {
            $search = new BlogSearchService($service, AppPaths::instance());
            $result = $search->search($query, $locale, $page);
            $results = $service->bySlugs($result->slugs, $locale);
            $content = $module->blogSearch(
                PageContext::forBlogSearch($request, $theme, $locale, $query, $results),
            );
        } else {
            $articles = $service->all($locale);
            $content = $module->blogIndex(
                PageContext::forBlogIndex($request, $theme, $locale, $articles, page: $page, perPage: self::PER_PAGE),
            );
        }

        // The index announces the blog; a result list does not, for the same
        // reason it carries no canonical: one corpus answers both /blog?q=x and
        // /en/blog?q=x.
        $canonical = '' === $query ? $request->absoluteUrl($this->indexPath($locale)) : null;

        $hreflang = null;
        if (null !== $canonical) {
            $baseUrl = $request->absoluteUrl('');
            $hreflang = [];

            foreach (['sk', 'en'] as $code) {
                $hreflang[$code] = $baseUrl . BlogArticleHelper::blogPath($code);
            }

            $hreflang['x-default'] = $baseUrl . BlogArticleHelper::blogPath('sk');
        }

        return Response::html(FrontendLayout::render(
            '' !== $query ? 'Search ... ' . BlogServices::brandSuffix() : 'Blog ... ' . BlogServices::brandSuffix(),
            $content,
            currentPath: $request->getPathInfo(),
            canonicalUrl: $canonical,
            hreflangLinks: $hreflang,
            structuredData: null !== $canonical
                ? StructuredDataService::collection(
                    BlogServices::label(),
                    $canonical,
                    $request->absoluteUrl('/'),
                )
                : null,
            feedLinks: BlogArticleHelper::feedLinks($locale),
            theme: $theme,
        ));
    }

    private function query(Request $request): string
    {
        $raw = $request->query('q');

        return is_string($raw) ? trim($raw) : '';
    }

    private function page(Request $request): int
    {
        $raw = $request->query('page');
        $page = is_string($raw) ? (int) $raw : 1;

        return max(1, $page);
    }

    private function indexPath(string $locale): string
    {
        return BlogArticleHelper::blogPath($locale);
    }
}
