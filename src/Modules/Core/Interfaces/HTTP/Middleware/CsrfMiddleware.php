<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Infrastructure\Attributes\AsMiddleware;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;

/**
 * CSRF Protection Middleware.
 *
 * Rejects state-changing requests (POST, PUT, DELETE, PATCH) that carry no
 * valid CSRF token. Tokens are stored in the (lazily started) session and
 * validated with hash_equals.
 *
 * A state-changing request that ALSO carries evidence of having been launched
 * from another site (`Origin` naming a different host, or — when `Origin` is
 * absent — `Referer` doing the same) is refused after the token check (audit
 * finding #7: the security checklist asks for the header cross-check and only
 * the token was ever validated). The two checks answer for different failures:
 * the token is the credential, the origin is the independent second lock, so a
 * leaked or replayed token still needs the request to reach this host from this
 * host's own pages.
 *
 * Three deliberate properties of the cross-check, each of which exists to keep
 * a working client working:
 *
 *  - **The token check stays FIRST**, so a request without a token is refused
 *    exactly as it was before this check existed (same 403, same message) and
 *    no existing probe or integration changes meaning.
 *  - **Neither header present → token-only.** `curl`, a server-to-server call,
 *    an older same-origin form, a proxy that strips both: no evidence is not
 *    evidence of forgery.
 *  - **A header that does not name this host → refused**, including
 *    `Origin: null` (a sandboxed document) and a malformed value; see
 *    `Request::isSameOrigin()`.
 *
 * Template usage:
 *   <?= CsrfMiddleware::field() ?>
 * or the raw token via CsrfMiddleware::token().
 */
#[AsMiddleware(priority: 20)]
final class CsrfMiddleware implements MiddlewareInterface
{
    private const string TOKEN_KEY = '_token';

    private const string SESSION_KEY = 'csrf_token';

    private const string HEADER_KEY = 'x-csrf-token';

    public function handle(Request $request, callable $next): Response
    {
        if ($this->isStateChangingRequest($request)) {
            if (!$this->isValidToken($request)) {
                return Response::html('CSRF token validation failed', 403);
            }

            if ($this->isCrossOrigin($request)) {
                return Response::html('Cross-site request rejected', 403);
            }
        }

        return $next($request);
    }

    /**
     * Get the session CSRF token, generating it on first use.
     */
    public static function token(): string
    {
        self::startSession();

        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        /** @var string $token */
        $token = $_SESSION[self::SESSION_KEY];

        return $token;
    }

    /**
     * Hidden form field helper for templates.
     */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::TOKEN_KEY . '" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Verify a submitted token against the session token.
     */
    public static function verify(#[\SensitiveParameter] string $token): bool
    {
        return hash_equals(self::token(), $token);
    }

    /**
     * The token the session THIS request opened already holds, minting nothing
     * and starting nothing — `null` when no session is open or none holds one.
     *
     * `token()` is the caller-facing accessor and mints on first use; this one
     * answers the question a cache policy has to ask: "does the body I am about
     * to store contain the token of the session that produced it?"
     *
     * The test is `session_status()`, not a cookie: a page that printed a form
     * started the session in this request (that is what printing the token
     * does), while `$_COOKIE` is the REQUEST's cookies and is therefore empty on
     * the very first render — which is exactly the render a cache stores. Asking
     * the cookie is how the first version of this method missed the case it was
     * written for: the guest's first page carried a token and was cached anyway,
     * and the next visitor's submission failed with 403.
     *
     * A request that never printed a token is a request with no session open (or
     * one opened for something else, holding no `csrf_token`) — nothing to
     * protect, and nothing a store may leak.
     */
    public static function sessionToken(): ?string
    {
        if (PHP_SESSION_NONE === session_status()) {
            return null;
        }

        $token = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($token) && '' !== $token ? $token : null;
    }

    /**
     * Regenerate the session token (call after login to prevent fixation).
     */
    public static function regenerateToken(): string
    {
        self::startSession();

        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));

        /** @var string $token */
        $token = $_SESSION[self::SESSION_KEY];

        return $token;
    }

    private static function startSession(): void
    {
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }
    }

    private function isStateChangingRequest(Request $request): bool
    {
        return \in_array($request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH'], true);
    }

    private function isValidToken(Request $request): bool
    {
        $token = $request->body(self::TOKEN_KEY);

        if (!is_string($token)) {
            $token = $request->header(self::HEADER_KEY);
        }

        if (!is_string($token) || '' === $token) {
            return false;
        }

        return hash_equals(self::token(), $token);
    }

    /**
     * Was this token-bearing request launched from somewhere other than here?
     *
     * `Origin` is the header browsers set on form posts and cross-site requests
     * alike; `Referer` is the fallback for the clients that omit it. Only when
     * BOTH are absent (or empty) is there nothing to compare, and then the
     * token — already verified by the caller — is the whole check.
     */
    private function isCrossOrigin(Request $request): bool
    {
        $evidence = $request->header('origin');

        if (!is_string($evidence) || '' === trim($evidence)) {
            $evidence = $request->header('referer');
        }

        if (!is_string($evidence) || '' === trim($evidence)) {
            return false;
        }

        if ($request->isSameOrigin($evidence)) {
            return false;
        }

        $this->logMismatch($evidence);

        return true;
    }

    /**
     * Why a token-bearing request was still refused, at DEBUG — the same gate
     * and the same tag vocabulary `AuthMiddleware::logDenial()` uses. The
     * value logged is the header as it arrived: it is the evidence an operator
     * needs to tell a real cross-site post from a misconfigured public host,
     * and it is the caller's own header rather than anything secret.
     */
    private function logMismatch(string $evidence): void
    {
        if ('debug' !== strtolower((string) (getenv('LOG_LEVEL') ?: 'info'))) {
            return;
        }

        error_log(sprintf('[security] csrf origin mismatch: %s', $evidence));
    }
}
