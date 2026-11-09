<?php

declare(strict_types = 1);

namespace Modules\Core\Domain\Contracts;

use Modules\Core\Infrastructure\Http\Request;

/**
 * PageCacheReader — the kernel's read-only view of the page cache.
 *
 * The cache is a module surface, but RateLimitMiddleware needs its entries for
 * the 429 fallback. This port keeps the kernel's dependency pointing at the
 * kernel: the PageCache module implements it, and a tree without that module
 * passes null and answers the bare 429 instead.
 */
interface PageCacheReader
{
    /**
     * @return array{content: string, statusCode: int, headers: array<string, string>}|null
     */
    public function get(string $key): ?array;

    public function cacheKeyForRequest(Request $request): string;
}
