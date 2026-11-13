<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping\Pagination;

/**
 * CursorStrategy — the next page is an opaque token, and there is no total.
 *
 * Feed- and log-shaped APIs answer `{"data": [...], "cursor": {"next": "…"}}`
 * (the shape the OAuth example uses) or the flatter `{"next_cursor": "…"}`.
 * Both spellings are read because both mean the same thing; an empty or absent
 * token means the end, which is the only signal a cursor API has.
 *
 * A cursor is echoed back verbatim by `RequestFactory` under `?cursor=`, never
 * parsed: its meaning belongs to the service, and decoding it here would be a
 * second implementation of somebody else's format.
 */
final readonly class CursorStrategy implements PaginationStrategy
{
    public function state(array $payload, array $headers): PageState
    {
        $cursor = $payload['cursor'] ?? null;
        $next = is_array($cursor) ? ($cursor['next'] ?? null) : null;
        $next ??= $payload['next_cursor'] ?? null;
        $nextCursor = is_string($next) && '' !== trim($next) ? trim($next) : null;

        return new PageState(
            currentPage: 1,
            nextCursor: $nextCursor,
            hasNext: null !== $nextCursor,
        );
    }
}
