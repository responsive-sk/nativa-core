<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Actions;

use Modules\Core\Application\Services\WebsiteStructuredData;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Shell\PageContext;
use Shell\ShellContextProviderRegistry;
use Themes\FrontendLayout;
use Themes\ThemeKernel;
use Themes\ThemeResolver;

/**
 * HomepageAction — the kernel's own home surface.
 *
 * The showcase home (hero + feature sections + latest articles, copy through
 * the Locales translation system) is the app's, not the kernel's: its ledger
 * answer is `M` — it moves to the module/slice that owns the copy it renders
 * (`Modules\Site` in `../nativa-minimal`, task 1.1). What stays here is the
 * smallest surface the kernel can answer honestly with nothing installed: the
 * site name through the `ShellContextProvider` port, rendered by the kernel
 * theme from `ShellContext` + `PageContext` alone.
 *
 * No `Blog`, no `Locales`, no `Settings` import. The locale passed to the
 * page context is the mapper's default (itself the provider's answer with a
 * kernel fallback) — the kernel never hardcodes which locale owns `/`.
 *
 * ## The copy travels the port too
 *
 * The copy is the port's `home` group, merged with the site name under `title`
 * — the one key the kernel answers itself, and the key the short-key themes
 * (`ErikTheme`, `MonoTheme`) read.
 *
 * It used to be the site name alone. A theme with translated strings therefore
 * had to carry them itself, and a `sk_SK` site rendered English: the 25
 * `home.*` rows were already seeded in both locales, and nothing rendering the
 * page could ask for them without the import this class exists to avoid. Now
 * whoever owns translations answers, whoever owns the markup renders, and the
 * kernel in between names neither.
 */
#[Route(path: '/', method: 'GET', name: 'home')]
final class HomepageAction
{
    public function handle(Request $request): Response
    {
        $theme = (new ThemeResolver())->resolve($request);
        $locale = ShellContextProviderRegistry::defaultLocale() ?? 'sk_SK';
        $siteName = ShellContextProviderRegistry::siteName() ?? 'Nativa PHP Core';

        // `+` and not `array_merge`: the site name is the kernel's own answer for
        // `title`, so it is the LEFT operand and keeps the key if the port ever
        // grows one — while every key the port answers under a name of its own
        // (`home.hero_title`) is added beside it.
        $copy = ['title' => $siteName] + ShellContextProviderRegistry::copy('home', $locale);

        $content = ThemeKernel::for($theme)->homepage(
            PageContext::forHomepage(
                $request,
                $theme,
                $locale,
                copy: $copy,
            ),
        );

        $homeUrl = $request->absoluteUrl('/');

        // The home surface announces the site itself (Organization + WebSite,
        // with a SearchAction). The name is the port's answer, the URL the
        // request's, so a kernel with nothing installed still emits a truthful
        // block; Blog's article builders live with the module that owns them.
        return Response::html(FrontendLayout::render(
            $siteName,
            $content,
            currentPath: '/',
            canonicalUrl: $homeUrl,
            structuredData: WebsiteStructuredData::website($siteName, $homeUrl),
            theme: $theme,
        ));
    }
}
