<?php

declare(strict_types = 1);

namespace Tests\Http;

use Modules\Core\Domain\MarkRoutePolicy;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Actions\RobotsAction;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RobotsActionTest extends TestCase
{
    #[Test]
    public function itAllowsThePublicAssetPathsTheShellEmits(): void
    {
        $body = self::body(new RobotsAction());

        foreach ([
            '/dist/',
            '/fonts/',
            '/favicon.ico',
            '/favicon.svg',
            '/apple-touch-icon.png',
            '/robots.txt',
        ] as $path) {
            self::assertStringContainsString('Allow: ' . $path . "\n", $body);
        }
    }

    #[Test]
    public function itCachesTheCrawlPolicyForOneHour(): void
    {
        $response = (new RobotsAction())->handle(self::request());

        self::assertSame('public, max-age=3600', $response->getHeader('Cache-Control'));
        self::assertSame('text/plain; charset=utf-8', $response->getHeader('Content-Type'));
        self::assertStringStartsWith("User-agent: *\nDisallow: /\n", $response->getContent());
        self::assertStringContainsString(
            "Sitemap: http://responsive.sk/sitemap.xml\n",
            $response->getContent(),
        );
    }

    #[Test]
    public function itKeepsAdminDebugAndUploadPathsDisallowed(): void
    {
        $body = self::body(new RobotsAction());

        self::assertStringStartsWith("User-agent: *\nDisallow: /\n", $body);

        foreach ([MarkRoutePolicy::prefix(), '/debug', '/storage/uploads'] as $path) {
            self::assertStringNotContainsString('Allow: ' . $path . "\n", $body);
        }
    }

    #[Test]
    public function itAdvertisesLlmsWhenTheRouterFallsBackOrFindsTheRoute(): void
    {
        self::assertStringContainsString("Allow: /llms.txt\n", self::body(new RobotsAction()));

        $router = new Router([], discover: false);
        self::assertStringNotContainsString("Allow: /llms.txt\n", self::body(new RobotsAction($router)));

        $router->register(new Route('/llms.txt', 'GET'), RobotsAction::class);

        self::assertStringContainsString(
            "Allow: /llms.txt\n",
            self::body(new RobotsAction($router)),
        );
    }

    private static function body(RobotsAction $action): string
    {
        return $action->handle(self::request())->getContent();
    }

    private static function request(): Request
    {
        return Request::create('GET', '/robots.txt', headers: ['host' => 'responsive.sk']);
    }
}
