<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping;

use Modules\RestAdmin\Application\Mapping\Pagination\PageState;

/**
 * ListPage — one page of rows, where it sits, and the answer it came from.
 *
 * `payload` is kept because the panel may show fields that are not columns
 * (a count in a meta block, the service's own `links`) and because a probe in
 * F4 wants to show the operator what actually arrived without a second
 * request. It is not a second item list: `items` is the mapped collection.
 */
final readonly class ListPage
{
    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $payload
     */
    public function __construct(
        public array $items,
        public PageState $state,
        public array $payload = [],
    ) {
    }
}
