<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping\Pagination;

/**
 * LinkHeaderStrategy — navigation lives in the `Link` header, not the body.
 *
 * GitHub, GitLab and Atlassian answer a collection with the rows in the body
 * and `<https://…?page=2>; rel="next"` in a header; the total is a header too
 * (`X-Total-Count` or GitLab's `X-Total`). RFC 8288 allows a sender to split
 * the field across lines, and `HttpResponse` already joins those with a comma,
 * so one parse sees all of them.
 *
 * The shop example has no such header: it puts `next`/`previous`/`count` in the
 * body. That is the same information in the same place a link client looks for
 * it, so the body is the fallback rather than a fourth strategy — an operator
 * declaring `link` should not have to care which half of the response the
 * service chose.
 *
 * The current page is derived from the next/prev URL's `page` parameter when
 * the service does not state it; without either, it is 1.
 */
final readonly class LinkHeaderStrategy implements PaginationStrategy
{
    public function state(array $payload, array $headers): PageState
    {
        $links = $this->parse((string) ($headers['link'] ?? ''));

        $nextUrl = $links['next'] ?? $this->stringOrNull($payload['next'] ?? null);
        $previousUrl = $links['prev'] ?? $this->stringOrNull($payload['previous'] ?? null);
        $lastUrl = $links['last'] ?? null;

        $nextPage = $this->pageOf($nextUrl);
        $previousPage = $this->pageOf($previousUrl);

        $current = null !== $nextPage
            ? max(1, $nextPage - 1)
            : (null !== $previousPage ? $previousPage + 1 : 1);

        return new PageState(
            currentPage: $current,
            lastPage: $this->pageOf($lastUrl),
            total: $this->intOrNull($headers['x-total-count'] ?? $headers['x-total'] ?? $payload['count'] ?? null),
            hasNext: null !== $nextUrl,
            hasPrevious: null !== $previousUrl || $current > 1,
            nextUrl: $nextUrl,
            previousUrl: $previousUrl,
        );
    }

    /**
     * @return array<string, string> rel => URL
     */
    private function parse(string $header): array
    {
        $links = [];

        if ('' === trim($header)) {
            return $links;
        }

        preg_match_all('/<([^>]+)>\s*;\s*rel="?([^";,]+)"?/', $header, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $url = trim($match[1]);

            foreach (preg_split('/\s+/', trim($match[2])) ?: [] as $rel) {
                if ('' !== $rel) {
                    $links[strtolower($rel)] = $url;
                }
            }
        }

        return $links;
    }

    private function pageOf(?string $url): ?int
    {
        if (null === $url) {
            return null;
        }

        $query = parse_url($url, PHP_URL_QUERY);

        if (!is_string($query)) {
            return null;
        }

        parse_str($query, $params);
        $page = $params['page'] ?? null;

        return is_string($page) && ctype_digit($page) && (int) $page > 0 ? (int) $page : null;
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
