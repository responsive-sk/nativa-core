<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP;

use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Infrastructure\Env;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\Middleware\MiddlewareInterface;
use Modules\Core\Interfaces\HTTP\Middleware\MiddlewareRegistry;
use Modules\Core\Interfaces\HTTP\Middleware\SecurityHeadersMiddleware;
use Modules\Core\Kernel\Contracts\ErrorRendererInterface;
use Modules\Core\Kernel\Contracts\RouterInterface;

/**
 * HTTP Kernel — middleware pipeline + route dispatch.
 *
 * Pipeline order: security headers → session → CSRF → rate limiting → auth → dispatch.
 * Security headers are registered here; the remaining middleware is added by
 * their owning modules (Security/Users) via addMiddleware() during wiring.
 */
final class Kernel
{
    /** @var list<MiddlewareInterface> */
    private array $middleware = [];

    /**
     * @param bool $autoDiscoverMiddleware When true, auto-discovers #[AsMiddleware] classes
     */
    public function __construct(
        private readonly RouterInterface $router,
        bool $autoDiscoverMiddleware = true,
        private readonly ErrorRendererInterface $errors = new ErrorPageRenderer(),
    ) {
        if ($autoDiscoverMiddleware) {
            // Auto-discover #[AsMiddleware] classes (SecurityHeaders, Csrf, Debugbar)
            foreach (MiddlewareRegistry::all() as $middleware) {
                $this->middleware[] = $middleware;
            }
        } else {
            // Manual mode: only SecurityHeaders (for tests)
            $this->middleware[] = new SecurityHeadersMiddleware();
        }
    }

