<?php

declare(strict_types = 1);

/*
 * HTTP composition root — the whole web application, assembled in one place.
 *
 * `public/index.php` is the front controller: it requires this file and calls
 * `run_http_application()`. The wiring lives here rather than in the entry
 * point because an entry point that also composes the pipeline is a file every
 * new SAPI copies — and the copies drift.
 *
 * The layers, in boot order:
 *
 *   1. early error logging — before `init.php` can install its handlers;
 *   2. `init.php` — autoloaders, environment, error/session setup, timezone;
 *   3. `composition.php` — the module pairings discovery cannot express;
 *   4. `http_kernel()` — migrations, discovery, middleware pipeline;
 *   5. `run_http_application()` — dispatch the request, send it, dispose.
 *
 * The kernel discovers its own middleware (security headers, CSRF) through
 * `#[AsMiddleware]`, and the admin gate through `#[AsAuthProvider]`
 * (`AuthProviderRegistry`), so no call is needed for those and no module class
 * is named here for auth.
 *
 * Rate limiting takes a constructor argument (`RateLimitMiddleware` a limiter
 * factory), so it is added explicitly: present when its module is installed,
 * inert when it is not. Each stage logs exactly one line ([rate] … / [auth] …),
 * so a deployment can tell "not installed" from "broken" without reading code.
 * In a kernel-only tree the guard fails and the registry finds no provider,
 * nothing is wired, and /mark answers 404 — never an unguarded 200.
 *
 * A line naming a module here is a module dependency the kernel cannot have.
 * If one is needed it must be behind `class_exists`; if the surface itself
 * needs a module, the surface belongs to a module (docs/kernel-status.md lists
 * what moved and why).
 *
 * The one exception is a PAIRING, not a dependency: two installed modules that
 * must not know about each other. It lives in `src/composition.php` and is
 * CALLED from here, because a pairing declared in the web process existed only
 * in that process.
 */

use Modules\Core\Application\Services\AuthProviderRegistry;
use Modules\Core\Application\Services\DiscoveryCache;
use Modules\Core\Application\Services\DisposalRegistry;
use Modules\Core\Application\Services\PageCacheService;
use Modules\Core\Domain\Contracts\SecurityEventSink;
use Modules\Core\Infrastructure\Bootstrap\Bootstrapper;
use Modules\Core\Infrastructure\Env;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Middleware\AuthMiddleware;
use Modules\Core\Interfaces\HTTP\Middleware\MiddlewareRegistry;
use Modules\Core\Interfaces\HTTP\Middleware\PageCacheMiddleware;
use Modules\Core\Interfaces\HTTP\Middleware\RateLimitMiddleware;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Security\Application\Services\RateLimiter as SecurityRateLimiter;

// EARLY ERROR LOGGING — capture crashes before bootstrap runs. Writes to
// storage/logs/ (created below if missing).
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
$_earlyLogDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($_earlyLogDir)) {
    @mkdir($_earlyLogDir, 0o755, true);
}
if (is_dir($_earlyLogDir)) {
    // This runs before any framework exists, and PHP offers no other way to say
    // where a fatal before bootstrap is written: the three values are the
    // bootstrap's own configuration, not a runtime override.
    // @mago-expect lint:no-ini-set
    ini_set('log_errors', '1');
    // @mago-expect lint:no-ini-set
    ini_set('error_log', $_earlyLogDir . '/php-error.log');
    // @mago-expect lint:no-ini-set
    ini_set('display_errors', '0');
}
unset($_earlyLogDir);

// ============================================================================
// BOOTSTRAP — autoloaders (Composer + PSR-4 fallback), environment,
// error/exception handlers, session, timezone, module composition
// ============================================================================

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/composition.php';

/**
 * Build the kernel: migrations, discovery and the one pipeline every request
 * runs through.
 *
 * Order IS the pipeline: the kernel runs the list front to back, so rate
 * limiting is added before auth and therefore sits outside it — a flood is
 * counted before a session is looked at — and both sit inside the discovered
 * headers/CSRF.
 */
