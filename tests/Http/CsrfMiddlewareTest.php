<?php

declare(strict_types = 1);

namespace Tests\Http;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Middleware\CsrfMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CsrfMiddlewareTest — audit finding #7: the gate validated the token and
 * nothing else, so a cross-site POST that carried a token (leaked in a
 * referrer, scraped from a cached page, or simply replayed) was accepted. The
 * middleware now also cross-checks the request's `Origin` — or its `Referer`
 * fallback — against the host the request was addressed to.
 *
 * Both halves of that claim are pinned here, because only the pair makes the
 * check worth having:
 *
 *  - the cross-site shapes are refused EVEN WITH a valid token (otherwise the
 *    new lock is indistinguishable from the old one), and
 *  - every shape a browser or an honest client sends legitimately still passes:
 *    the same host over another scheme or port, a matching `Referer` alone, and
 *    a request carrying neither header (an HTTP client, a server-to-server
 *    call, a proxy that strips both). A cross-check that refused those would be
 *    reverted the day it broke an integration.
 *
 * The pre-existing contract is pinned in the same file: no token / wrong token
 * is still `CSRF token validation failed` and is decided BEFORE the origin is
 * looked at, so no probe that existed before the fix changes meaning. The
 * `TRUSTED_HOSTS` case is the subtler one — the site's own host comes from the
 * `Host` header, so without the allowlist a forged Host would let a cross-site
 * request describe itself as same-site.
 *
 * The session is the app's own (started, then stamped with the key
 * `CsrfMiddleware` reads) — the same shape the module tests use.
 */
final class CsrfMiddlewareTest extends TestCase
{
    // A fixture, not a credential: the middleware hashes this against whatever
    // the session holds, so the literal IS the assertion.
    // @mago-expect lint:no-literal-password
    private const string TOKEN = 'a-known-csrf-token';

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        $_SESSION = ['csrf_token' => self::TOKEN];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        putenv('TRUSTED_HOSTS');

