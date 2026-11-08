<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Attributes;

/**
 * Route attribute — marks an action class as a route handler.
 *
 * Supports:
 * - path: URL pattern, e.g. '/mark/users/{id}'
 * - method: HTTP method (GET|POST|PUT|DELETE|PATCH)
 * - name: optional stable route name for URL generation
 * - handlerMethod: optional method name when one class exposes multiple routes
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Route
{
    public function __construct(
        public string $path,
        public string $method = 'GET',
        public ?string $name = null,
        public ?string $handlerMethod = null,
    ) {
    }
}
