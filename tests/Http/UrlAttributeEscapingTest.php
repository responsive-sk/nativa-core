<?php

declare(strict_types = 1);

namespace Tests\Http;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * UrlAttributeEscapingTest — the head's URLs come from the request, so they are
 * escaped where they are written.
 *
 * `canonical`, `og:url`, `og:image` and `twitter:image` are built from
 * `Request::absoluteUrl()`, which takes the `Host` header and only constrains it
 * when `trustedHosts()` is configured. Without that, a quote in the host ends the
 * attribute it lands in: measured before this test, `Host: evil" onerror="x`
 * rendered `<meta property="og:image" content="http://evil" onerror="x/og/home.png">`
 * — a new attribute on the page, written by whoever sent the header.
 *
 * `hreflangTags()` escaped its URL from the start; `ogTags()` documented the
 * assumption that everything arrives escaped and escaped nothing. This pins the
 * rule at the point where a request-derived string becomes markup, so the next
 * URL added to the head inherits it instead of repeating the assumption.
 *
 * Kernel-only: `/` answers a truthy document on any tree, with or without a
 * module owning the landing page.
 */
final class UrlAttributeEscapingTest extends TestCase
{
    #[Test]
    public function aQuoteInTheHostCannotEndTheAttributeItLandsIn(): void
    {
        $host = 'evil.example" onerror="alert(1)';
        $html = $this->page($host);

        self::assertStringNotContainsString(
            '" onerror="',
            $html,
            'A quote in the Host header became an attribute on the page.',
        );

        $expected = htmlspecialchars(
            Request::create('GET', '/', headers: ['host' => $host])->absoluteUrl('/'),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        self::assertSame(
            $expected,
            self::attribute($html, 'link rel="canonical" href'),
            'The canonical must carry the host escaped, not the host as sent.',
        );

        self::assertSame(
            $expected,
            self::attribute($html, 'meta property="og:url" content'),
            'og:url mirrors the canonical and must be escaped the same way.',
        );

        if (str_contains($html, 'og:image')) {
            self::assertSame(
                $expected . 'og/home.png',
                self::attribute($html, 'meta property="og:image" content'),
                'The share image is built from the same request URL.',
            );
        }
    }

    /** The value of the first `<tag attr="…">` whose tag+attribute is given. */
    private static function attribute(string $html, string $tagAndAttribute): string
    {
        preg_match('/<' . preg_quote($tagAndAttribute, '/') . '="([^"]*)">/', $html, $match);

        return $match[1] ?? '';
    }

    private function page(string $host): string
    {
        $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));
        $response = $kernel->handle(Request::create('GET', '/', headers: ['host' => $host]));

        self::assertSame(200, $response->getStatusCode(), '/ must render with any host.');

        return $response->getContent();
    }
}