    public function addMiddleware(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function handle(Request $request): Response
    {
        $pipeline = $this->buildPipeline();

        return $pipeline($request);
    }

    /**
     * Build the middleware chain as nested closures (onion model).
     *
     * @return callable(Request): Response
     */
    private function buildPipeline(): callable
    {
        $next = fn (Request $request): Response => $this->dispatch($request);

        foreach (array_reverse($this->middleware) as $middleware) {
            $next = static fn (Request $request): Response => $middleware->handle($request, $next);
        }

        return $next;
    }

    private function dispatch(Request $request): Response
    {
        try {
            return $this->dispatchMatched($request);
        } catch (\Throwable $e) {
            return $this->renderThrowable($e, $request->getPathInfo());
        }
    }

    /**
     * Route the request and run the matched action — 404 on no match, then
     * trailing-slash redirect, method-not-allowed, and finally the action.
     */
    private function dispatchMatched(Request $request): Response
    {
        // The admin surface answers under its configured prefix, optional via
        // `ADMIN_PATH` (default `/mark`). Canonicalizing HERE means the router
        // only ever sees the internal spelling the route attributes declare,
        // the discovery cache stays valid, and no route declaration has to
        // become dynamic. The middleware pipeline ran earlier, so
        // AuthMiddleware still saw the URL the visitor typed and answers
        // through AdminRoutePolicy, which accepts both spellings.
        $publicPath = $request->getPathInfo();
        $request->setPathInfo(AdminRoutePolicy::canonicalPath($publicPath));

        $match = $this->router->match($request);

        if (null === $match) {
            return $this->renderError(404, 'Not Found', 'The requested page could not be found.', $publicPath);
        }

        $redirect = $this->resolveRedirect($match);
        if (null !== $redirect) {
            return $redirect;
        }

        $methodError = $this->resolveMethodNotAllowed($match, $publicPath);
        if (null !== $methodError) {
            return $methodError;
        }

        $request->setRouteParams($match['params']);

        return $this->runAction($request, $match);
    }

    /**
     * Instantiate the matched action, invoke its handler and normalize the
     * result to a Response.
     *
     * @param array{route: Route, action: class-string, params: array<string, string>, methodNotAllowed?: bool, redirect?: string} $match
     */
    private function runAction(Request $request, array $match): Response
    {
        $actionClass = $match['action'];
        $action = $this->instantiateAction($actionClass);

        $handlerMethod = $match['route']->handlerMethod ?? 'handle';
        if (!method_exists($action, $handlerMethod)) {
            throw new \RuntimeException(sprintf(
                'Action %s has no %s() method for route %s',
                $actionClass,
                $handlerMethod,
                $match['route']->path,
            ));
        }

        return $this->normalizeResult($action->{$handlerMethod}($request));
    }

    /**
     * Instantiate the action, injecting the Router where it asks for one.
     *
     * Actions navigate by route NAME (task 2.8), which they can only do if
     * they hold the router. The parameter is optional in the action's own
     * signature — a test can build one by hand — and the Kernel fills it by
     * TYPE, the same way it recognises `RouterInterface` here. Every other
     * optional parameter keeps its default (`?CloudinaryApi`, `?DocsCatalog`),
     * so an action without a router is constructed exactly as before.
     *
     * @param class-string $actionClass
     */
    private function instantiateAction(string $actionClass): object
    {
        $reflection = new \ReflectionClass($actionClass);
        $constructor = $reflection->getConstructor();

        if (null === $constructor || 0 === $constructor->getNumberOfParameters()) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType
                && !$type->isBuiltin()
                && is_a($this->router, $type->getName())
            ) {
                $arguments[] = $this->router;

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            throw new \RuntimeException(sprintf(
                'Action %s needs a %s the Kernel cannot supply.',
                $actionClass,
                $type instanceof \ReflectionNamedType ? $type->getName() : 'dependency',
            ));
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * Actions may return a Response, a JSON-serializable array or a scalar
     * rendered as HTML. Anything else is a programming error.
     */
    private function normalizeResult(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        if (is_scalar($result)) {
            return Response::html((string) $result);
        }

        throw new \RuntimeException(sprintf(
            'Action handler returned an unsupported type: %s',
            get_debug_type($result),
        ));
    }

    /**
     * 500 path — debug mode leaks the message + trace; production logs the
     * exception and answers with a generic error page.
     */
    private function renderThrowable(\Throwable $e, ?string $path = null): Response
    {
        if (Env::bool('APP_DEBUG', false)) {
            return $this->renderError(500, 'Server Error', $e->getMessage() . "\n\n" . $e->getTraceAsString(), $path);
        }

        error_log(sprintf(
            '[KERNEL] %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));

        return $this->renderError(500, 'Server Error', 'An unexpected error occurred.', $path);
    }

    /**
     * Trailing-slash canonicalization — the router matched the slash-stripped
     * path and marked the hit with a 'redirect' marker (301, query preserved).
     *
     * @param array{route: Route, action: class-string, params: array<string, string>, methodNotAllowed?: bool, redirect?: string} $match
     */
    private function resolveRedirect(array $match): ?Response
    {
        if (isset($match['redirect'])) {
            // The router canonicalized an INTERNAL path; send the visitor back
            // to the spelling they used (the configured prefix on a
            // deployment that set `ADMIN_PATH`).
            return Response::redirect(AdminRoutePolicy::publicPath($match['redirect']), 301);
        }

        return null;
    }

    /**
     * Method mismatch — the path matched a route but with the wrong HTTP
     * method. Answer with 405 + an Allow header naming the route's method.
     *
     * @param array{route: Route, action: class-string, params: array<string, string>, methodNotAllowed?: bool, redirect?: string} $match
     */
    private function resolveMethodNotAllowed(array $match, ?string $path = null): ?Response
    {
        if (isset($match['methodNotAllowed']) && true === $match['methodNotAllowed']) {
            $response = $this->renderError(405, 'Method Not Allowed', 'This method is not allowed for the requested resource.', $path);
            $response->setHeader('Allow', $match['route']->method);

            return $response;
        }

        return null;
    }

    private function renderError(int $status, string $title, string $detail, ?string $path = null): Response
    {
        return $this->errors->renderError($status, $title, $detail, $path);
    }
}
