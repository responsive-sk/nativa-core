<?php

declare(strict_types = 1);

namespace Seo;

/**
 * WebsiteStructuredData — the kernel home's Organization + WebSite JSON-LD.
 *
 * The home surface announces the site itself, including a SearchAction so
 * Google can offer site search rich results. The article, listing, category
 * and author builders belong to Blog and live in its own
 * `StructuredDataService`.
 *
 * Each method returns a ready-to-inline JSON string for the
 * <script type="application/ld+json"> tag in FrontendLayout. JSON is encoded
 * with JSON_HEX_TAG so a `</script>` sequence inside any user-provided
 * value can never break out of the script element (XSS-safe by
 * construction), plus JSON_UNESCAPED_SLASHES/UNICODE for readable output.
 */
final class WebsiteStructuredData
{
    /**
     * Organization + WebSite for the homepage, including a SearchAction so
     * Google can offer site search rich results (target /blog?q=…).
     */
    public static function website(string $name, string $url): string
    {
        $searchUrl = $url . 'blog?q={search_term_string}';

        return (string) json_encode(
            [
                [
                    '@context' => 'https://schema.org',
                    '@type'    => 'Organization',
                    'name'     => $name,
                    'url'      => $url,
                ],
                [
                    '@context'        => 'https://schema.org',
                    '@type'           => 'WebSite',
                    'name'            => $name,
                    'url'             => $url,
                    'potentialAction' => [
                        '@type'       => 'SearchAction',
                        'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $searchUrl],
                        'query-input' => 'required name=search_term_string',
                    ],
                ],
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
    }
}
