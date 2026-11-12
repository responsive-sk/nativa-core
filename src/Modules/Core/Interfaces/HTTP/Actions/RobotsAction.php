<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Actions;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\Router;

/**
 * RobotsAction — robots.txt for cooperative crawlers, allow-whitelist style.
 *
 * The file disallows everything by default and re-allows only the public
 * indexable surfaces this tree actually serves: home, then the /sk and /en
 * locale roots, the blog, author profiles, the legal pages, the sitemap and
 * `llms.txt` — each emitted only when the router answers its path. Admin
 * (/mark), debug (/debug), uploads and auth pages are deliberately NOT
 * mentioned — a blacklist would advertise their existence to anyone reading
 * the file.
 *
 * Measured 2026-11-11 on the kernel-only export: the file listed `/blog`,
 * `/authors`, `/privacy`, `/cookies`, both locale roots and the cover-image
 * directory while every one of them answered 404. A crawl hint that points at
 * a dead end is the same defect as a seeded menu row or a hero button: it is
 * a claim about the site that no route backs.
 *
 * robots.txt is a crawl hint, not a security boundary: the real protection
 * for /mark and /debug is the existing auth (admin role) + APP_DEBUG gating
 * + the noindex meta tags on those pages. Whitelist matching follows the
 * most-specific-rule-wins convention (Disallow: / vs Allow: /blog).
 */
#[Route(path: '/robots.txt', method: 'GET', name: 'robots')]
final readonly class RobotsAction
{
    public function __construct(private ?Router $router = null)
    {
    }

    public function handle(Request $request): Response
    {
        $content = 'User-agent: *' . "\n"
            . 'Disallow: /' . "\n"
            // `/` is the one surface the kernel itself always serves.
            . 'Allow: /$' . "\n";

        // The rest are advertisements, so they follow the same rule as the
        // seeded menu and the theme's hero: ask the router, the owner of "what
        // exists". A module that is installed answers yes; a tree without it
        // stops teaching a crawler a 404. No router means nothing can be
        // confirmed, and the file advertises only `/`.
        foreach (self::indexablePaths() as $path) {
            if (null !== $this->router && $this->router->hasPath($path)) {
                $content .= 'Allow: ' . $path . "\n";
            }
        }

        // Not a route: generated covers are files, so the directory itself is
        // the test. An install that never generated one has nothing to allow.
        if (is_dir(AppPaths::instance()->public('storage/blog-images'))) {
            $content .= 'Allow: /storage/blog-images' . "\n";
        }

        $content .= "\n" . 'Sitemap: ' . $request->absoluteUrl('/sitemap.xml') . "\n";

        $response = new Response($content);
        $response->setHeader('Content-Type', 'text/plain; charset=utf-8');

        return $response;
    }

    /**
     * Public paths a full site advertises, in file order. `/sitemap.xml` is the
     * kernel's own route (`SitemapAction`), so a kernel-only tree answers it and
     * advertises it too; the rest belong to a module (ContentLocale, Blog,
     * Pages) or are a generated file, so each is emitted only on the router's
     * yes.
     *
     * @return list<string>
     */
    private static function indexablePaths(): array
    {
        return ['/sk', '/en', '/blog', '/authors', '/privacy', '/cookies', '/sitemap.xml', '/llms.txt'];
    }
}
