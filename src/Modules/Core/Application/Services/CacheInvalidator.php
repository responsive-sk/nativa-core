<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services;

/**
 * CacheInvalidator — drops the page-cache entries a write made stale.
 *
 * Contract: after ANY admin write action completes, the page cache must not
 * serve content that predates the save. Every action that appends to the
 * immutable audit log invalidates beside it; bulk actions invalidate once
 * after their loop. The rate-limit 429 fallback therefore never serves
 * pre-edit content for longer than the request that wrote it.
 *
 * Selective invalidation is the default: `invalidate('/blog', '/authors', '/')`
 * deletes only the pages under those paths, because `PageCacheService::set()`
 * records the request path beside the entry. An article edit therefore costs
 * the blog its render and leaves every unrelated page (`/kontakt`, `/docs/…`)
 * cached, instead of making the whole site re-render on the next visitor.
 *
 * `flush()` stays for writes that genuinely change every page — a module
 * install/remove, settings that alter the shell everywhere, media or locales
 * that every render reads — where a path list could only be an incomplete
 * guess. A caller that cannot name the affected paths must flush, never
 * invalidate a subset it guessed.
 *
 * Lives in Core (shared kernel) so every module can call it without
 * cross-module imports; static + stateless so #[Route] action classes
 * (instantiated by the router with no arguments) need no wiring changes.
 */
final class CacheInvalidator
{
    /**
     * Delete every cached page entry. Returns the number of files deleted.
     */
    public static function flush(): int
    {
        return self::service()->flush();
    }

    /**
     * Delete the cached pages rendered for the given request paths.
     *
     * Each path is a segment-boundary prefix: '/blog' covers `/blog` and
     * `/blog/{slug}`, '/' covers the homepage only. Returns the number of
     * files deleted.
     */
    public static function invalidate(string ...$paths): int
    {
        return self::service()->invalidatePaths(...$paths);
    }

    private static function service(): PageCacheService
    {
        return new PageCacheService(
            APP_BASE_PATH . '/' . PageCacheService::DEFAULT_DIR,
        );
    }
}
