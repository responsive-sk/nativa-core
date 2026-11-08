<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Actions;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;

/**
 * RobotsAction — robots.txt for cooperative crawlers, allow-whitelist style.
 *
 * The file disallows everything by default and re-allows only the public
 * indexable surfaces: home (incl. the /sk and /en locale roots), the blog
 * (index, articles, feeds), author profiles, the self-hosted generated
 * cover images and the sitemap. Admin (/mark), debug (/debug), uploads and
 * auth pages are deliberately NOT mentioned — a blacklist would advertise
 * their existence to anyone reading the file.
 *
 * robots.txt is a crawl hint, not a security boundary: the real protection
 * for /mark and /debug is the existing auth (admin role) + APP_DEBUG gating
 * + the noindex meta tags on those pages. Whitelist matching follows the
 * most-specific-rule-wins convention (Disallow: / vs Allow: /blog).
 */
#[Route(path: '/robots.txt', method: 'GET', name: 'robots')]
final class RobotsAction
{
    public function handle(Request $request): Response
    {
        $content = 'User-agent: *' . "\n"
            . 'Disallow: /' . "\n"
            . 'Allow: /$' . "\n"
            . 'Allow: /sk' . "\n"
            . 'Allow: /en' . "\n"
            . 'Allow: /blog' . "\n"
            . 'Allow: /authors' . "\n"
            . 'Allow: /privacy' . "\n"
            . 'Allow: /cookies' . "\n"
            . 'Allow: /storage/blog-images' . "\n"
            . 'Allow: /sitemap.xml' . "\n"
            . 'Allow: /llms.txt' . "\n"
            . "\n"
            . 'Sitemap: ' . $request->absoluteUrl('/sitemap.xml') . "\n";

        $response = new Response($content);
        $response->setHeader('Content-Type', 'text/plain; charset=utf-8');

        return $response;
    }
}
