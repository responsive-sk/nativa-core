<?php

declare(strict_types = 1);

namespace Tests\Boot;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Middleware\AdminUnavailableMiddleware;
use Modules\Core\Interfaces\HTTP\Middleware\MiddlewareInterface;
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
 * present for the admin surface to exist: a module that PROVIDES the auth port
 * (Users — its `#[AsAuthProvider]` `UsersAuthProvider` is DISCOVERED by
 * `AuthProviderRegistry`) and, when it is absent, the kernel answers the admin
 * prefix with 404 through `AdminUnavailableMiddleware`. Without a provider the
 * surface does not exist even when a module still ROUTES it: measured
 * 2026-11-09, `Cardboard` without `Users` answered 200 to a guest because the
 * gate was never wired, which is the defect this file was written to rule out.
 * With a provider → 302 to the login: the gate answers before dispatch, so a
 * guest never reaches a route at all. The kernel rule below asserts the
 * no-provider case separately: a path the kernel has no route for is never
 * Forbidden, and an admin path with a route but no gate is not 200 either.
 */
final class KernelOnlyBootTest extends TestCase
{
    private const string ADMIN_MODULE = 'Cardboard';

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function surfaces(): iterable
    {
        $gated = null !== self::authProvider();
        $docs = is_dir(APP_BASE_PATH . '/src/Modules/Docs') ? 200 : 404;

        yield 'homepage'   => ['GET', '/',            200];

        yield 'sitemap'    => ['GET', '/sitemap.xml', 200];

        yield 'robots'     => ['GET', '/robots.txt',  200];

        yield 'docs index' => ['GET', '/docs',        $docs];

        yield 'unknown'    => ['GET', '/nope',        404];

        // Gate first: with a provider installed the auth middleware answers
        // before routing, so an anonymous request is 302 whether or not the
        // route exists — and never the 200 that served the admin dashboard
        // to a guest (the measured defect, plan fix-mark-access-control).
        yield 'admin'      => ['GET', '/mark',        $gated ? 302 : 404];
    }

    #[Test]
    #[DataProvider('surfaces')]
    public function surfaceAnswersThroughTheKernel(string $method, string $uri, int $expected): void
    {
        $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));

        // Mirror src/http.php's wiring — the pipeline under test is the
        // pipeline the composition root builds, not a test-only one. The Auth
        // slice is optional, so both class names stay strings: `::class` and a
        // `use` would make the kernel-only export's PHPStan resolve classes the
        // export deliberately does not ship.
        $provider = self::authProvider();
        $gate = null !== $provider ? self::authGate($provider) : null;

        if (null !== $gate) {
            $kernel->addMiddleware($gate);
        } else {
            // The composition root's other branch: with no provider the admin
            // prefix does not exist, even if a module routes it.
            $kernel->addMiddleware(new AdminUnavailableMiddleware());
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
     * The provider registry belongs to the optional Auth module. No module, no
     * class; the name stays a string so this kernel test still typechecks in
     * the export that prunes Auth.
     */
    private static function authProvider(): ?object
    {
        /** @var string $registry */
        $registry = 'Modules\\Auth\\Application\\Services\\AuthProviderRegistry';

        if (!class_exists($registry) || !is_callable([$registry, 'first'])) {
            return null;
        }

        /** @var callable(): mixed $first */
        $first = [$registry, 'first'];
        $provider = $first();

        return is_object($provider) ? $provider : null;
    }

    /**
     * The module's middleware takes a provider and a sink, so discovery cannot
     * construct it. Reflection mirrors src/http.php and keeps the class name out
     * of a type position in the kernel-only export.
     */
    private static function authGate(object $provider): ?MiddlewareInterface
    {
        // Mirror src/http.php: widen the literal before reflection so the
        // kernel-only export can type-check a class that is not on disk.
        $keepString = static fn (string $class): string => $class;
        /** @var class-string $middleware */
        $middleware = $keepString('Modules\\Auth\\Interfaces\\HTTP\\Middleware\\AuthMiddleware');

        try {
            $gate = (new \ReflectionClass($middleware))->newInstance($provider);
        } catch (\Throwable) {
            return null;
        }

        return $gate instanceof MiddlewareInterface ? $gate : null;
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
