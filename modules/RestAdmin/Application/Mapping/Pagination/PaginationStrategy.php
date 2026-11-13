<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping\Pagination;

/**
 * PaginationStrategy — one way of answering "where does the next page start?".
 *
 * A strategy reads the body and the response headers of one answer; it never
 * issues a request. That keeps the three strategies testable against a fixture
 * and lets `ListReader` own the transport, the envelope and the error mapping
 * once.
 */
interface PaginationStrategy
{
    /**
     * @param array<string, mixed>  $payload the decoded body
     * @param array<string, string> $headers lower-cased response headers
     */
    public function state(array $payload, array $headers): PageState;
}
