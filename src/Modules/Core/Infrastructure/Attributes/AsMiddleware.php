<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure\Attributes;

/**
 * Marks a class as HTTP middleware for auto-discovery.
 *
 * Middleware classes annotated with #[AsMiddleware] are discovered by
 * MiddlewareRegistry and registered into the Kernel pipeline automatically.
 * The priority parameter controls insertion order (lower = earlier in pipeline).
 *
 * @see MiddlewareRegistry
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class AsMiddleware
{
    public function __construct(
        public int $priority = 100,
    ) {
    }
}
