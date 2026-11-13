<?php

declare(strict_types = 1);

namespace Modules\PageCache\Tests;

use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Core\Interfaces\HTTP\Middleware\CsrfMiddleware;
use Modules\Core\Interfaces\HTTP\Middleware\SecurityHeadersMiddleware;
use Modules\PageCache\Application\Services\PageCacheService;
use Modules\PageCache\Interfaces\HTTP\Middleware\PageCacheMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PageCacheServiceTest (Boot 24) — who a cached page belongs to.
 *
 * The page cache is keyed by method + path + query + locale, which is to say by
 * nothing that distinguishes one visitor from another. It is written by
 * `PageCacheMiddleware` on every cacheable response and read back by that same
 * middleware for the next anonymous GET — and by `RateLimitMiddleware` (serving
 * to whoever trips the limit) when the limiter blocks a request.
 *
 * That makes any session-dependent render a cross-visitor leak the moment it is
 * stored, and one such render exists by design: the shell chrome advertises the
 * admin surface to a signed-in editor and to nobody else (`FrontendLayout`
 * through `sessionHasAuth()`, and the Settings shell provider's menu filter).
 * An editor's `/` stored under the shared key is an anonymous reader being
 * handed a link to `/mark` — measured on the live server before this rule
 * existed, in the rate-limit fallback path.
 *
 * The rule pinned here: a response rendered for a signed-in visitor is never
 * cacheable, and the anonymous render of the same URL still is. Both halves
 * matter — a policy that quietly stopped caching the site would also silence
 * this test's failure.
 *
 * The tests drive the MIDDLEWARE for the write path (what actually populates the
 * cache) and the service for the bypass table around the new rule, so an edit
 * that moves the rule out of `isCacheable()` and leaves future writers to
 * remember it fails here.
 */
final class PageCacheServiceTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        if (!class_exists(PageCacheService::class)) {
            $this->markTestSkipped('PageCache is not installed in this checkout.');
        }

        $this->cacheDir = sys_get_temp_dir() . '/nativa-page-cache-' . bin2hex(random_bytes(6));

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->signOut();

        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->cacheDir);
    }

    #[Test]
    public function anAnonymousPageIsWrittenToTheCache(): void
    {
        $this->assertCount(0, $this->cacheFiles(), 'Precondition: nothing is cached yet.');

        $this->writeThroughMiddleware(Request::create('GET', '/blog'));

        $this->assertCount(
            1,
            $this->cacheFiles(),
            'The anonymous render must still be cached — the cache exists for the rate-limit fallback.',
        );
    }

    #[Test]
    public function anEditorsPageIsNeverWrittenToTheCache(): void
    {
        $this->signIn();

        $this->writeThroughMiddleware(Request::create('GET', '/'));

        $this->assertSame(
            [],
            $this->cacheFiles(),
            'A page rendered for a signed-in visitor entered a cache every visitor can read.',
        );
    }

    #[Test]
    public function signingOutMakesTheSamePageCacheableAgain(): void
    {
        $this->signIn();
        $this->writeThroughMiddleware(Request::create('GET', '/'));
        $this->assertSame([], $this->cacheFiles());

        $this->signOut();
        $this->writeThroughMiddleware(Request::create('GET', '/'));

        $this->assertCount(
            1,
            $this->cacheFiles(),
            'The rule must cost the editor their own cache entry, not the whole site its cache.',
        );
    }

    /**
     * A form's token is per SESSION. A body that contains it is not a page a
     * shared key may hold: the next visitor would post a credential that is not
     * theirs. Found by `bin/build/admin-smoke.sh` — the first guest comment on a
     * cached article page came back 403.
     */
    #[Test]
    public function aBodyThatPrintedTheSessionTokenIsNeverStored(): void
    {
        $this->startSessionWithToken();

        $this->assertIsString(
            CsrfMiddleware::sessionToken(),
            'Precondition: printing the token opened the session that holds it.',
        );

        $this->writeThroughMiddleware(Request::create('GET', '/blog'), '<html>' . CsrfMiddleware::field() . '</html>');

        $this->assertSame(
            [],
            $this->cacheFiles(),
            'A response carrying this visitor\'s CSRF token was stored under a key every visitor shares.',
        );
    }

    /**
     * The other half of the same rule: the guard must cost the form-bearing
     * pages their cache entry, not the whole site its cache.
     */
    #[Test]
    public function aPageWithoutAFormIsStillStoredWhileASessionHoldsAToken(): void
    {
        $this->startSessionWithToken();

        $this->writeThroughMiddleware(Request::create('GET', '/blog'), '<html><p>no form here</p></html>');

        $this->assertCount(1, $this->cacheFiles(), 'A page that printed no token must still be cached.');
    }

    /**
     * The read path, first half: a fresh entry answers the request WITHOUT the
     * page being rendered again. That is the whole point of the cache on the
     * ordinary path — the render is where a deployment's seconds go.
     */
    #[Test]
    public function aFreshEntryIsServedWithoutRenderingThePageAgain(): void
    {
        $request = Request::create('GET', '/blog');

        [$first, $rendersAfterFirst] = $this->serveThroughMiddleware($request);
        [$second, $rendersAfterSecond] = $this->serveThroughMiddleware($request);

        $this->assertSame(1, $rendersAfterFirst, 'The first request has nothing to serve, so it renders.');
        $this->assertNull($first->getHeader('X-Cache'), 'A rendered response is not a cache hit.');

        $this->assertSame(0, $rendersAfterSecond, 'The second request rendered the page the cache was supposed to answer.');
        $this->assertSame('HIT', $second->getHeader('X-Cache'));
        $this->assertSame('<html>rendered</html>', (string) $second->getContent());
    }

    /**
     * Locale is part of the key — not just a parameter with a default.
     *
     * `LocaleMiddleware` strips `/en` from the path before this cache sees the
     * request, so the path plus the DEFAULT locale cannot tell `/blog` from
     * `/en/blog`. Measured on the artifact, `/en/blog` was answered from the
     * Slovak entry (and `/en` from the `/` entry). The locale the middleware
     * resolved for the request — carried as its `locale` attribute — is what
     * keeps the two apart, in both directions.
     */
    #[Test]
    public function theSamePathInAnotherLocaleIsNotAnsweredFromTheCache(): void
    {
        [$defaultLocale, $otherLocale] = ContentLocaleServices::mapper()->locales();

        $default = Request::create('GET', '/blog');
        $default->setAttribute('locale', $defaultLocale);

        $other = Request::create('GET', '/en/blog');
        $other->setPathInfo('/blog');
        $other->setAttribute('locale', $otherLocale);

        $this->serveThroughMiddleware($default, '<html>default locale</html>');

        [$served, $renders] = $this->serveThroughMiddleware($other, '<html>other locale</html>');

        $this->assertSame(1, $renders, 'The prefixed path was answered from the entry the bare path wrote.');
        $this->assertSame('<html>other locale</html>', (string) $served->getContent());

        [$defaultAgain, $defaultRenders] = $this->serveThroughMiddleware($default);
        $this->assertSame(0, $defaultRenders);
        $this->assertSame('<html>default locale</html>', (string) $defaultAgain->getContent());

        [$otherAgain, $otherRenders] = $this->serveThroughMiddleware($other);
        $this->assertSame(0, $otherRenders);
        $this->assertSame('<html>other locale</html>', (string) $otherAgain->getContent());
    }

    /**
     * The read path, second half: what comes out of the cache still carries the
     * placeholder, so `SecurityHeadersMiddleware` — which wraps this middleware
     * — stamps a fresh nonce on the response that actually leaves. A served
     * entry pinned to the nonce of the response that wrote it would be a CSP
     * that no longer matches its own body.
     */
    #[Test]
    public function aServedEntryKeepsTheNoncePlaceholder(): void
    {
        $request = Request::create('GET', '/');
        $body = '<html><script nonce="' . SecurityHeadersMiddleware::NONCE_PLACEHOLDER . '">x</script></html>';

        $this->serveThroughMiddleware($request, $body);
        [$served, $renders] = $this->serveThroughMiddleware($request, '<html>rendered again</html>');

        $this->assertSame(0, $renders, 'Precondition: the entry must answer the second request without a render.');
        $this->assertStringContainsString(
            SecurityHeadersMiddleware::NONCE_PLACEHOLDER,
            (string) $served->getContent(),
            'A served entry must keep the placeholder for SecurityHeadersMiddleware to replace.',
        );
        $this->assertStringNotContainsString('rendered again', (string) $served->getContent());
    }

    /**
     * A body that already went through the nonce substitution (a real nonce
     * baked in) must never be stored verbatim: the next visitor's CSP header
     * carries a fresh nonce, so every inline script — the pre-paint
     * theme-init at the top of the document first — would be blocked as a
     * `script-src-elem` violation. Measured on the deployment: page cache ON,
     * localhost (cache OFF) clean.
     */
    #[Test]
    public function aBakedInNonceIsNormalizedBackToThePlaceholderOnStore(): void
    {
        $stale = bin2hex(random_bytes(16));
        $request = Request::create('GET', '/');
        $body = '<html><script nonce="' . $stale . '">x</script></html>';

        $this->writeThroughMiddleware($request, $body);

        [$served, $renders] = $this->serveThroughMiddleware($request, '<html>rendered again</html>');

        $this->assertSame(0, $renders, 'Precondition: the entry must answer the second request without a render.');
        $this->assertStringContainsString(
            SecurityHeadersMiddleware::NONCE_PLACEHOLDER,
            (string) $served->getContent(),
            'A stale nonce entered the shared cache instead of the placeholder.',
        );
        $this->assertStringNotContainsString($stale, (string) $served->getContent());
    }

    /**
     * The other half: entries poisoned BEFORE this normalization (a deploy
     * does not flush the cache) heal on read, so the fix takes effect without
     * anyone having to flush `storage/cache/pages` by hand.
     */
    #[Test]
    public function anAlreadyPoisonedEntryIsHealedOnRead(): void
    {
        $stale = bin2hex(random_bytes(16));
        $service = new PageCacheService($this->cacheDir);
        $request = Request::create('GET', '/');
        $key = $service->cacheKeyForRequest($request);

        file_put_contents(
            $this->cacheDir . '/' . $key . '.json',
            json_encode([
                'content' => '<html><script nonce="' . $stale . '">x</script></html>',
                'statusCode' => 200,
                'headers' => ['Content-Type' => 'text/html; charset=UTF-8'],
                'cachedAt' => time(),
                'ttl' => 300,
            ], JSON_THROW_ON_ERROR),
        );

        $entry = $service->get($key);

        $this->assertNotNull($entry, 'Precondition: the poisoned entry must still be readable.');
        $this->assertStringContainsString(SecurityHeadersMiddleware::NONCE_PLACEHOLDER, $entry['content']);
        $this->assertStringNotContainsString($stale, $entry['content']);
    }

    /**
     * The serve path obeys the REQUEST half of the bypass table, entry or no
     * entry: a stale guide, an admin page, a POST or a submission state must
     * never be answered from a shared file.
     *
     * Deliberately a different table than `uncacheableRequests()`: that one
     * also carries the RESPONSE rules (a 404 is not stored, but `GET /` is
     * still a request a fresh entry may answer), and this path decides before
     * any response exists.
     *
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('requestsThatAreNeverServedFromTheCache')]
    public function nothingFromTheRequestBypassTableIsServedFromTheCache(string $method, string $path, array $query): void
    {
        $request = Request::create($method, $path, $query);

        $this->seedCacheEntry($request, '<html>from the cache</html>');
        [$served, $renders] = $this->serveThroughMiddleware($request);

        $this->assertSame(1, $renders, sprintf('%s %s was answered from the page cache.', $method, $path));
        $this->assertNull($served->getHeader('X-Cache'), sprintf('%s %s must not report a cache hit.', $method, $path));
        $this->assertSame('<html>rendered</html>', (string) $served->getContent());
    }

    /**
     * The cross-visitor rule on the READ path: an anonymous entry sitting in
     * the cache is not an answer for a signed-in editor, whose chrome names the
     * admin surface. The store half of this rule is pinned above; a serve half
     * that forgot it would hand the editor the anonymous page instead.
     */
    #[Test]
    public function aSignedInVisitorIsNeverServedTheAnonymousEntry(): void
    {
        $request = Request::create('GET', '/');

        $this->serveThroughMiddleware($request, '<html>anonymous</html>');
        $this->assertCount(1, $this->cacheFiles(), 'Precondition: the anonymous render is cached.');

        $this->signIn();
        [$served, $renders] = $this->serveThroughMiddleware($request);

        $this->assertSame(1, $renders, 'The editor was served the anonymously rendered page from the cache.');
        $this->assertNull($served->getHeader('X-Cache'));
        $this->assertSame('<html>rendered</html>', (string) $served->getContent());
    }

    /**
     * The bypass table the new rule sits inside — pinned so that an edit to
     * `isCacheable()` cannot trade one leak for another.
     *
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('uncacheableRequests')]
    public function theExistingBypassRulesStillHold(string $method, string $path, int $status, array $query): void
    {
        $request = Request::create($method, $path, $query);
        $response = Response::html('<html>ok</html>', $status);

        $this->assertFalse(
            new PageCacheService($this->cacheDir)->isCacheable($request, $response),
            sprintf('%s %s must not be cacheable.', $method, $path),
        );
    }

    /**
     * The request half of the table — what the middleware refuses to LOOK UP,
     * before any response exists to judge.
     *
     * @return iterable<string, array{0: string, 1: string, 2: array<string, string>}>
     */
    public static function requestsThatAreNeverServedFromTheCache(): iterable
    {
        yield 'a POST is never a page' => ['POST', '/', []];

        yield 'the admin surface' => ['GET', AdminRoutePolicy::prefix(), []];

        yield 'a guide is the file' => ['GET', '/docs/kernel', []];

        yield 'a submission state' => ['GET', '/blog', ['form-sent' => '1']];
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: array<string, string>}>
     */
    public static function uncacheableRequests(): iterable
    {
        yield 'a POST is never a page' => ['POST', '/', 200, []];

        yield 'the admin surface' => ['GET', '/mark', 200, []];

        yield 'a guide is the file' => ['GET', '/docs/kernel', 200, []];

        yield 'an error page' => ['GET', '/', 404, []];

        yield 'a submission state' => ['GET', '/blog', 200, ['form-sent' => '1']];
    }

    /**
     * The real write path: the middleware captures a response only when the
     * service calls it cacheable.
     */
    private function writeThroughMiddleware(Request $request, string $body = '<html>ok</html>'): void
    {
        $middleware = new PageCacheMiddleware(new PageCacheService($this->cacheDir));

        $middleware->handle($request, static fn (): Response => Response::html($body));
    }

    /**
     * A visitor the app would have given a token to — and ONLY a token: nobody is
     * signed in, so these cases measure the CSRF rule rather than the signed-in
     * one. Deliberately sets NO cookie, because the render that escapes is the
     * FIRST one: the request arrives without a session cookie, printing the form
     * opens the session and sets the cookie on the way out, and the store happens
     * after that — so a guard that asked `$_COOKIE` would see nothing to ask
     * about and cache the page.
     */
    private function startSessionWithToken(): void
    {
        CsrfMiddleware::token();
    }

    /**
     * Drive the middleware the way the kernel does — through the READ path, so
     * a hit is visible as "the render did not happen".
     *
     * @return array{0: Response, 1: int} the response and how many times the page was rendered
     */
    private function serveThroughMiddleware(Request $request, string $body = '<html>rendered</html>'): array
    {
        $renders = 0;
        $middleware = new PageCacheMiddleware(new PageCacheService($this->cacheDir));

        $response = $middleware->handle(
            $request,
            static function () use (&$renders, $body): Response {
                $renders++;

                return Response::html($body);
            },
        );

        return [$response, $renders];
    }

    /**
     * Put an entry under the key the middleware would build for this request,
     * so the bypass tests measure whether the middleware LOOKS IT UP rather
     * than whether something ever wrote it.
     */
    private function seedCacheEntry(Request $request, string $body): void
    {
        $service = new PageCacheService($this->cacheDir);
        $key = $service->cacheKeyForRequest($request);

        $service->set($key, Response::html($body));
    }

    /**
     * @return list<string>
     */
    private function cacheFiles(): array
    {
        return glob($this->cacheDir . '/*.json') ?: [];
    }

    /**
     * The session the app itself would have: started, stamped with the key the
     * kernel's shell chrome reads, and announced by cookie — which is what the
     * guard requires before `$_SESSION` is looked at at all.
     */
    private function signIn(): void
    {
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        $_SESSION['auth'] = ['user_id' => 'test-editor'];
        $_COOKIE[session_name()] = session_id();
    }

    private function signOut(): void
    {
        $_SESSION = [];

        if (false !== session_name()) {
            unset($_COOKIE[session_name()]);
        }

        if (PHP_SESSION_ACTIVE === session_status()) {
            session_destroy();
        }
    }
}
