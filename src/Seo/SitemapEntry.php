<?php

declare(strict_types = 1);

namespace Seo;

use InvalidArgumentException;

/**
 * SitemapEntry — one URL a contributor adds to `/sitemap.xml`.
 *
 * The path is relative to the site root: a contributor answers from its own
 * content and locale mapping, not from the request or the host. `SitemapAction`
 * joins it to the request's absolute base URL.
 *
 * A path that cannot be trusted is rejected rather than advertised: a crawler
 * that follows an absolute URL, a traversal or a query is a crawler taught a
 * defect. A bad policy value is not a reason to lose the URL, so `changefreq`
 * and `priority` fall back to their defaults while the path is accepted.
 */
final readonly class SitemapEntry
{
    public const string DEFAULT_CHANGEFREQ = 'monthly';

    public const string DEFAULT_PRIORITY = '0.5';

    /** @var list<string> */
    private const array CHANGEFREQS = [
        'always',
        'hourly',
        'daily',
        'weekly',
        'monthly',
        'yearly',
        'never',
    ];

    public string $path;

    public string $changefreq;

    public string $priority;

    /**
     * @throws InvalidArgumentException when the path is not root-relative and safe
     */
    public function __construct(
        string $path,
        string $changefreq = self::DEFAULT_CHANGEFREQ,
        string $priority = self::DEFAULT_PRIORITY,
    ) {
        $this->path = self::assertPath($path);
        $this->changefreq = in_array($changefreq, self::CHANGEFREQS, true)
            ? $changefreq
            : self::DEFAULT_CHANGEFREQ;
        $this->priority = self::normalizePriority($priority);
    }

    private static function assertPath(string $path): string
    {
        if ('' === $path
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '://')
            || str_contains($path, '..')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || 1 === preg_match('/[\x00-\x20]/', $path)
        ) {
            throw new InvalidArgumentException(sprintf(
                'Sitemap path must be a safe root-relative path, got "%s".',
                $path,
            ));
        }

        return $path;
    }

    private static function normalizePriority(string $priority): string
    {
        return 1 === preg_match('/^(?:0(?:\.\d+)?|1(?:\.0+)?)$/', $priority)
            ? $priority
            : self::DEFAULT_PRIORITY;
    }
}
