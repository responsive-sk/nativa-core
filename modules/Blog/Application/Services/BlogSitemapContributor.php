<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Blog\Application\BlogServices;
use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Seo\AsSitemapContributor;
use Seo\SitemapContributor;
use Seo\SitemapEntry;

/**
 * BlogSitemapContributor — the blog's own rows for `/sitemap.xml`.
 *
 * The listing is the same `BlogArticlesService::all()` the index renders, so a
 * draft is absent by the module's own publication rule. A blog index is listed
 * for every locale the mapper knows, even when a locale has no article yet:
 * the index is a served 200 surface and its empty state is still indexable.
 */
#[AsSitemapContributor(priority: 0)]
final readonly class BlogSitemapContributor implements SitemapContributor
{
    /**
     * @return list<SitemapEntry>
     */
    public function entries(): array
    {
        $mapper = ContentLocaleServices::mapper();
        $entries = [];

        foreach ($mapper->locales() as $locale) {
            $prefix = $mapper->urlPrefix($locale);
            $entries[] = new SitemapEntry($prefix . '/blog', 'weekly', '0.8');

            foreach (BlogServices::articles()->all($locale) as $article) {
                $entries[] = new SitemapEntry(
                    $prefix . '/blog/' . $article->slug,
                    SitemapEntry::DEFAULT_CHANGEFREQ,
                    '0.6',
                );
            }
        }

        return $entries;
    }
}
