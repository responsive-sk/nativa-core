<?php

declare(strict_types = 1);

namespace Modules\Layout\Interfaces\HTTP;

use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Core\Kernel\Contracts\PageShellInterface;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Core\Kernel\PageShell\AsPageShell;
use Modules\Core\Kernel\PageShell\PlainPageShell;
use Modules\Layout\FrontendLayout;
use Modules\Layout\ThemeKernel;
use Modules\Layout\ThemeResolver;
use Seo\WebsiteStructuredData;
use Shell\PageContext;

/**
 * PageShell — the installed layout's answer to the kernel's page port.
 *
 * The kernel asks which site name, locale, copy and status; this module owns
 * the theme, the chrome and the markup. `#[AsPageShell]` is the only
 * registration: `PageShellRegistry` finds the class by existing, so the kernel
 * names no module and a removed layout leaves the plain shell behind.
 */
#[AsPageShell]
final class PageShell implements PageShellInterface
{
    public function home(
        Request $request,
        string $siteName,
        string $locale,
        array $copy,
        ?RouterInterface $router = null,
    ): string
    {
        $theme = (new ThemeResolver())->resolve($request);

        // A hero button is an advertisement, so point it at a surface this
        // tree serves or leave it out. `Router::hasPath()` is the question the
        // kernel already keeps (RobotsAction asks it before advertising
        // `/llms.txt`); a test double that only matches requests is not a
        // `Router`, and the theme then renders no action at all rather than a
        // link to a 404 — the same rule the seeded menu follows.
        $adminPrefix = AdminRoutePolicy::prefix();
        $hasBlog = $router instanceof Router && $router->hasPath('/blog');
        $hasAdmin = $router instanceof Router && $router->hasPath($adminPrefix);

        // `+` and not `array_merge`: the site name is the kernel's own answer
        // for `title`, so it is the LEFT operand and keeps the key — while
        // every key the port answers under a name of its own is added beside it.
        $content = ThemeKernel::for($theme)->homepage(
            PageContext::forHomepage(
                $request,
                $theme,
                $locale,
                copy: ['title' => $siteName] + $copy,
                blogHref: $hasBlog ? '/blog' : null,
                adminHref: $hasAdmin ? $adminPrefix : null,
            ),
        );

        $homeUrl = $request->absoluteUrl('/');

        return FrontendLayout::render(
            $siteName,
            $content,
            currentPath: '/',
            canonicalUrl: $homeUrl,
            structuredData: WebsiteStructuredData::website($siteName, $homeUrl),
            theme: $theme,
        );
    }

    public function error(int $status, string $title, string $detail, ?string $path = null, ?string $lang = null): string
    {
        $theme = self::resolveTheme($path);
        $content = ThemeKernel::for($theme)->errorContent($status, $title, $detail);

        try {
            return FrontendLayout::render(
                $status . ' ' . $title,
                $content,
                currentPath: $path ?? '/',
                lang: $lang,
                noindex: true,
                theme: $theme,
            );
        } catch (\Throwable) {
            return PlainPageShell::bare($status, $title, $detail);
        }
    }

    /**
     * Theme resolution that cannot fail the error page: anything the
     * resolver trips on (a provider reading a broken database during a 500)
     * degrades to the kernel theme, which the tree always has.
     */
    private static function resolveTheme(?string $path): string
    {
        if (null === $path) {
            return ThemeResolver::KERNEL_THEME;
        }

        try {
            return ThemeResolver::resolvePath($path);
        } catch (\Throwable) {
            return ThemeResolver::KERNEL_THEME;
        }
    }
}