function http_kernel(): Kernel
{
    // Migrations — every module that ships an #[AsMigrator] runs it here,
    // idempotently. With no feature module installed this creates the kernel's
    // own tables and nothing else.
    (new Bootstrapper())->boot();

    // OUTBOX HANDLERS — the one pairing discovery cannot express. It is
    // declared in `src/composition.php`, not here, and this CALLS it rather
    // than repeating it. It used to live inline in the web entry point, which
    // meant it existed in exactly one process — the one serving HTTP — while
    // every CLI boot (`bin/cms`, a worker) drained an empty handler map and
    // parked each message as `No handler registered for event type` with the
    // HTTP responses still looking perfectly healthy (Boot 16). The pairing is
    // the same line of code either way; what changed is that it now has ONE
    // home, which the worker shares.
    wire_module_handlers();

    // ROUTER — scan module action directories for #[Route] attributes, through
    // the fingerprint-validated discovery cache when it is fresh. Any cache
    // failure degrades to a direct scan: a corrupt cache costs one scan, never
    // a 500 (RFC-1).
    $actionDirs = [
        APP_BASE_PATH . '/src/Modules',
    ];

    try {
        $discovery = DiscoveryCache::fromAppPaths();
        $payload = $discovery->build(
            static fn (): array => (new Router($actionDirs))->toEntries(),
            static fn (): array => MiddlewareRegistry::discoveredClasses(),
        );
        $router = Router::fromEntries($actionDirs, $payload['routes']);
        MiddlewareRegistry::prime($payload['middleware']);
    } catch (\Throwable $e) {
        error_log('[discovery] cache fallback to direct scan: ' . $e->getMessage());
        MiddlewareRegistry::reset();
        $router = new Router($actionDirs);
    }

    $kernel = new Kernel($router);

    // WIRING — the pipeline stages auto-discovery cannot construct.

    // The security-event sink: the kernel's own `SecurityEventSink` port, which
    // the Outbox module implements. The class name is a STRING on purpose, the
    // same rule the limiter below keeps: a kernel-only checkout has no
    // `Modules\Outbox` on disk, and a class written in a TYPE position here
    // (the `new` itself, or a return annotation) is resolved by static analysis
    // whether or not the guard would protect it. PHPStan proved that on the
    // first CI run of this wiring.
    //
    // The proof that the built object really is the port is the `instanceof`
    // inside `$asSink`, and it has to happen where the value is typed `object`:
    // checked on the `new` result directly, static analysis already knows the
    // module class implements the port and calls the test redundant — which is
    // only true in a tree that HAS the module. Widened to `object`, the same
    // line is a real check in both shapes, and neither shape needs a suppression.
    $sinkClass = 'Modules\\Outbox\\Application\\Services\\OutboxSecurityEventSink';
    $asSink = static fn (object $candidate): ?SecurityEventSink => $candidate instanceof SecurityEventSink ? $candidate : null;
    $securityEvents = class_exists($sinkClass) ? $asSink(new $sinkClass()) : null;

    // The factory yields an `object` because that is RateLimitMiddleware's own
    // declared shape (`\Closure(): object`) — its product is verified at
    // runtime by isLimiter(), never trusted at compile time. Nothing about the
    // module's class appears in a TYPE position here on purpose: a class_exists
    // guard protects the `new` below, but a return-type annotation is resolved
    // regardless of any guard, which is exactly what a kernel-only checkout
    // (no Modules\Security on disk) caught.
    if (class_exists(SecurityRateLimiter::class)) {
        $kernel->addMiddleware(new RateLimitMiddleware(
            static fn (): object => new SecurityRateLimiter(
                DatabaseConnectionManager::instance()->connection('users'),
            ),
            sink: $securityEvents,
        ));
        error_log('[rate] wired RateLimit');
    } else {
        error_log('[rate] absent — no Security module');
    }

    // The page cache: an anonymous public GET is answered from
    // storage/cache/pages while its entry is fresh, so the theme render is paid
    // once per TTL instead of once per visitor. Core-owned — no module class to
    // guard — so it is wired unconditionally, and wired AFTER the limiter on
    // purpose: the rate limit is counted before a cache hit is returned, not
    // after.
    //
    // It is added as the pipeline is built rather than found by discovery
    // because it needs a constructed `PageCacheService`, and the discovered
    // stages are the ones with no wiring (SecurityHeaders, Csrf, Debugbar). The
    // signed-in and admin bypasses are the service's own policy, so this file
    // states no rule of its own here.
    //
    // PAGE_CACHE (default: OFF) is the one lever, read from the environment so
    // a deployment can turn it on in `.env` without a rebuild. It is opt-in on
    // purpose: serving a page a visitor did not just render is a deployment's
    // decision, not a kernel default, and a cached HTML copy outlives the
    // request that produced it.
    //
    // The lever exists because "does the cache cause the render delay?" is not
    // answerable by reading code: measured on responsive.sk, the cache HIT
    // served TTFB in 0 ms while Lighthouse reported an LCP element render
    // delay of 2500 ms, and every candidate the delay could come from is ruled
    // out on its own terms — `font-display: swap` on all seven @font-face
    // rules, the 47 KB critical CSS inlined and resolving, 61–158 KB hero
    // images, and Total Blocking Time at 0 ms. Speed Index measures visual
    // completeness from screenshots, which a server-side HTML cache does not
    // touch, so the switch makes the claim measurable on the deployment
    // instead of argued. Turning it ON buys the 0 ms HIT; leaving it off pays
    // the render on every request.
    if (Env::bool('PAGE_CACHE', false)) {
        $kernel->addMiddleware(new PageCacheMiddleware(
            new PageCacheService(APP_BASE_PATH . '/' . PageCacheService::DEFAULT_DIR),
        ));
        error_log('[cache] wired PageCache (anonymous HTML, 5 min TTL, admin and signed-in bypass)');
    } else {
        error_log('[cache] PageCache OFF (PAGE_CACHE not enabled)');
    }

    // The admin gate: the provider is DISCOVERED (`#[AsAuthProvider]`), so this
    // file names no module class for auth at all. `first()` returns null in a
    // tree with no provider module, and null wires nothing — absence removes the
    // middleware, not the request (the port's own rule), which is what keeps
    // /mark a 404 kernel-only instead of an unguarded 200. The kernel-level
    // tests ask the same registry the same question, so the pipeline the suite
    // proves and the pipeline this file runs cannot drift apart.
    $authProvider = AuthProviderRegistry::first();

    if (null !== $authProvider) {
        $kernel->addMiddleware(new AuthMiddleware($authProvider, $securityEvents));
        error_log('[auth] wired ' . $authProvider::class);
    } else {
        error_log('[auth] absent — no provider module');
    }

    return $kernel;
}

/**
 * Handle the incoming request and send the response.
 */
function run_http_application(): void
{
    $response = http_kernel()->handle(Request::createFromGlobals());
    $response->send();

    // Disposal — run cleanup callbacks (best-effort, all run even if one throws)
    DisposalRegistry::disposeAll();
}
