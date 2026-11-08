<?php

declare(strict_types=1);

namespace Modules\Security\Tests;

use Modules\Core\Domain\Contracts\SecurityEventSink;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Middleware\RateLimitMiddleware;
use Modules\Security\Application\Services\RateLimiter;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RateLimitMiddlewareTest — the throttling stage of the pipeline, which had
 * zero importers when the security audit flagged it: the kernel docblock
 * promised "rate limiting" between CSRF and auth and nothing ever added the
 * middleware to a pipeline. Now that `public/index.php` wires it, this file
 * pins the two halves of its contract, with the REAL `RateLimiter` against
 * a real (in-memory) SQLite file so the atomic upsert is exercised rather
 * than mocked:
 *
 * - **60 requests pass, the 61st is refused** — 429 with a `Retry-After`
 *   naming when to come back. The header is the whole point of a 429 to a
 *   client that speaks HTTP: without it the refusal is a dead end.
 * - **Loopback never throttles** — the documented exemption that keeps
 *   local development and this suite itself off the counter. The test
 *   FORGES a non-loopback `REMOTE_ADDR` for the first half; production
 *   behaviour is untouched.
 *
 * A refused request also records a security event, through the kernel's
 * `SecurityEventSink` port. The middleware no longer reaches the Outbox module
 * at all — that edge became a kernel contract (`Modules\Core\Domain\Contracts\
 * SecurityEventSink`, implemented by `OutboxSecurityEventSink`), which is why
 * this file can now hand in a sink of its own. It hands in one that THROWS: the
 * port is a boundary the kernel does not control, so what is asserted is that a
 * recorder which cannot write leaves the 429 and its `Retry-After` intact
 * instead of turning a refusal into a 500. No database, no `DB_OUTBOX` file, and
 * no module closure gained by Security.
 *
 * Like every module test it skips when the module is not installed.
 */
final class RateLimitMiddlewareTest extends TestCase
{
    private const string CLIENT_IP = '203.0.113.7';

    private const int LIMIT = 60;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Security')) {
            $this->markTestSkipped('Security is not installed.');
        }
    }

    /**
     * The measured contract: exactly the limit passes, the next request is
     * refused with a status AND a time to retry — both reported on failure,
     * because "some request failed" tells nobody which one or how far the
     * run got.
     */
    #[Test]
    public function sixtyRequestsPassAndTheSixtyFirstIsRefusedWithARetryAfter(): void
    {
        $middleware = $this->middleware();
        $next = static fn (): Response => Response::html('ok');
        $reached = 0;

        for ($attempt = 1; $attempt <= self::LIMIT; $attempt++) {
            $response = $middleware->handle($this->request(self::CLIENT_IP), $next);
            $reached = $attempt;

            self::assertSame(
                200,
                $response->getStatusCode(),
                sprintf(
                    'request %d of %d from %s was answered %d — the limit must allow exactly %d requests per window',
                    $attempt,
                    self::LIMIT,
                    self::CLIENT_IP,
                    $response->getStatusCode(),
                    self::LIMIT,
                ),
            );
        }

        $blocked = $middleware->handle($this->request(self::CLIENT_IP), $next);

        self::assertSame(
            429,
            $blocked->getStatusCode(),
            sprintf(
                'after %d passing requests from %s the next one was answered %d — the %d request must be refused with 429',
                $reached,
                self::CLIENT_IP,
                $blocked->getStatusCode(),
                self::LIMIT + 1,
            ),
        );

        $retryAfter = $blocked->getHeader('Retry-After');

        self::assertNotNull(
            $retryAfter,
            sprintf(
                'the 429 to %s carried no Retry-After header — a refusal without a retry time is a dead end for a client that speaks HTTP',
                self::CLIENT_IP,
            ),
        );
        self::assertGreaterThan(
            0,
            (int) $retryAfter,
            sprintf('Retry-After must be a positive number of seconds, got %s', $retryAfter),
        );
    }

    /**
     * The documented exemption: the same run from the loopback address —
     * what local development and this suite itself look like — never trips,
     * even past the limit that just refused an external client.
     */
    #[Test]
    public function theLoopbackAddressNeverThrottles(): void
    {
        $middleware = $this->middleware();
        $next = static fn (): Response => Response::html('ok');

        for ($attempt = 1; $attempt <= self::LIMIT + 5; $attempt++) {
            $response = $middleware->handle($this->request('127.0.0.1'), $next);

            self::assertSame(
                200,
                $response->getStatusCode(),
                sprintf(
                    'loopback request %d was answered %d — 127.0.0.1 is exempt from throttling and must pass every time',
                    $attempt,
                    $response->getStatusCode(),
                ),
            );
        }
    }

    /**
     * A fresh limiter per test over an in-memory database — the real class,
     * the real upsert, no shared state between the halves of the contract.
     *
     * The sink throws on purpose; see the class docblock.
     */
    private function middleware(): RateLimitMiddleware
    {
        $limiter = new RateLimiter(new PDO('sqlite::memory:'));

        $sink = new class implements SecurityEventSink {
            public function authorizationDenied(
                string $path,
                string $required,
                string $userId,
                array $roles,
                string $ip,
            ): void {
                throw new \RuntimeException('security event sink unavailable');
            }

            public function rateLimitTripped(string $key, string $scope, string $ip): void
            {
                throw new \RuntimeException('security event sink unavailable');
            }
        };

        return new RateLimitMiddleware(static fn (): RateLimiter => $limiter, null, $sink);
    }

    /**
     * A request whose client address is forged — `Request::create()` defaults
     * to loopback, so without this the first test would measure the
     * exemption instead of the limit.
     */
    private function request(string $ip): Request
    {
        return Request::create('GET', '/docs', [], [], ['REMOTE_ADDR' => $ip]);
    }
}
