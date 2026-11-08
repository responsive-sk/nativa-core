<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AdminRouteUrlNamesTest — admin navigation addresses a route by NAME.
 *
 * Tasks 2.8 and 2.9, from a measurement on 2026-10-17: 130 admin redirects and
 * 83 HTML links/forms (54 in PHP, 29 in .phtml) retyped the path their own
 * file had just declared beside a name, e.g.
 *
 *     #[Route(path: '/mark/settings/modules', name: 'mark.settings.modules')]
 *     ...
 *     return Response::redirect('/mark/settings/modules');
 *
 * The path and the name are one fact. Moving the route, or renaming it, left
 * the redirect pointing at the old spelling — and nothing failed, because the
 * old path still resolved until the route moved. The owner is the Router:
 * the Kernel injects it into an action that asks for it, and the action calls
 * `$this->url('mark.settings.modules')`.
 *
 * Two claims are pinned here:
 *
 *  1. **The tree holds no path-literal navigation.** Redirects, `HX-Redirect`
 *     headers, `href`, `action` and HTMX URLs are all scanned; the gate reads
 *     the catalogue (or the installed slice on a kernel-only checkout) and
 *     ignores comments, so the docblock above is not a false positive.
 *  2. **The NAME is what builds the URL.** The probe route's path is
 *     deliberately different from the name's usual path; a redirect that
 *     resolved `mark.settings` to `/mark/settings` would fail. The second half
 *     proves a hand-built action refuses to navigate instead of silently
 *     falling back to a global router.
 *
 * @internal
 *
 * @coversNothing
 */
final class AdminRouteUrlNamesTest extends TestCase
{
    #[Test]
    public function noAdminNavigationIsBuiltFromAPathLiteral(): void
    {
        $root = is_dir(APP_BASE_PATH . '/modules')
            ? APP_BASE_PATH . '/modules'
            : APP_BASE_PATH . '/src/Modules';

        self::assertDirectoryExists($root, 'the redirect scan found no action tree — the walk would pass on nothing');

        $found = [];
        $files = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || !in_array($file->getExtension(), ['php', 'phtml'], true)) {
                continue;
            }

            $path = $file->getPathname();

            // HTML navigation is emitted from the HTTP layer and from the
            // shared UI services it calls (BulkActions builds forms). A
            // service that spells a path is the same defect one layer down.
            if (!str_contains($path, '/Interfaces/HTTP/') && !str_contains($path, '/Application/Services/')) {
                continue;
            }

            $files++;
            $relative = substr($path, \strlen(APP_BASE_PATH) + 1);

            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
                // A comment is documentation, not a redirect. The trait's own
                // docblock quotes the defect; it must not count as the defect.
                if (str_starts_with(ltrim($line), '*')) {
                    continue;
                }

                if (str_contains($line, "Response::redirect('/mark")
                    || str_contains($line, "HX-Redirect', '/mark")
                    || str_contains($line, 'href="/mark')
                    || str_contains($line, 'action="/mark')
                    || str_contains($line, 'hx-get="/mark')
                    || str_contains($line, 'hx-post="/mark')
                    || str_contains($line, 'hx-delete="/mark')
                    || str_contains($line, 'hx-put="/mark')
                    || str_contains($line, 'hx-patch="/mark')
                    || str_contains($line, 'data-reorder-url="/mark')
                ) {
                    $found[] = sprintf('%s:%d %s', $relative, $index + 1, trim($line));
                }
            }
        }

        self::assertGreaterThan(20, $files, 'the scan found ' . $files . ' HTTP files — the walk or the path filter stopped working');

        self::assertSame(
            [],
            $found,
            "A redirect or link spells a route as a path instead of asking the Router for its name:\n"
            . implode("\n", $found)
            . "\nUse \$this->url('route.name') (ResolvesRouteUrls); the Kernel injects the Router.",
        );
    }

    #[Test]
    public function theInjectedRouterResolvesTheNameRatherThanTheOldPath(): void
    {
        $router = Router::fromEntries([], [[
            'path'          => '/mark/settings-moved',
            'method'        => 'GET',
            'name'          => 'mark.settings',
            'handlerMethod' => 'handle',
            'action'        => AdminRedirectProbeAction::class,
        ]]);

        $response = (new Kernel($router, false))
            ->handle(Request::create('GET', '/mark/settings-moved'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/mark/settings-moved', $response->getHeader('Location'));
    }

    #[Test]
    public function anActionWithoutARouterRefusesToNavigate(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no Router was injected');

        (new AdminRedirectProbeAction())->handle(Request::create('GET', '/'));
    }
}

/**
 * The smallest action that navigates by name — the seam the Kernel fills.
 *
 * @internal
 */
final class AdminRedirectProbeAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        return Response::redirect($this->url('mark.settings'));
    }
}
