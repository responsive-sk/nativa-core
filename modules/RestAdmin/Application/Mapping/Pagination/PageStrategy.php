<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping\Pagination;

/**
 * PageStrategy — the Laravel/Django paginator shape.
 *
 * The same numbers appear in two places depending on the producer: Laravel's
 * API resources wrap them in `meta`, while the blog example's `/v1/authors`
 * returns them at the top level. Both are read, because both are the same
 * paginator with a different serialiser.
 *
 * `nav` URLs win over computed ones: a service that says where its next page is
 * knows better than a `current + 1` guess, especially when it caps page size.
 */
final readonly class PageStrategy implements PaginationStrategy
{
    public function state(array $payload, array $headers): PageState
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : $payload;
        $links = is_array($payload['links'] ?? null) ? $payload['links'] : [];

        $current = $this->int($meta['current_page'] ?? null, 1);
        $last = $this->intOrNull($meta['last_page'] ?? null);
        $nextUrl = $this->stringOrNull($links['next'] ?? $meta['next_page_url'] ?? null);
        $previousUrl = $this->stringOrNull($links['prev'] ?? $meta['prev_page_url'] ?? null);

        return new PageState(
            currentPage: max(1, $current),
            lastPage: null === $last ? null : max(1, $last),
            perPage: $this->intOrNull($meta['per_page'] ?? null),
            total: $this->intOrNull($meta['total'] ?? null),
            hasNext: null !== $last ? $current < $last : null !== $nextUrl,
            hasPrevious: $current > 1 || null !== $previousUrl,
            nextUrl: $nextUrl,
            previousUrl: $previousUrl,
        );
    }

    private function int(mixed $value, int $default): int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : $default;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