        parent::tearDown();
    }

    /**
     * The ordinary browser POST: same host, token in the body.
     */
    #[Test]
    public function aSameOriginPostWithTheTokenPasses(): void
    {
        $response = $this->dispatch($this->post(
            ['host' => 'example.test', 'origin' => 'https://example.test'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('reached', (string) $response->getContent());
    }

    /**
     * The finding itself: a valid token is not enough when the request was
     * launched from somewhere else.
     */
    #[Test]
    public function aCrossSitePostIsRefusedEvenWithAValidToken(): void
    {
        $response = $this->dispatch($this->post(
            ['host' => 'example.test', 'origin' => 'https://evil.test'],
        ));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Cross-site request rejected', (string) $response->getContent());
    }

    /**
     * The token is decided first, so the tokenless shape is byte-identical to
     * what it was before the origin check existed — and a cross-site request
     * without a token still fails on the token, not on its origin.
     */
    #[Test]
    public function theTokenIsDecidedBeforeTheOrigin(): void
    {
        foreach (
            [
                'no token'          => [[], ['origin' => 'https://evil.test']],
                // Same fixture rule: a wrong token must fail on the token.
                // @mago-expect lint:no-literal-password
                'wrong token'       => [['_token' => 'not-the-token'], ['origin' => 'https://evil.test']],
            ] as $case => [$body, $headers]
        ) {
            $response = $this->dispatch($this->post(['host' => 'example.test'] + $headers, $body));

            self::assertSame(403, $response->getStatusCode(), $case);
            self::assertSame(
                'CSRF token validation failed',
                (string) $response->getContent(),
                $case . ' must be refused on the token, as it was before the origin check existed',
            );
        }
    }

    /**
     * `Referer` is the fallback for the clients that omit `Origin` on a POST.
     */
    #[Test]
    public function theRefererIsTheFallbackWhenTheOriginIsAbsent(): void
    {
        $allowed = $this->dispatch($this->post(
            ['host' => 'example.test', 'referer' => 'https://example.test/some/page'],
        ));

        self::assertSame('reached', (string) $allowed->getContent());

        $refused = $this->dispatch($this->post(
            ['host' => 'example.test', 'referer' => 'https://evil.test/attack'],
        ));

        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('Cross-site request rejected', (string) $refused->getContent());
    }

    /**
     * A page on the same host posting over a different scheme or port is
     * same-site: only the host decides, which is also what makes a dev server
     * on `:8000` work (the `Host` header carries the port, the origin is
     * compared without it).
     */
    #[Test]
    public function aSameHostWithADifferentSchemeOrPortIsNotCrossSite(): void
    {
        foreach (['http://example.test', 'https://example.test:8443', 'http://example.test:8000/form'] as $value) {
            $response = $this->dispatch($this->post(['host' => 'example.test', 'origin' => $value]));

            self::assertSame('reached', (string) $response->getContent(), $value . ' must be same-site');
        }
    }

    /**
     * No `Origin` and no `Referer` is no evidence: the token remains the whole
     * check, so HTTP clients keep working.
     */
    #[Test]
    public function aRequestWithNeitherHeaderIsJudgedOnItsTokenAlone(): void
    {
        $response = $this->dispatch($this->post(['host' => 'example.test']));

        self::assertSame('reached', (string) $response->getContent());
    }

    /**
     * `Origin: null` — a sandboxed or opaque document. A browser never sends it
     * for an ordinary on-site form and it cannot be verified as this site, so
     * it fails closed. Same for a value that does not parse as a URL.
     */
    #[Test]
    public function anUnverifiableOriginIsRefused(): void
    {
        foreach (['null', 'NULL', 'not a url'] as $value) {
            $response = $this->dispatch($this->post(['host' => 'example.test', 'origin' => $value]));

            self::assertSame(403, $response->getStatusCode(), $value . ' must not be treated as same-site');
            self::assertSame('Cross-site request rejected', (string) $response->getContent());
        }
    }

    /**
     * A sibling subdomain is another host: the session cookie is not scoped to
     * the parent by default, and a compromised sibling must not be able to
     * drive this one.
     */
    #[Test]
    public function aSubdomainCountsAsAnotherHost(): void
    {
        $response = $this->dispatch($this->post(
            ['host' => 'example.test', 'origin' => 'https://app.example.test'],
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * The forged-Host case, which is why the site's own host goes through the
     * `TRUSTED_HOSTS` allowlist instead of being taken from the request.
     */
    #[Test]
    public function aForgedHostCannotMakeACrossSiteRequestLookSameSite(): void
    {
        putenv('TRUSTED_HOSTS=example.test');

        $response = $this->dispatch($this->post(
            ['host' => 'evil.test', 'origin' => 'https://evil.test'],
        ));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Cross-site request rejected', (string) $response->getContent());

        // And the honest request still passes with the allowlist in place.
        $allowed = $this->dispatch($this->post(
            ['host' => 'example.test', 'origin' => 'https://example.test:8443'],
        ));

        self::assertSame('reached', (string) $allowed->getContent());
    }

    /**
     * Only unsafe methods are cross-checked and token-checked — a cross-site
     * GET is a link, not a state change, and this middleware has never claimed
     * otherwise.
     */
    #[Test]
    public function aCrossSiteGetIsNotThisMiddlewaresBusiness(): void
    {
        $request = Request::create(
            'GET',
            '/blog',
            [],
            [],
            [],
            ['host' => 'example.test', 'origin' => 'https://evil.test'],
        );

        $response = $this->dispatch($request);

        self::assertSame('reached', (string) $response->getContent());
    }

    /**
     * The HEADER form of the token (`X-CSRF-Token`, what the admin layout's
     * HTMX script sends) takes the same path as the body field.
     */
    #[Test]
    public function theTokenHeaderTakesTheSamePathAsTheField(): void
    {
        $request = Request::create(
            'POST',
            '/mark/users/create',
            [],
            [],
            [],
            ['host' => 'example.test', 'origin' => 'https://example.test', 'x-csrf-token' => self::TOKEN],
        );

        self::assertSame('reached', (string) $this->dispatch($request)->getContent());
    }

    private function dispatch(Request $request): Response
    {
        return (new CsrfMiddleware())->handle(
            $request,
            static fn (): Response => Response::html('reached'),
        );
    }

    /**
     * A POST to a page on `example.test`, with the valid token in the body
     * unless the case supplies its own.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    private function post(array $headers, array $body = ['_token' => self::TOKEN]): Request
    {
        return Request::create('POST', '/mark/users/create', [], $body, [], $headers);
    }
}
