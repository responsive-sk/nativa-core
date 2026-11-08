<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Core\Application\Services\CacheInvalidator;

/**
 * BlogPageCache — the public pages an article write makes stale.
 *
 * One list, because the answer is the same for every write that changes the
 * article set (create, edit, delete, publish toggle, inline save, tag and
 * category edits):
 *
 *  - `/` — the homepage lists the newest articles.
 *  - `/blog` — the index, every article, the category and tag pages and the
 *    series pages all live under this prefix.
 *  - `/authors` — an author profile lists that author's articles.
 *
 * Everything else keeps its cache: a write no longer re-renders a site that
 * did not change. The pages that are NOT here are the reason the list is a
 * constant rather than an argument — an action adding its own path would
 * silently forget the homepage.
 *
 * `CacheInvalidator::flush()` is the wrong call from a content write: it
 * drops entries this write never touched, which is exactly the cost this list
 * exists to avoid.
 */
final class BlogPageCache
{
    /**
     * Request paths whose cached page depends on the article set, matched as
     * segment-boundary prefixes by `CacheInvalidator::invalidate()`.
     *
     * A method rather than a constant because this tree ratchets its const
     * declarations (`NameOwnershipContractTest`): a new constant has to lower
     * something else, and these paths are a list this class owns, not a value
     * shared with another declaration.
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        return ['/', '/blog', '/authors'];
    }

    /**
     * Invalidate the cached public pages that depend on the article set.
     *
     * Returns the number of cache entries deleted.
     */
    public static function invalidate(): int
    {
        return CacheInvalidator::invalidate(...self::paths());
    }
}
