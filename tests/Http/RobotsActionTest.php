<?php

declare(strict_types = 1);

namespace Tests\Http;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Actions\RobotsAction;
use Modules\Core\Interfaces\HTTP\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * robots.txt is a crawl hint, so every `Allow:` line is a claim about the site.
 * These tests ask the ROUTER, through the same `hasPath()` the action calls,
 * instead of asserting against a copy of the path list: a copy would stay green
 * while the file taught a crawler a 404, which is exactly the defect the
 * action's docblock records from the kernel-only export.
 */
final class RobotsActionTest extends TestCase
{
    #[Test]
    public function aKernelWithoutRoutesAdvertisesOnlyTheHomepage(): void
    {
        // No router at all: nothing can be confirmed, so nothing is claimed
        // beyond `/` and the sitemap that the kernel always serves.
        $body = self::render(null);

        self::assertStringStartsWith("User-agent: *\nDisallow: /\nAllow: /\$\n", $body);
        self::assertStringEndsWith("\nSitemap: http://localhost/sitemap.xml\n", $body);
        self::assertSame([], self::advertisedPaths($body));
    }

    #[Test]
    public function onlyThePathsTheRouterAnswersAreAllowed(): void
    {
        $body = self::render(self::router([
            // Served, and therefore advertised.
            ['/blog', 'GET'],
            ['/authors', '*'],
            ['/sitemap.xml', 'GET'],
            // Path known, but only as a form target: `hasPath()` asks for GET,
            // so a crawler is not sent at a POST-only endpoint.
            ['/privacy', 'POST'],
        ]));

        // `indexablePaths()` order, minus everything the router did not answer:
        // `/sk`, `/en`, `/cookies` and `/llms.txt` are absent by construction.
        self::assertSame(['/blog', '/authors', '/sitemap.xml'], self::advertisedPaths($body));
    }

    #[Test]
    public function theBodyIsPlainText(): void
    {
        $response = (new RobotsAction(null))->handle(Request::create('GET', '/robots.txt'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeader('Content-Type'));
    }

    /**
     * Every `Allow:` path in file order, minus the two entries the router is
     * not asked about: `/` (emitted unconditionally — `HomepageAction` is the
     * kernel's own route) and the generated cover directory, which is a
     * filesystem question and differs between a fresh checkout and an install
     * that has imported images.
     *
     * @return list<string>
     */
    private static function advertisedPaths(string $body): array
    {
        $paths = [];

        foreach (explode("\n", $body) as $line) {
            if (!str_starts_with($line, 'Allow: ')) {
                continue;
            }

            $path = substr($line, strlen('Allow: '));

            if ('/$' === $path || '/storage/blog-images' === $path) {
                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    private static function render(?Router $router): string
    {
        return (new RobotsAction($router))
            ->handle(Request::create('GET', '/robots.txt'))
            ->getContent();
    }

    /**
     * @param list<array{string, string}> $routes path and method, in registration order
     */
    private static function router(array $routes): Router
    {
        return Router::fromEntries([], array_map(
            static fn (array $route): array => [
                'path'          => $route[0],
                'method'        => $route[1],
                'name'          => null,
                'handlerMethod' => 'handle',
                'action'        => RobotsAction::class,
            ],
            $routes,
        ));
    }
}
