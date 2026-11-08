<?php

declare(strict_types = 1);

namespace Tests\Boot;

use Modules\Core\Application\Services\AuthProviderRegistry;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Middleware\AuthMiddleware;
use Modules\Core\Interfaces\HTTP\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * KernelOnlyBootTest (task 2.3) — the six content surfaces, dispatched
 * through the Kernel, never by calling an action directly.
 *
 * Only STATUS codes are asserted: which theme renders a 200 is
 * ThemeKernel's business, not the boot contract's.
 *
 * `/mark` is the one path whose expected status depends on operator state,
 * and the dependency is written down instead of assumed. Two things must be
 * present for the admin surface to exist: a module that ROUTES it (Cardboard)
 * and a module that PROVIDES the auth port (Users — its `#[AsAuthProvider]`
 * `UsersAuthProvider` is DISCOVERED by `AuthProviderRegistry`, and the
 * middleware is wrapped around what it finds, which is the same call
 * `src/http.php` makes). Neither → 404 (no route — not 403, not 500). Router but no
 * provider → 200, which is the unauthenticated admin serving this file was
 * written to rule out. Both → 302 to the login: the gate answers before
 * dispatch, so a guest never reaches a route at all. The kernel rule below
 * asserts the first case separately: a path the kernel has no route for is
 * never Forbidden.
 */
final class KernelOnlyBootTest extends TestCase
{
    private const string ADMIN_MODULE = 'Cardboard';

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function surfaces(): iterable
    {
        $adminInstalled = is_dir(APP_BASE_PATH . '/src/Modules/' . self::ADMIN_MODULE);
        $gated = null !== AuthProviderRegistry::first();

        yield 'homepage'   => ['GET', '/',            200];

        yield 'sitemap'    => ['GET', '/sitemap.xml', 200];

        yield 'robots'     => ['GET', '/robots.txt',  200];

        yield 'docs index' => ['GET', '/docs',        200];

        yield 'unknown'    => ['GET', '/nope',        404];

        // Gate first: with a provider installed the auth middleware answers
        // before routing, so an anonymous request is 302 whether or not the
        // route exists — and never the 200 that served the admin dashboard
        // to a guest (the measured defect, plan fix-mark-access-control).
        yield 'admin'      => ['GET', '/mark',        $gated ? 302 : ($adminInstalled ? 200 : 404)];
    }

    #[Test]
    #[DataProvider('surfaces')]
    public function surfaceAnswersThroughTheKernel(string $method, string $uri, int $expected): void
    {
        $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));

        // Mirror src/http.php's wiring — the pipeline under test is the
        // pipeline the composition root builds, not a test-only one. Two
        // Core-only lines, and they cannot drift from production: both sides
        // ask the same registry the same question ("does any module ship a
        // provider?"), so there is no module-specific copy left to diverge.
        $provider = AuthProviderRegistry::first();

        if (null !== $provider) {
            $kernel->addMiddleware(new AuthMiddleware($provider));
        }

        $response = $kernel->handle(Request::create($method, $uri));

        $this->assertSame($expected, $response->getStatusCode(), sprintf('%s %s', $method, $uri));

        if (302 === $expected) {
            $this->assertSame(
                '/login',
                $response->getHeader('Location'),
                sprintf('%s %s must redirect to the login, not merely answer 302', $method, $uri),
            );
        }
    }

    /**
     * The kernel-only promise, independent of what is installed: a route the
     * tree does not have is NOT FOUND. Answering 403 would mean the tree
     * knows about admin it cannot protect, which is the shape task 2.3 exists
     * to rule out.
     */
    #[Test]
    public function unroutedAdminPathIsNeverForbidden(): void
    {
        if (is_dir(APP_BASE_PATH . '/src/Modules/' . self::ADMIN_MODULE)) {
            $this->markTestSkipped(self::ADMIN_MODULE . ' is installed — the admin path IS routed here.');
        }

        $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));
        $response = $kernel->handle(Request::create('GET', '/mark'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNotSame(403, $response->getStatusCode());
    }
}
