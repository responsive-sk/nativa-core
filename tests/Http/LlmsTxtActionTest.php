<?php

declare(strict_types = 1);

namespace Tests\Http;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Actions\LlmsTxtAction;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LlmsTxtActionTest — llms.txt must not advertise a route the tree does not have.
 *
 * Docs is a module. A kernel-only export carries no Docs slice, so `/docs` is a
 * 404 there; a link in llms.txt would teach a language model to fetch it. The
 * action asks the router instead of assuming the module is present.
 *
 * @internal
 *
 * @coversNothing
 */
final class LlmsTxtActionTest extends TestCase
{
    #[Test]
    public function aTreeWithoutTheDocsRouteDoesNotAdvertiseIt(): void
    {
        $body = self::render(new Router([], false));

        self::assertStringNotContainsString(
            '/docs',
            $body,
            'llms.txt advertised /docs in a tree with no Docs module — the link is a 404.',
        );
        self::assertStringContainsString('/sitemap.xml', $body, 'The fixed surfaces left the document.');
    }

    #[Test]
    public function aTreeWithTheDocsRouteAdvertisesIt(): void
    {
        $router = new Router([], false);
        $router->register(new Route(path: '/docs', method: 'GET', name: 'docs-index'), \stdClass::class);

        $body = self::render($router);

        self::assertStringContainsString(
            '/docs',
            $body,
            'The Docs route is installed, so llms.txt must point a model at it.',
        );
    }

    private static function render(Router $router): string
    {
        $response = (new LlmsTxtAction($router))->handle(Request::create('GET', '/llms.txt'));

        return $response->getContent();
    }
}
