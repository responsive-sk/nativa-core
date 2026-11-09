<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Actions;

use Modules\Core\Application\Services\ShellContextProviderRegistry;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * SitemapAction — XML sitemap (Google sitemap protocol 0.9) of the URLs the
 * KERNEL knows: home and the locale roots, with absolute URLs built from the
 * request (loopback-safe via Request::absoluteUrl).
 *
 * What it does NOT list is the point. The article enumeration belonged to
 * Blog, the page enumeration to Pages, the author profiles to Blog's URL
 * shape, the blog index and the feeds to the module that serves them — four
 * `M` ledger rows, one per half this file used to enumerate. A kernel-only
 * tree serves no `/blog`, and a sitemap that advertises a 404 teaches a
 * crawler to find one. When those modules arrive they extend the sitemap
 * through their own contribution (a port, like every other kernel surface) —
 * until then the kernel lists what it serves and nothing else.
 *
 * All values are XML-escaped; output is deterministic per request.
 */
#[Route(path: '/sitemap.xml', method: 'GET', name: 'sitemap')]
final class SitemapAction
{
    public function handle(Request $request): Response
    {
        $base = $request->absoluteUrl('');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n"
            . $this->entries($base)
            . '</urlset>' . "\n";

        return Response::xml($xml, 'application/xml; charset=utf-8');
    }

    /**
     * Home in every locale the shell port reports: the default owns the bare
     * path, every other locale its short language prefix. A kernel with no
     * locale provider lists only `/` — the pages it actually serves.
     */
    private function entries(string $base): string
    {
        $default = ShellContextProviderRegistry::defaultLocale();
        $entries = $this->entry($base, '/', 'weekly', '1.0');

        foreach (ShellContextProviderRegistry::languages() as $locale) {
            if ($locale === $default) {
                continue;
            }

            $entries .= $this->entry(
                $base,
                '/' . strtolower(substr($locale, 0, 2)) . '/',
                'weekly',
                '1.0',
            );
        }

        return $entries;
    }

    private function entry(string $base, string $path, string $changefreq, string $priority): string
    {
        return '  <url>' . "\n"
            . '    <loc>' . FrontendLayout::e($base . $path) . '</loc>' . "\n"
            . '    <changefreq>' . $changefreq . '</changefreq>' . "\n"
            . '    <priority>' . $priority . '</priority>' . "\n"
            . '  </url>' . "\n";
    }
}
