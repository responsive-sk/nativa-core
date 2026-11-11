<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Actions;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Core\Kernel\PageShell\PageShellRegistry;
use Shell\ShellContextProviderRegistry;

/**
 * HomepageAction — the kernel's own home surface.
 *
 * The kernel owns the QUESTIONS the page answers: the site name through the
 * `ShellContextProvider` port, the locale, and the port's `home` copy (merged
 * with the site name under `title`). The MARKUP belongs to whichever layout
 * module is installed, reached through `PageShellInterface` — so this file
 * names no theme, no shell class and no module. A tree with nothing installed
 * still answers `/` through `PlainPageShell`.
 *
 * It used to import `ThemeResolver`, `ThemeKernel`, `FrontendLayout` and
 * `Seo\WebsiteStructuredData` directly, which made the layout inseparable from
 * the kernel: moving it out would have been a kernel import of a module.
 *
 * The showcase home (hero + feature sections + latest articles, copy through
 * the Locales translation system) is the app's, not the kernel's: its ledger
 * answer is `M` — it moves to the module/slice that owns the copy it renders
 * (`Modules\Site` in `../nativa-minimal`, task 1.1).
 */
#[Route(path: '/', method: 'GET', name: 'home')]
final readonly class HomepageAction
{
    /**
     * The router is not read here: it is handed to the shell, which alone
     * knows which links its markup would otherwise advertise. Optional because
     * the Kernel injects it by type and a direct caller (a test) may not have
     * one — a shell without a router renders no module link.
     */
    public function __construct(private ?RouterInterface $router = null)
    {
    }

    public function handle(Request $request): Response
    {
        $locale = ShellContextProviderRegistry::defaultLocale() ?? 'sk_SK';
        $siteName = ShellContextProviderRegistry::siteName() ?? 'Nativa PHP Core';

        return Response::html(
            PageShellRegistry::current()->home(
                $request,
                $siteName,
                $locale,
                ShellContextProviderRegistry::copy('home', $locale),
                $this->router,
            ),
        );
    }
}
