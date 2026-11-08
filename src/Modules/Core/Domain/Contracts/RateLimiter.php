<?php

declare(strict_types = 1);

namespace Modules\Core\Domain\Contracts;

/**
 * RateLimiter — the structural contract a module's limiter satisfies.
 *
 * `RateLimitMiddleware` keeps its runtime shape check (`isLimiter()`:
 * `method_exists` on both methods, degrading to pass-through when the
 * factory product is junk) because the factory crosses a module boundary
 * and its product is verified, not trusted. This interface is what the
 * check verifies, and what PHPStan narrows to after it — the two stay in
 * sync by construction: a method added here must be added to the guard.
 *
 * A module implements these two methods structurally (like Security's
 * `RateLimiter`); nothing requires it to implement this interface, because
 * the kernel never names the module's class. The direction stays
 * module → kernel even when the shape is kernel-defined.
 */
interface RateLimiter
{
    public function isAllowed(string $key, int $maxRequests, int $windowSeconds): bool;

    public function getRetryAfter(string $key): int;
}
