<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services;

/**
 * StructuredDataService — JSON-LD structured data builders (schema.org).
 *
 * Each method returns a ready-to-inline JSON string for the
 * <script type="application/ld+json"> tag in FrontendLayout. JSON is encoded
 * with JSON_HEX_TAG so a `</script>` sequence inside any user-provided
 * value can never break out of the script element (XSS-safe by
 * construction), plus JSON_UNESCAPED_SLASHES/UNICODE for readable output.
 *
 * Callers pass absolute URLs (Request::absoluteUrl) so the markup matches
 * the canonical/hreflang signals already emitted on the same page.
 */
final class StructuredDataService
{
    /**
     * Organization + WebSite for the homepage, including a SearchAction so
     * Google can offer site search rich results (target /blog?q=…).
     */
    public static function website(string $name, string $url): string
    {
        $searchUrl = $url . 'blog?q={search_term_string}';

        return self::encode([
            [
                '@context' => 'https://schema.org',
                '@type'    => 'Organization',
                'name'     => $name,
                'url'      => $url,
            ],
            [
                '@context'      => 'https://schema.org',
                '@type'         => 'WebSite',
                'name'          => $name,
                'url'           => $url,
                'potentialAction' => [
                    '@type'       => 'SearchAction',
                    'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $searchUrl],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ]);
    }

    /**
     * Article + BreadcrumbList for a blog article page. $published is the
     * front-matter date (YYYY-MM-DD), $image an absolute cover URL or null.
     *
     * $blogName is the section's own word for itself, passed in for the same
     * reason as everywhere else here: the kernel renders the trail, the module
     * that owns the section names it.
     */
    public static function article(
        string $title,
        string $description,
        string $url,
        string $published,
        ?string $image,
        string $authorName,
        string $siteName,
        string $blogName,
    ): string {
        $article = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => $title,
            'description'      => $description,
            'datePublished'    => $published,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'author'           => ['@type' => 'Person', 'name' => $authorName],
            'publisher'        => ['@type' => 'Organization', 'name' => $siteName],
        ];

        if (null !== $image) {
            $article['image'] = $image;
        }

        // Article URLs always end with /{slug}, so the blog index is the
        // URL up to the last slash (https://host/blog/slug → https://host/blog,
        // https://host/en/blog/slug → https://host/en/blog).
        $blogUrl = (string) substr($url, 0, (int) strrpos($url, '/'));

        return self::encode([
            $article,
            self::breadcrumb([
                ['name' => $blogName, 'url' => $blogUrl],
                ['name' => $title],
            ]),
        ]);
    }

    /**
     * A listing page: a `CollectionPage` plus the Home > name trail.
     *
     * $homeUrl is passed rather than derived from $url because a listing is
     * locale-prefixed (`/en/blog`) and the root it hangs under is not. The name
     * is the caller's: a kernel service does not know what the module that owns
     * the listing calls itself.
     */
    public static function collection(
        string $name,
        string $url,
        string $homeUrl,
    ): string {
        return self::encode([
            [
                '@context' => 'https://schema.org',
                '@type'    => 'CollectionPage',
                'name'     => $name,
                'url'      => $url,
            ],
            self::breadcrumb([
                ['name' => 'Home', 'url' => $homeUrl],
                ['name' => $name],
            ]),
        ]);
    }

    /**
     * A category listing: `CollectionPage` plus the parent > Category trail.
     *
     * The parent URL and label are the caller's, not a substring of $url: the
     * category path (`/en/blog/categories/nastroje`) carries the locale prefix,
     * and cutting it at a guessed separator is how the trail ends up pointing at
     * `/en/blog/`.
     */
    public static function category(string $name, string $url, string $parentName, string $parentUrl): string
    {
        return self::encode([
            [
                '@context' => 'https://schema.org',
                '@type'    => 'CollectionPage',
                'name'     => $name,
                'url'      => $url,
            ],
            self::breadcrumb([
                ['name' => $parentName, 'url' => $parentUrl],
                ['name' => $name],
            ]),
        ]);
    }

    /**
     * BreadcrumbList from a name/url trail.
     *
     * The last crumb is the page itself and carries no `item`, which is what
     * schema.org's own example does: repeating the current URL there is a
     * self-link rather than a step.
     *
     * @param list<array{name: string, url?: string}> $trail
     *
     * @return array<string, mixed>
     */
    private static function breadcrumb(array $trail): array
    {
        $items = [];

        foreach ($trail as $position => $crumb) {
            $item = ['@type' => 'ListItem', 'position' => $position + 1, 'name' => $crumb['name']];

            if (isset($crumb['url'])) {
                $item['item'] = $crumb['url'];
            }

            $items[] = $item;
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * Person for an author profile page.
     */
    public static function person(string $name, string $url): string
    {
        return self::encode([
            '@context' => 'https://schema.org',
            '@type'    => 'Person',
            'name'     => $name,
            'url'      => $url,
        ]);
    }

    /**
     * JSON-encode with script-context safety flags.
     *
     * @param array<array-key, mixed> $data
     */
    private static function encode(array $data): string
    {
        return (string) json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
    }
}
