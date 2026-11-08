<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogArticleHelper;
use Modules\Blog\Application\Services\BlogArticlesService;
use Modules\Blog\Domain\Entities\Article;
use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Application\Services\StructuredDataService;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use Modules\Locales\Interfaces\HTTP\LocalesServices;

/**
 * Blog categories — category grid index (/blog/categories) and
 * category-filtered article list (/blog/categories/{category}).
 *
 * Category URLs use the MarkdownRenderer slug convention (diacritics
 * folded). Both pages are search-like surfaces: noindex + no canonical.
 * Theme-aware: moonshine renders the card grid + sidebar, starter a simple
 * heading + card grid.
 *
 * The filtered list (`show`) also announces itself as a `CollectionPage` with
 * a Blog > Category trail. It stays noindex, so a crawler ignores the block
 * today; keeping the markup truthful beside the page is what makes lifting
 * that noindex a one-line decision rather than a rewrite.
 */
#[Route(path: '/blog/categories', method: 'GET', name: 'blog.categories', handlerMethod: 'index')]
#[Route(path: '/blog/categories/{category}', method: 'GET', name: 'blog.categories.show', handlerMethod: 'show')]
final class BlogCategoriesAction
{
    /** Filtered articles per page — covers are PHP-served, so an unpaginated list walks into the rate limit. */
    private const int PER_PAGE = 9;

    public function index(Request $request): Response
    {
        $locale = $this->syncLocale($request);
        $theme = (new ThemeResolver())->resolve($request);
        $articles = $this->articles($locale);

        [$categories, $counts] = $this->categoryIndex($articles);

        // One ThemeKernel call per surface — composition lives in the theme
        // module (theme-as-module).
        $content = ThemeKernel::for($theme)->blogCategories(
            PageContext::forBlogCategories($request, $theme, $locale, $categories, $counts),
        );

        return Response::html(FrontendLayout::render(
            'Categories ... ' . BlogServices::brandSuffix(),
            $content,
            currentPath: $request->getPathInfo(),
            noindex: true,
            feedLinks: BlogArticleHelper::feedLinks($locale),
            theme: $theme,
        ));
    }

    public function show(Request $request): Response
    {
        $category = (string) ($request->routeParam('category') ?? '');
        $locale = $this->syncLocale($request);
        $theme = (new ThemeResolver())->resolve($request);

        $articles = [];
        foreach ($this->articles($locale) as $article) {
            if ('' !== $article->category && strtolower($category) === MarkdownRenderer::slugify($article->category)) {
                $articles[] = $article;
            }
        }

        if ([] === $articles) {
            return $this->notFound($request->getPathInfo(), $theme);
        }

        [$page, $totalPages, $pageArticles] = BlogArticleHelper::paginate(
            $articles,
            BlogArticleHelper::requestedPage($request),
            self::PER_PAGE,
        );

        $content = ThemeKernel::for($theme)->blogCategories(
            PageContext::forBlogCategories(
                $request,
                $theme,
                $locale,
                [],
                [],
                $category,
                $pageArticles,
                $page,
                self::PER_PAGE,
                \count($articles),
                $totalPages,
            ),
        );

        return Response::html(FrontendLayout::render(
            $category . ' ... ' . BlogServices::brandSuffix(),
            $content,
            currentPath: $request->getPathInfo(),
            noindex: true,
            structuredData: StructuredDataService::category(
                $category,
                $request->absoluteUrl($request->getPathInfo()),
                BlogServices::label(),
                $request->absoluteUrl(BlogArticleHelper::blogPath($locale)),
            ),
            feedLinks: BlogArticleHelper::feedLinks($locale),
            theme: $theme,
        ));
    }    /**
     * @return list<Article>
     */
    private function articles(string $locale): array
    {
        return BlogServices::articles()->all($locale);
    }

    /**
     * @param list<Article> $articles
     *
     * @return array{list<string>, array<string, int>}
     */
    private function categoryIndex(array $articles): array
    {
        $counts = [];

        foreach ($articles as $article) {
            if ('' !== $article->category) {
                $counts[$article->category] = ($counts[$article->category] ?? 0) + 1;
            }
        }

        $categories = array_keys($counts);
        sort($categories, SORT_STRING | SORT_FLAG_CASE);

        return [$categories, $counts];
    }

    private function notFound(string $currentPath, string $theme): Response
    {
        $content = '<section class="st-hero">'
            . '<h1 class="st-hero__title">Category not found</h1>'
            . '<p class="st-hero__subtitle">No articles belong to this category.</p>'
            . '</section>';

        return Response::html(
            FrontendLayout::render('Not Found ... ' . BlogServices::brandSuffix(), $content, currentPath: $currentPath, noindex: true),
            404,
        );
    }

    private function syncLocale(Request $request): string
    {
        $locale = BlogArticlesService::requestLocale($request);
        LocalesServices::locale()->set($locale);

        return $locale;
    }
}
