<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Domain\Model;

/**
 * PaginationSpec — which strategy the mapper uses to walk a collection.
 *
 * The vocabulary is closed and owned here rather than in the mapping layer,
 * because a stored specification names one of these four and an unknown word
 * has to be refused at save time, not discovered as an empty second page.
 *
 * - `page`   — `?page=n` and a total in the body;
 * - `link`   — RFC 8288 `Link: <…>; rel="next"`;
 * - `cursor` — an opaque `next_cursor` in the body;
 * - `none`   — the endpoint answers the whole collection.
 *
 * The parameter NAMES those strategies use (`page`, `per_page`, `cursor`) are
 * the mapping layer's business in F3: they are part of a strategy, not of the
 * stored choice.
 */
final readonly class PaginationSpec
{
    public function __construct(public string $strategy = 'page')
    {
        if (!in_array($strategy, ['page', 'link', 'cursor', 'none'], true)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a pagination strategy; use page, link, cursor or none.',
                $strategy,
            ));
        }
    }
}
