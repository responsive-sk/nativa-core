<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Infrastructure\Attributes\AsMiddleware;
use Modules\Core\Infrastructure\Env;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;

/**
 * Security Headers Middleware — first in the pipeline.
 *
 * Generates a per-request CSP nonce, shares it with templates via the
 * request attribute, and sets security headers on the response.
 *
 * Nonce sharing strategy: templates embed a NONCE_PLACEHOLDER string; the
 * middleware replaces it with the real nonce in the response body AFTER the
 * response is generated. This keeps cached HTML free of stale nonces while
 * every response still matches its CSP header.
 */
#[AsMiddleware(priority: 0)]
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public const string NONCE_PLACEHOLDER = '__CSP_NONCE_PLACEHOLDER__';

    private const array CSP_DIRECTIVES = [
        'default-src'    => "'self'",
        'script-src'     => "'self' 'nonce-{{NONCE}}'",
        'style-src'      => "'self' 'nonce-{{NONCE}}'",
        'style-src-elem' => "'self' 'unsafe-inline'",
        'style-src-attr' => "'unsafe-inline'",
        'img-src'        => "'self' data: blob: https://res.cloudinary.com",
        'font-src'       => "'self'",
        'connect-src'    => "'self'",
        'frame-src'      => "'none'",
        'object-src'     => "'none'",
        'base-uri'       => "'self'",
        'form-action'    => "'self'",
        'frame-ancestors' => "'none'",
    ];

    private string $nonce;

    public function __construct()
    {
        $this->nonce = bin2hex(random_bytes(16));
    }

    public function handle(Request $request, callable $next): Response
    {
        // Share placeholder with templates via request attribute
        $request->setAttribute('csp_nonce', self::NONCE_PLACEHOLDER);

        $response = $next($request);

        // Replace nonce placeholder with the actual nonce in the response body.
        // Runs on every request so cached pages with the placeholder always get
        // a fresh, matching nonce.
        //
        // HTML-only: binary file bodies (uploads served via the storage
        // route) must pass through byte-identical — a full-body scan is
        // wasted CPU on large files, and a txt/md upload literally
        // containing the placeholder string would be corrupted (and cached
        // immutable). Content-Type is set before this middleware runs.
        $contentType = (string) ($response->getHeader('Content-Type') ?? '');
        $content = $response->getContent();
        if (str_starts_with($contentType, 'text/html')) {
            $updated = str_replace(self::NONCE_PLACEHOLDER, $this->nonce, $content);
            if ($updated !== $content) {
                $response->setContent($updated);
            }
        }

        $this->addSecurityHeaders($response);

        return $response;
    }

    private function addSecurityHeaders(Response $response): void
    {
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        // X-XSS-Protection is intentionally NOT set (audit D-3): the header is
        // deprecated, unreliable and can mask real XSS — CSP with a per-request
        // nonce is the actual defense and is set below.
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->setHeader('Content-Security-Policy', $this->getCspHeader());

        // HSTS — production only (requires HTTPS). Gated on APP_ENV rather
        // than APP_DEBUG so debug builds can never advertise it on plain
        // HTTP (audit finding F-3).
        if ('production' === Env::get('APP_ENV', 'development')) {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }
    }

    private function getCspHeader(): string
    {
        $parts = [];

        foreach (self::CSP_DIRECTIVES as $directive => $value) {
            $parts[] = $directive . ' ' . str_replace('{{NONCE}}', $this->nonce, $value);
        }

        return implode('; ', $parts);
    }
}
