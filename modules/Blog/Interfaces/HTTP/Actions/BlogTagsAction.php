<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogArticleHelper;
use Modules\Blog\Application\Services\BlogArticlesService;
use Modules\Blog\Domain\Entities\Article;
use Modules\Markdown\Application\Services\MarkdownRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Themes\FrontendLayout;
use Shell\PageContext;
use Themes\ThemeKernel;
use Themes\ThemeResolver;
use Modules\Locales\Interfaces\HTTP\LocalesServices;

/**
 * Blog tags — tag cloud index (/blog/tags) and tag-filtered article list
 * (/blog/tags/{tag}).
 *
 * Tag URLs use the MarkdownRenderer slug convention (diacritics folded), so
 * Slovak tags like "PHP typy" become /blog/tags/php-typy. Both pages are
 * search-like surfaces: noindex + no canonical (same treatment as /blog?q=).
 * Theme-aware: moonshine renders the cloud/grid + sidebar, starter a simple
 * chip cloud + card grid.
 */
#[Route(path: '/blog/tags', method: 'GET', name: 'blog.tags', handlerMethod: 'index')]
#[Route(path: '/blog/tags/{tag}', method: 'GET', name: 'blog.tags.show', handlerMethod: 'show')]
final class BlogTagsAction
{
    /** Filtered articles per page — covers are PHP-served, so an unpaginated list walks into the rate limit. */
    private const int PER_PAGE = 9;

    public function index(Request $request): Response
    {
        $locale = $this->syncLocale($request);
        $theme = (new ThemeResolver())->resolve($request);
        $articles = $this->articles($locale);

        [$tags, $counts] = $this->tagIndex($articles);

        // One ThemeKernel call per surface — composition lives in the theme
        // module (theme-as-module).
        $content = ThemeKernel::for($theme)->blogTags(
            PageContext::forBlogTags($request, $theme, $locale, $tags, $counts),
        );

        return Response::html(FrontendLayout::render(
            'Tags ... ' . BlogServices::brandSuffix(),
            $content,
            currentPath: $request->getPathInfo(),
            noindex: true,
            feedLinks: BlogArticleHelper::feedLinks($locale),
            theme: $theme,
        ));
    }

    public function show(Request $request): Response
    {
        $tag = (string) ($request->routeParam('tag') ?? '');
        $locale = $this->syncLocale($request);
        $theme = (new ThemeResolver())->resolve($request);

        $articles = $this->filtered($tag, $locale);

        if ([] === $articles) {
            return $this->notFound($request->getPathInfo(), $theme);
        }

        [$page, $totalPages, $pageArticles] = BlogArticleHelper::paginate(
            $articles,
            BlogArticleHelper::requestedPage($request),
            self::PER_PAGE,
        );

        $content = ThemeKernel::for($theme)->blogTags(
            PageContext::forBlogTags(
                $request,
                $theme,
                $locale,
                [],
                [],
                $tag,
                $pageArticles,
                $page,
                self::PER_PAGE,
                \count($articles),
                $totalPages,
            ),
        );

        return Response::html(FrontendLayout::render(
            'Tag: ' . $tag . ' ... ' . BlogServices::brandSuffix(),
            $content,
            currentPath: $request->getPathInfo(),
            noindex: true,
            feedLinks: BlogArticleHelper::feedLinks($locale),
            theme: $theme,
        ));
    }

    /**
     * Articles carrying the given tag slug, newest first.
     *
     * @return list<Article>
     */
    private function filtered(string $tag, string $locale): array
    {
        $slug = strtolower($tag);
        $articles = [];

        foreach ($this->articles($locale) as $article) {
            foreach ($article->tags as $articleTag) {
                if ($slug === MarkdownRenderer::slugify($articleTag)) {
                    $articles[] = $article;

                    break;
                }
            }
        }

        return $articles;
    }

    /**
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
    private function tagIndex(array $articles): array
    {
        $counts = [];

        foreach ($articles as $article) {
            foreach ($article->tags as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        $tags = array_keys($counts);
        sort($tags, SORT_STRING | SORT_FLAG_CASE);

        return [$tags, $counts];
    }

    private function notFound(string $currentPath, string $theme): Response
    {
        $content = '<section class="st-hero">'
            . '<h1 class="st-hero__title">Tag not found</h1>'
            . '<p class="st-hero__subtitle">No articles carry this tag.</p>'
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
