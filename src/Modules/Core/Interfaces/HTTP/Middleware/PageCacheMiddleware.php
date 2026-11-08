<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Application\Services\PageCacheService;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;

/**
 * PageCacheMiddleware — serves an anonymous public page from the file cache
 * when a fresh entry exists, and stores the render when one does not.
 *
 * It is the whole read path for ordinary traffic: a cacheable GET that finds
 * an entry returns it without reaching the router, so the theme, the shell
 * providers and the per-module queries behind a page are paid once per TTL
 * instead of once per visitor. Measured on the deployed site (2026-10-18), a
 * render cost 1.9–2.4 s of a ~2.0 s TTFB — the cache is the difference between
 * that and a file read, and it is why the cache stopped being write-only.
 *
 * Three contracts make that safe, and each is pinned by a test beside the
 * service:
 *
 *  - `PageCacheService::isCacheableRequest()` decides before the render whether
 *    this request may be served AND stored (same predicate, one place): not the
 *    admin surface, not a bypass path (`/docs` — a guide IS the file), no
 *    session-scoped query, and never a signed-in visitor. The last one is the
 *    cross-visitor rule: the cache key is method + path + query + locale, so an
 *    editor's render would hand an anonymous reader the editor's chrome.
 *  - The cached body keeps `__CSP_NONCE_PLACEHOLDER__`; SecurityHeadersMiddleware
 *    runs OUTSIDE this middleware (it is auto-discovered, this one is wired into
 *    the pipeline) and substitutes a fresh nonce on the way out, so a served
 *    entry is never pinned to the nonce of the response that produced it.
 *  - Staleness is bounded by the entry TTL (300 s) and by `CacheInvalidator`,
 *    which the content actions call on every write — the reason a 5-minute
 *    window does not turn into a 5-minute lie after an edit.
 *
 * A fourth rule is not visible here because it is inside `isCacheable()`: a
 * body that printed the visitor's CSRF token is never stored. A form's token is
 * per session, so a cached form would hand the next visitor a credential that
 * fails their own submission — the `403` on the first guest comment, which is
 * how the rule was found. Form-bearing pages are therefore not cached; the
 * store path is the only one that needs the rule, because nothing is served
 * that was not stored.
 *
 * Runs after RateLimitMiddleware in the pipeline so only requests that passed
 * the rate limit are served and cached — the limiter stays in front of the
 * cache rather than behind it.
 *
 * The cache key is per locale; without Locales it falls back to the mapper's
 * default (itself the shell provider's answer with a kernel fallback) rather
 * than loading a missing translation store to ask which locale is current.
 */
final readonly class PageCacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private PageCacheService $cache,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->cache->isCacheableRequest($request)) {
            $key = $this->cache->cacheKeyForRequest($request);
            $cached = $this->cache->get($key);

            if (null !== $cached) {
                $response = Response::html($cached['content'], $cached['statusCode']);

                foreach ($cached['headers'] as $name => $value) {
                    $response->setHeader($name, $value);
                }

                $response->setHeader('X-Cache', 'HIT');

                return $response;
            }
        }

        $response = $next($request);

        if ($this->cache->isCacheable($request, $response)) {
            $key = $this->cache->cacheKeyForRequest($request);
            // The request path is stored beside the entry so a later write can
            // invalidate `/blog` without flushing every unrelated page.
            $this->cache->set($key, $response, path: $request->getPathInfo());
        }

        return $response;
    }
}
