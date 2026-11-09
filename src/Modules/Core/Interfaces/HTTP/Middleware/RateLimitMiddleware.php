<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Domain\Contracts\PageCacheReader;
use Modules\Core\Domain\Contracts\RateLimiter;
use Modules\Core\Domain\Contracts\SecurityEventSink;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;

/**
 * RateLimitMiddleware — general per-IP request throttling.
 *
 * Applies a 60 requests/minute limit per client IP, returning 429 when
 * exceeded. Loopback addresses (127.0.0.1, ::1, localhost) are exempt so
 * local development and testing are not throttled.
 *
 * Three guards keep this file kernel-owned, one per edge it used to carry:
 *
 * - LOCALE. The cache key is per locale; without Locales it falls back to
 *   the mapper's default (itself the shell provider's answer with a kernel
 *   fallback) instead of loading a missing translation store.
 * - OUTBOX. A throttled request is a security event, and the kernel raises it
 *   through its own `SecurityEventSink` port: the module that owns the outbox
 *   implements the port and the composition root wires it, so this file names
 *   no module, imports none, and a tree without one simply gets no sink — the
 *   request is answered either way, never fataled. The inline-FQCN-with-
 *   `class_exists` shape this replaced kept `KernelCouplingTest`'s ledger empty
 *   while `mago guard` still counted the dependency, which is why the edge is
 *   inverted now instead of hidden.
 * - LIMITER. The factory product is duck-typed, not type-hinted: the limiter
 *   itself is a kernel obligation, so the middleware degrades (passes the
 *   request through un-limited) rather than disappearing when the factory
 *   yields nothing usable. The expected shape is `isAllowed(string, int,
 *   int): bool` + `getRetryAfter(string): int`.
 *
 * When a PageCacheReader is provided, blocked requests check the cache
 * for a cached HTML response. Cache hits are served with HTTP 429,
 * Retry-After header, and X-Cache: HIT — giving rate-limited clients
 * a functional page instead of a bare error string.
 */
final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    private const array LOOPBACK_IPS = ['127.0.0.1', '::1', 'localhost'];

    private const int GENERAL_LIMIT = 60;

    private const int GENERAL_WINDOW = 60;

    /**
     * @param \Closure(): object   $rateLimiterFactory Yields the limiter candidate.
     *                                                 The product is unchecked until isLimiter() verifies the RateLimiter
     *                                                 shape, so the closure stays `object` — typing it narrower would
     *                                                 turn a junk product (pass-through by contract) into a TypeError.
     * @param PageCacheReader|null $cache              Optional page cache reader for the fallback
     */
    public function __construct(
        private \Closure $rateLimiterFactory,
        private ?PageCacheReader $cache = null,
        private ?SecurityEventSink $sink = null,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->isLoopback($request)) {
            return $next($request);
        }

        $rateLimiter = ($this->rateLimiterFactory)();

        if (!$this->isLimiter($rateLimiter)) {
            return $next($request);
        }

        /** @var RateLimiter $rateLimiter The isLimiter() guard verified the shape. */
        $key = 'rate_limit:' . $request->getClientIp();

        if (!$rateLimiter->isAllowed($key, self::GENERAL_LIMIT, self::GENERAL_WINDOW)) {
            // getRetryAfter() is called HERE, where the @var narrowing above
            // is in scope — not inside blockedResponse(), whose job is to
            // render a number, not to hold the module's limiter type.
            return $this->blockedResponse($rateLimiter->getRetryAfter($key), $key, $request);
        }

        return $next($request);
    }

    /**
     * The factory crossed a module boundary, so its product is verified, not
     * trusted: anything without the two limiter methods degrades to
     * pass-through rather than fataling on the call.
     */
    private function isLimiter(mixed $rateLimiter): bool
    {
        return is_object($rateLimiter)
            && method_exists($rateLimiter, 'isAllowed')
            && method_exists($rateLimiter, 'getRetryAfter');
    }

    /**
     * Record the trip as a security event — only when the outbox is installed.
     *
     * A 429 is a caller being refused, the same category of fact as an
     * authorization denial, and the IP rides along because an investigation
     * starts there. Without the outbox the event is dropped, not the request.
     */
    private function recordTrip(string $key, Request $request): void
    {
        if (null === $this->sink) {
            return;
        }

        try {
            $this->sink->rateLimitTripped($key, $request->getPathInfo(), $request->getClientIp());
        } catch (\Throwable $e) {
            // The 429 is the answer; the event is the trace. A sink that cannot
            // write must not turn a refusal into a 500.
            error_log('[rate] could not record the trip: ' . $e->getMessage());
        }
    }

    /**
     * Build the 429 response — serve cached HTML when available, bare error
     * string otherwise. Every branch carries `Retry-After`: a refusal with no
     * retry time is a dead end for a client that speaks HTTP.
     *
     * `$retryAfter` arrives already resolved by the caller on purpose. This
     * parameter used to be typed with the kernel's `RateLimiter` contract
     * while the factory product is duck-typed (`isLimiter()` verifies two
     * methods, nothing more) — so the first request ever refused through
     * Security's structurally-valid limiter fatalled with a TypeError
     * instead of answering 429. The number crosses; the module's type does
     * not.
     */
    private function blockedResponse(int $retryAfter, string $key, Request $request): Response
    {
        $this->recordTrip($key, $request);

        if (null !== $this->cache) {
            $cacheKey = $this->cache->cacheKeyForRequest($request);
            $cached = $this->cache->get($cacheKey);

            if (null !== $cached) {
                $response = Response::html($cached['content'], 429);
                $response->setHeader('Retry-After', (string) $retryAfter);
                $response->setHeader('X-Cache', 'HIT');

                return $response;
            }
        }

        $response = Response::html('Too Many Requests', 429);
        $response->setHeader('Retry-After', (string) $retryAfter);

        return $response;
    }

    private function isLoopback(Request $request): bool
    {
        return \in_array($request->getClientIp(), self::LOOPBACK_IPS, true);
    }
}
