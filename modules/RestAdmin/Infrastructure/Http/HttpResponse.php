<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Infrastructure\Http;

/**
 * HttpResponse — the answer as the mapping layer reads it.
 *
 * Header names are lower-cased and a repeated name is joined with a comma,
 * which is what HTTP means by sending it twice (except `Set-Cookie`, which
 * this read-only panel never needs). Callers ask with any casing.
 *
 * The body is a string, not decoded JSON: a 422 body and a 200 body are the
 * same bytes to the transport, and deciding what a body MEANS belongs to
 * `ErrorMapper`/`Envelope` in F3. A transport that decoded would have to
 * decide what to do with an HTML error page, which is exactly the decision
 * this class refuses to make.
 */
final readonly class HttpResponse
{
    /** @var array<string, string> lower-cased header name => value */
    public array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public string $body = '',
        array $headers = [],
    ) {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $key = strtolower($name);
            $normalised[$key] = isset($normalised[$key])
                ? $normalised[$key] . ', ' . $value
                : $value;
        }

        $this->headers = $normalised;
    }

    /**
     * A JSON answer for fixtures and for `FakeTransport`.
     *
     * @param array<string, string> $headers
     */
    public static function json(mixed $payload, int $status = 200, array $headers = []): self
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers += ['content-type' => 'application/json'];

        return new self($status, json_encode($payload, JSON_THROW_ON_ERROR), $headers);
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
