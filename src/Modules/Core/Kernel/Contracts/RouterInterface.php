<?php

declare(strict_types = 1);

namespace Modules\Core\Kernel\Contracts;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Attributes\Route;

/**
 * RouterInterface — what the HTTP kernel needs from a router: match a request
 * to an action, or return null for a 404.
 */
interface RouterInterface
{
    /**
     * @return array{route: Route, action: class-string, params: array<string, string>, methodNotAllowed?: bool, redirect?: string}|null
     */
    public function match(Request $request): ?array;

    /**
     * Build the URL a named route declares.
     *
     * Actions address navigation by NAME, not by retyping the path: a route
     * whose path moves keeps every redirect and cached link working, and a
     * typo is an exception ("Unknown route name") instead of a 404 nobody
     * notices until a deployment.
     *
     * @param array<string, string|int> $params Placeholder values
     */
    public function url(string $name, array $params = []): string;
}
