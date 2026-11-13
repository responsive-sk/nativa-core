<?php

declare(strict_types = 1);

namespace Modules\Locales\Interfaces\HTTP\Middleware;

use Modules\Core\Infrastructure\Attributes\AsMiddleware;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Middleware\MiddlewareInterface;
use Modules\Locales\Application\Services\LocaleService;
use Modules\Locales\Domain\Model\Locale;
use Modules\Locales\Interfaces\HTTP\LocalesServices;

/**
 * LocaleMiddleware — detects and sets the active locale on every request.
 *
 * Detection priority:
 *   0. URL prefix (/en/..., /sk/...) — highest priority, stripped before routing
 *   1. X-Locale header (API clients, AJAX)
 *   2. locale cookie
 *   3. locale session value
 *   4. Default from LocaleService (settings.locale → built-in sk_SK)
 *
 * The Accept-Language header is intentionally not used: the site default
 * always takes precedence over browser preference; visitors choose a locale
 * explicitly. The resolved locale is stored as request attribute "locale"
 * and on LocaleService::set() for templates/layouts.
 *
 * Registered before CSRF in the kernel pipeline so the URL prefix is
 * stripped before routing (and before CSRF path matching) — `#[AsMiddleware]`
 * at priority 10, between the security headers (0) and CSRF (20).
 *
 * ## Why the attribute IS the wiring
 *
 * Measured 2026-10-17: every prefixed URL answered 404 — `/sk_SK/blog` (a link
 * the locale switcher renders itself), `/sk/docs` (linked from the `/docs`
 * index) and `/en/blog` alike. This class reached the pipeline NOWHERE. The
 * registry scans every module's `Interfaces/HTTP/Middleware` directory but
 * accepts only `#[AsMiddleware]` classes with a ZERO-ARG constructor, and this one had
 * neither the attribute nor a way to build itself: "not annotated" plus "needs a
 * LocaleService" is a documented prefix contract that nothing served. It carries
 * the attribute now and defaults its own service through the module's factory,
 * so it joins the pipeline by existing like every other discovered stage — and a
 * tree without this module has no prefix, which is the right answer for a tree
 * with no locales either.
 */
#[AsMiddleware(priority: 10)]
final readonly class LocaleMiddleware implements MiddlewareInterface
{
    /**
     * The service is OPTIONAL so discovery can construct this class: the
     * registry builds middleware with `new $class()` and skips (with a log line)
     * anything whose constructor demands an argument. The module's own factory is
     * the default, which is also what a hand-written wiring wants.
     */
    public function __construct(private ?LocaleService $localeService = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        // explicitLocale() is resolved ONCE: detectFromUrl() rewrites the
        // request path (strips the /sk|/en prefix), so calling it twice
        // would make the second call see the already-stripped path and
        // report the locale as not explicit. The blog relies on the flag to
        // tell /blog (default) apart from /en/blog (explicit choice).
        $localeService = $this->localeService ?? LocalesServices::locale();

        $explicit = $this->explicitLocale($request);
        $locale = $explicit ?? $localeService->default();

        $request->setAttribute('locale', $locale->code());
        // Whether the locale was explicitly selected (URL prefix, header,
        // cookie, session) rather than resolved from the site default. The
        // blog uses this to distinguish "default locale page" (/blog) from
        // an explicit language choice (/en/blog): blog content lives under
        // the default locale's directory (sk), translations under /en/...
        $request->setAttribute('locale_explicit', $explicit !== null);
        $localeService->set($locale);

        return $next($request);
    }

    /**
     * Explicitly selected locale (URL prefix, header, cookie, session) or
     * null when nothing was chosen and the site default applies.
     */
    private function explicitLocale(Request $request): ?Locale
    {
        return $this->detectFromUrl($request)
            ?? $this->tryParse($request->header('X-Locale'))
            ?? $this->tryParse($this->cookieLocale())
            ?? $this->tryParse($this->sessionLocale());
    }

    /**
     * Detect locale from URL prefix (/en/blog/... or /sk/...). Strips the
     * prefix from the request path so the router matches the remaining path.
     */
    private function detectFromUrl(Request $request): ?Locale
    {
        $trimmed = trim($request->getPathInfo(), '/');

        if ('' === $trimmed) {
            return null;
        }

        $segments = explode('/', $trimmed);
        $candidate = $segments[0];

        // Short code first (sk, en) via the short→full map, then full ll_CC.
        $locale = $this->shortToFull($candidate) ?? $this->tryParse($candidate);

        if (null === $locale) {
            return null;
        }

        // '/' prefix guarantees a non-empty path, so '/' with no remainder
        // collapses to the root path the router expects.
        $request->setPathInfo('/' . implode('/', array_slice($segments, 1)));

        return $locale;
    }

    private function cookieLocale(): ?string
    {
        $value = $_COOKIE['locale'] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    private function sessionLocale(): ?string
    {
        // The middleware runs before CSRF (which is what lazily starts the
        // session), so at this point the session is normally not running.
        // Start it lazily ONLY when the browser already sent a session cookie
        // — visitors without one never pay the session-file I/O cost.
        if (PHP_SESSION_NONE === session_status()) {
            if (!isset($_COOKIE[session_name()])) {
                return null;
            }

            session_start();
        }

        $value = $_SESSION['locale'] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Resolve a short language code (sk, en) to a full locale via the
     * available set — the single source of truth for URL prefixes.
     */
    private function shortToFull(string $short): ?Locale
    {
        foreach (Locale::available() as $locale) {
            if ($locale->language() === $short) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * Try to parse a locale code string into a valid available Locale.
     */
    private function tryParse(?string $code): ?Locale
    {
        if (null === $code || '' === $code) {
            return null;
        }

        try {
            $locale = Locale::fromString($code);

            return $locale->isAvailable() ? $locale : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
