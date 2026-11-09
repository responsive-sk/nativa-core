<?php

declare(strict_types = 1);

namespace Tests\Http;

use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Middleware\AdminUnavailableMiddleware;
use Modules\Core\Interfaces\HTTP\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AdminWithoutProviderTest — a routed admin path with no gate is a 404.
 *
 * Measured 2026-11-09 in a catalogue tree with `Cardboard` and no `Users`:
 * `/mark` was routed, `AuthMiddleware` was never wired because the provider
 * registry found nothing, and the dashboard answered 200 to a guest. The
 * kernel owns the admin prefix, so the kernel closes it: no provider means the
 * surface does not exist, and "does not exist" is 404 — not 403 (nothing
 * authenticated the caller) and not 500 (nothing failed).
 *
 * The middleware is exercised through a real `Kernel` and a real `Router`, so
 * the pipeline under test is the pipeline `src/http.php` builds.
 */
final class AdminWithoutProviderTest extends TestCase
{
    #[Test]
    public function aRoutedAdminPathWithoutAProviderAnswers404(): void
    {
        $kernel = $this->kernelWith([AdminRoutePolicy::prefix(), 'mark.dashboard']);

        $response = $kernel->handle(Request::create('GET', AdminRoutePolicy::prefix()));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('admin-fixture', $response->getContent());
    }

    #[Test]
    public function theSameMiddlewareLetsAPublicRouteThrough(): void
    {
        $kernel = $this->kernelWith(['/', 'test.public']);

        $response = $kernel->handle(Request::create('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('admin-fixture', $response->getContent());
    }

    #[Test]
    public function anAdminPathWithNoRouteAtAllIsStill404(): void
    {
        $kernel = new Kernel(Router::fromEntries([], []));
        $kernel->addMiddleware(new AdminUnavailableMiddleware());

        self::assertSame(404, $kernel->handle(Request::create('GET', AdminRoutePolicy::prefix()))->getStatusCode());
    }

    /**
     * One route under the admin prefix, named as the panel names it, carrying
     * the fixture action — the shape a `Cardboard`-only catalogue produces.
     *
     * @param array{string, string} $route path and name
     */
    private function kernelWith(array $route): Kernel
    {
        $router = Router::fromEntries([], [
            [
                'path' => $route[0],
                'method' => 'GET',
                'name' => $route[1],
                'handlerMethod' => null,
                'action' => AdminFixtureAction::class,
            ],
        ]);

        $kernel = new Kernel($router);
        $kernel->addMiddleware(new AdminUnavailableMiddleware());

        return $kernel;
    }
}

/**
 * Minimal action so the router has something to match; the admin test never
 * reaches it (the middleware answers first) and the public test proves the
 * middleware is not swallowing everything.
 */
final class AdminFixtureAction
{
    public function handle(Request $request): Response
    {
        return Response::html('admin-fixture');
    }
}
