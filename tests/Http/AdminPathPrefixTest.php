<?php

declare(strict_types = 1);

namespace Tests\Http;

use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Actions\RobotsAction;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AdminPathPrefixTest — `/mark` is the default spelling, not the only one.
 *
 * Task 0.3 of `.ai-factory/plans/feature-constant-ownership.md`: two constants
 * both named `ADMIN_PATH` disagreed — `SettingsShellContextProvider` said
 * `'/mark'` and `AuthMiddlewareDenialTest` said the same string a second time,
 * while `AuthMiddleware`, `PageCacheService` and every hardcoded link each
 * carried their own copy. `AdminRoutePolicy` is the owner now, and
 * `ADMIN_PATH` is a validated value rather than a hardcoded fact:
 *
 *   - an unset value is `/mark`, exactly as before, so no deployment changes;
 *   - a configured value (`/admin`) is an ALIAS: it serves every admin route
 *     and 301s within itself;
 *   - a malformed value falls back to `/mark` instead of hiding the panel;
 *   - the internal `/mark/...` spelling keeps working, so hardcoded links and
 *     stored URLs do not 404 on a renamed prefix.
 *
 * The last test is the end-to-end claim: a real Kernel, a real Router and a
 * real action, with only the environment saying `/admin`.
 *
 * @internal
 *
 * @coversNothing
 */
final class AdminPathPrefixTest extends TestCase
{
    private string|false $previousProcessValue = false;

    /** @var array<string, mixed> */
    private array $previousEnv = [];

    /** @var array<string, mixed> */
    private array $previousServer = [];

    protected function setUp(): void
    {
        $this->previousProcessValue = getenv('ADMIN_PATH');
        $this->previousEnv = $_ENV;
        $this->previousServer = $_SERVER;

        $this->configure(null);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->previousEnv;
        $_SERVER = $this->previousServer;

        if (false === $this->previousProcessValue) {
            putenv('ADMIN_PATH');
        } else {
            putenv('ADMIN_PATH=' . $this->previousProcessValue);
        }
    }

    /**
     * Set (or clear) `ADMIN_PATH` for one test. `Env::get()` reads `$_ENV`
     * first, then `$_SERVER`, then `getenv()`, so all three are moved
     * together — otherwise a value from the real `.env` would leak in.
     */
    private function configure(?string $value): void
    {
        unset($_ENV['ADMIN_PATH'], $_SERVER['ADMIN_PATH']);
        putenv('ADMIN_PATH');

        if (null !== $value) {
            $_ENV['ADMIN_PATH'] = $value;
            putenv('ADMIN_PATH=' . $value);
        }
    }

    public function testTheDefaultIsTheSpellingTheRouteAttributesDeclare(): void
    {
        self::assertSame('/mark', AdminRoutePolicy::prefix());

        self::assertTrue(AdminRoutePolicy::isAdminPath('/mark'));
        self::assertTrue(AdminRoutePolicy::isAdminPath('/mark/users/7'));
        self::assertFalse(AdminRoutePolicy::isAdminPath('/marketing'));

        self::assertSame('/mark/users', AdminRoutePolicy::canonicalPath('/mark/users'));
        self::assertSame('/mark/users', AdminRoutePolicy::publicPath('/mark/users'));
    }

