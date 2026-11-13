<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping;

/**
 * ApiError — what a failed answer means, in words an operator can act on.
 *
 * `kind` is the small closed vocabulary the panel and the MCP tools branch on
 * (`validation`, `auth`, `forbidden`, `not_found`, `conflict`, `rate_limit`,
 * `server`, `client`, `transport`); `message` is already operator-facing and
 * never carries a token, a URL with a query, or an upstream HTML body.
 *
 * `fieldErrors` maps an API field name to the messages the service sent for it.
 * A 422 with no parseable field list puts its general message under
 * `_general`, which is what the form renders above the fields rather than
 * beside a wrong one.
 */
final readonly class ApiError
{
    /**
     * @param array<string, list<string>> $fieldErrors API field => messages
     */
    public function __construct(
        public int $status,
        public string $kind,
        public string $message,
        public array $fieldErrors = [],
    ) {
    }

    public function isValidation(): bool
    {
        return 'validation' === $this->kind;
    }

    /**
     * @return array{status: int, kind: string, message: string, field_errors: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return [
            'status'       => $this->status,
            'kind'         => $this->kind,
            'message'      => $this->message,
            'field_errors' => $this->fieldErrors,
        ];
    }
}
