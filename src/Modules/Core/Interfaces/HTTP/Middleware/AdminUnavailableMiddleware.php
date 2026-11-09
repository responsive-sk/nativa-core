<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ErrorPageRenderer;

/**
 * AdminUnavailableMiddleware — an admin path without an auth provider is a 404.
 *
 * The composition root wires the Auth module's middleware only when its
 * provider registry finds a provider: absence of a provider means there is no
 * admin surface to protect. That rule was only true while the routes were
 * absent too. Measured 2026-11-09 in a tree with `Cardboard`
 * installed and no `Users`: `/mark` was routed, the gate was never wired, and
 * the dashboard answered 200 to a guest — the exact defect
 * `tests/Boot/KernelOnlyBootTest.php` was written to rule out.
 *
 * Making `Cardboard` require `Users` is not the fix: `Users` already requires
 * `Cardboard`, so the closure would be a cycle. The kernel owns the prefix
 * (`AdminRoutePolicy`), so the kernel answers here: a request under the admin
 * prefix without a provider is not a 403 (nothing authenticated it) and not a
 * 500 (nothing failed); it is a surface that does not exist, and the same 404
 * a kernel-only tree already gives.
 *
 * This middleware is added ONLY in the no-provider branch of `src/http.php`.
 * With a provider, the Auth module's middleware answers first and this class
 * never runs.
 */
final readonly class AdminUnavailableMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $path = $request->getPathInfo();

        if (AdminRoutePolicy::isAdminPath($path)) {
            return ErrorPageRenderer::response(
                404,
                'Not Found',
                'The requested page could not be found.',
                $path,
            );
        }

        return $next($request);
    }
}