    public function testAConfiguredPrefixIsServedLikeTheDefault(): void
    {
        $this->configure('/admin');

        self::assertSame('/admin', AdminRoutePolicy::prefix());

        // Both spellings are admin paths.
        self::assertTrue(AdminRoutePolicy::isAdminPath('/admin/users'));
        self::assertTrue(AdminRoutePolicy::isAdminPath('/mark/users'));

        // Canonicalization is one-directional: the router sees `/mark/...`.
        self::assertSame('/mark', AdminRoutePolicy::canonicalPath('/admin'));
        self::assertSame('/mark/users/7', AdminRoutePolicy::canonicalPath('/admin/users/7'));
        self::assertSame('/adminfoo', AdminRoutePolicy::canonicalPath('/adminfoo'));

        // `publicPath()` maps a router-built redirect back to what was typed.
        self::assertSame('/admin/users', AdminRoutePolicy::publicPath('/mark/users'));
        self::assertSame('/admin/users?page=2', AdminRoutePolicy::publicPath('/mark/users?page=2'));
        self::assertSame('/admin', AdminRoutePolicy::publicPath('/mark'));
        self::assertSame('/blog/post', AdminRoutePolicy::publicPath('/blog/post'));

        // A prefix matches whole segments only.
        self::assertFalse(AdminRoutePolicy::isAdminPath('/adminfoo'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenPrefixes(): iterable
    {
        yield 'relative'         => ['admin'];

        yield 'trailing slash'   => ['/admin/'];

        yield 'empty'            => [''];

        yield 'absolute URL'     => ['https://example.test/mark'];

        yield 'query'            => ['/admin?x=1'];

        yield 'fragment'         => ['/admin#top'];

        yield 'space'            => ['/ad min'];

        yield 'double slash'     => ['/admin//panel'];

        yield 'bare slash'       => ['/'];
    }

    #[DataProvider('brokenPrefixes')]
    public function testAMalformedValueFallsBackInsteadOfHidingThePanel(string $value): void
    {
        $this->configure($value);

        self::assertSame('/mark', AdminRoutePolicy::prefix(), sprintf('ADMIN_PATH=%s must fall back', var_export($value, true)));

        // No alias existed, so the literal path stays literal.
        self::assertSame('/admin/users', AdminRoutePolicy::canonicalPath('/admin/users'));
        self::assertFalse(AdminRoutePolicy::isAdminPath($value));
    }

    public function testSurroundingWhitespaceIsTrimmedRatherThanRejected(): void
    {
        $this->configure('  /admin  ');

        self::assertSame('/admin', AdminRoutePolicy::prefix());
        self::assertSame('/mark/users', AdminRoutePolicy::canonicalPath('/admin/users'));
    }

    public function testTheRouterMatchesTheConfiguredPrefixAfterCanonicalization(): void
    {
        $this->configure('/admin');
        $router = $this->probeRouter();

        $request = Request::create('GET', AdminRoutePolicy::canonicalPath('/admin/probe'));
        $match = $router->match($request);

        self::assertNotNull($match);
        self::assertSame('/mark/probe', $match['route']->path);

        // Without canonicalization the router only knows the internal path —
        // this is the seam the kernel owns.
        $unmapped = $router->match(Request::create('GET', '/admin/probe'));
        self::assertNull($unmapped);
    }

    public function testTheKernelServesTheConfiguredPrefixAndKeepsRedirectsOnIt(): void
    {
        $this->configure('/admin');
        $kernel = new Kernel($this->probeRouter(), false);

        $response = $kernel->handle(Request::create('GET', '/admin/probe'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('User-agent: *', $response->getContent());

        // The internal spelling still works: hardcoded links keep resolving.
        $internal = $kernel->handle(Request::create('GET', '/mark/probe'));
        self::assertSame(200, $internal->getStatusCode());

        // Trailing-slash canonicalization must not leak `/mark` at the visitor.
        $redirect = $kernel->handle(Request::create('GET', '/admin/probe/', ['page' => '2']));
        self::assertSame(301, $redirect->getStatusCode());
        self::assertSame('/admin/probe?page=2', $redirect->getHeader('Location'));

        // An unclassified admin path is still a 404, and nothing in the page
        // leaks the internal spelling.
        $missing = $kernel->handle(Request::create('GET', '/admin/nope'));
        self::assertSame(404, $missing->getStatusCode());
        self::assertStringNotContainsString('/mark/nope', $missing->getContent());
    }

    private function probeRouter(): Router
    {
        return Router::fromEntries([], [[
            'path'          => '/mark/probe',
            'method'        => 'GET',
            'name'          => 'probe',
            'handlerMethod' => 'handle',
            'action'        => RobotsAction::class,
        ]]);
    }
}
