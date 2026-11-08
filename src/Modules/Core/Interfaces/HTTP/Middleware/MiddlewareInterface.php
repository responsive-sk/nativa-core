<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;

/**
 * Middleware interface — each middleware wraps the request/response cycle.
 */
interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
