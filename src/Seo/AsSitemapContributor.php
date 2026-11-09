<?php

declare(strict_types = 1);

namespace Seo;

use Attribute;

/**
 * AsSitemapContributor — mark a class the sitemap registry should discover.
 *
 * Higher priority sorts first. The kernel's own home and locale entries are
 * always first and are not part of the vote.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsSitemapContributor
{
    public function __construct(public int $priority = 0)
    {
    }
}
