<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Actions;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Seo\SitemapContributorRegistry;
use Seo\SitemapEntry;
use Shell\ShellContextProviderRegistry;
use Themes\FrontendLayout;

/**
 * SitemapAction — XML sitemap (Google sitemap protocol 0.9) of the URLs the
 * installed tree actually serves.
 *
 * The kernel contributes home and the locale roots it knows. Modules extend it
 * through `Seo\SitemapContributor`: a module owns its published content and the
 * URL shape that serves it, so the kernel never guesses a path that might be a
 * 404. A contributor failure degrades to the kernel rows instead of a 500.
 *
 * All values are XML-escaped; output is deterministic per request.
 */
#[Route(path: '/sitemap.xml', method: 'GET', name: 'sitemap')]
final readonly class SitemapAction
{
    public function __construct(
        private ?SitemapContributorRegistry $contributors = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        $base = $request->absoluteUrl('');
        $entries = $this->entries();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n"
            . $this->render($base, $entries)
            . '</urlset>' . "\n";

        return Response::xml($xml, 'application/xml; charset=utf-8');
    }

    /**
     * Kernel rows first, then every contributor row, deduplicated by path.
     * Sorting by path makes the response stable even when two contributors
     * return the same set in a different order.
     *
     * @return list<SitemapEntry>
     */
    private function entries(): array
    {
        $entries = [new SitemapEntry('/', 'weekly', '1.0')];
        $default = ShellContextProviderRegistry::defaultLocale();

        foreach (ShellContextProviderRegistry::languages() as $locale) {
            if ($locale === $default) {
                continue;
            }

            $entries[] = new SitemapEntry(
                '/' . strtolower(substr($locale, 0, 2)) . '/',
                'weekly',
                '1.0',
            );
        }

        $registry = $this->contributors ?? SitemapContributorRegistry::fromModules();

        foreach ($registry->entries() as $entry) {
            $entries[] = $entry;
        }

        $unique = [];
        $seen = [];

        foreach ($entries as $entry) {
            if (isset($seen[$entry->path])) {
                error_log('[seo] duplicate sitemap path: ' . $entry->path);

                continue;
            }

            $seen[$entry->path] = true;
            $unique[] = $entry;
        }

        usort(
            $unique,
            static fn (SitemapEntry $a, SitemapEntry $b): int => $a->path <=> $b->path,
        );

        return $unique;
    }

    /**
     * @param list<SitemapEntry> $entries
     */
    private function render(string $base, array $entries): string
    {
        $xml = '';

        foreach ($entries as $entry) {
            $xml .= '  <url>' . "\n"
                . '    <loc>' . FrontendLayout::e($base . $entry->path) . '</loc>' . "\n"
                . '    <changefreq>' . $entry->changefreq . '</changefreq>' . "\n"
                . '    <priority>' . $entry->priority . '</priority>' . "\n"
                . '  </url>' . "\n";
        }

        return $xml;
    }
}
