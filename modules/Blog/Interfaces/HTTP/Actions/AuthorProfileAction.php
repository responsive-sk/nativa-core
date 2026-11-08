<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogArticleHelper;
use Modules\Blog\Application\Services\BlogArticlesService;
use Modules\Core\Application\Services\StructuredDataService;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use Modules\Locales\Interfaces\HTTP\LocalesServices;

/**
 * Author profile — public page at /authors/{slug}.
 *
 * Renders an active user's avatar, name, member-since date and the grid of
 * articles whose author_email matches. Unknown or inactive users and
 * malformed slugs return 404 (an article whose author fell back to plain
 * text never links here). The email is a lookup key only — it is never
 * rendered. The slug is the diacritics-folded display name (nativa-dev).
 */
#[Route(path: '/authors/{slug}', method: 'GET', name: 'authors.show')]
final class AuthorProfileAction
{
    /** Articles per page on the profile grid — covers are PHP-served. */
    private const int PER_PAGE = 9;

    public function handle(Request $request): Response
    {
        $slug = (string) ($request->routeParam('slug') ?? '');

        // Author slugs are lowercase-alphanumeric with dashes, same grammar
        // as tag/category slugs — anything else is not a resolvable author.
        if (1 !== preg_match('/^[a-z0-9-]+$/', $slug)) {
            return $this->notFound();
        }

        $resolver = BlogServices::authorResolver();
        $profile = $resolver->profileBySlug($slug);

        if (null === $profile) {
            return $this->notFound();
        }

        // The article grid follows the blog content locale (sk_SK by default,
        // even when settings.locale is en_US) — sync the active locale so the
        // layout's <html lang> and the locale switcher match the served
        // articles instead of the site default locale.
        $locale = BlogArticlesService::requestLocale($request);
        LocalesServices::locale()->set($locale);

        $theme = (new ThemeResolver())->resolve($request);

        // One ThemeKernel call per surface — composition lives in the theme
        // module (theme-as-module).
        $content = ThemeKernel::for($theme)->authorProfile(
            $this->pageContext($request, $profile, $locale, $theme),
        );

        return $this->respond($request, $slug, $profile, $content, $locale, $theme);
    }

    /**
     * Author page payload for the theme module — grid keyed by resolved
     * identity (id), matching how the page is addressed: an article links
     * here exactly when it is listed here.
     *
     * @param array{id: string, name: string, avatar: ?string, memberSince: string} $profile
     */
    private function pageContext(
        Request $request,
        array $profile,
        string $locale,
        string $theme,
    ): PageContext {
        $articles = BlogServices::articles()->byAuthorProfileId($profile['id'], $locale);

        // Covers are PHP-served, so an unpaginated author page (the demo
        // author carries ~90 articles) walks into the per-IP rate limit.
        [$page, $totalPages, $pageArticles] = BlogArticleHelper::paginate(
            $articles,
            BlogArticleHelper::requestedPage($request),
            self::PER_PAGE,
        );

        return PageContext::forAuthorProfile(
            $request,
            $theme,
            $locale,
            ['id' => $profile['id'], 'name' => $profile['name'], 'avatar' => BlogServices::avatar()->url($profile['avatar'])],
            $pageArticles,
            $this->memberSince($profile['memberSince']),
            $page,
            self::PER_PAGE,
            \count($articles),
            $totalPages,
        );
    }

    /**
     * Rendered profile page — SEO head meta via the shared layout.
     *
     * @param array{id: string, name: string, avatar: ?string, memberSince: string} $profile
     */
    private function respond(
        Request $request,
        string $slug,
        array $profile,
        string $content,
        string $locale,
        string $theme,
    ): Response {
        $profileUrl = $request->absoluteUrl($this->profilePath($locale, $slug));

        return Response::html(FrontendLayout::render(
            $profile['name'] . ' ... ' . BlogServices::brandSuffix(),
            $content,
            currentPath: $request->getPathInfo(),
            canonicalUrl: $profileUrl,
            // An author profile exists in both locales (the article grid is
            // locale-filtered, the profile itself is not). Without alternates
            // the canonical decides which one search engines keep — and with a
            // single SK canonical the English profile is de-indexed as a
            // duplicate of the Slovak one.
            hreflangLinks: [
                'sk' => $request->absoluteUrl('/authors/' . $slug),
                'en' => $request->absoluteUrl('/en/authors/' . $slug),
                'x-default' => $request->absoluteUrl('/authors/' . $slug),
            ],
            ogType: 'profile',
            structuredData: StructuredDataService::person($profile['name'], $profileUrl),
            feedLinks: BlogArticleHelper::feedLinks($locale),
            theme: $theme,
        ));
    }

    /**
     * The default locale serves the unprefixed path (same scheme the blog
     * index, articles and the locale switcher use).
     */
    private function profilePath(string $locale, string $slug): string
    {
        return ContentServices::mapper()->urlPrefix($locale) . '/authors/' . $slug;
    }

    private function memberSince(string $createdAt): string
    {
        $ts = strtotime($createdAt);

        return 'Member since ' . (false === $ts ? $createdAt : date('Y-m-d', $ts));
    }

    private function notFound(): Response
    {
        $content = '<section class="st-hero">'
            . '<h1 class="st-hero__title">Author not found</h1>'
            . '<p class="st-hero__subtitle">The author you are looking for does not exist.</p>'
            . '</section>';

        return Response::html(
            FrontendLayout::render('Not Found ... ' . BlogServices::brandSuffix(), $content, currentPath: '/authors/'),
            404,
        );
    }
}
