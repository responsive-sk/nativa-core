<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Infrastructure\Http;

/**
 * HttpRequestSpec — one outbound request, fully decided before the wire.
 *
 * Read-only and free of transport concerns: building a request is not allowed
 * to open a connection, so the mapping layer can produce one and a test can
 * assert it without a server.
 *
 * Header names are lower-cased on construction, because HTTP header names are
 * case-insensitive and two spellings of one name would otherwise both reach
 * the wire. Values are rejected when they carry CR or LF: a header value that
 * can start a new header is a request-splitting bug waiting for an operator
 * field.
 *
 * `url` is absolute. Resolving a resource path against a service's base URL is
 * the mapping layer's job (F3); this object carries the result, and
 * `SsrfGuard` checks it before anything is sent.
 */
final readonly class HttpRequestSpec
{
    /** @var array<string, string> lower-cased header name => value */
    public array $headers;

    public string $method;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $method,
        public string $url,
        array $headers = [],
        public ?string $body = null,
    ) {
        $method = strtoupper($method);

        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not an HTTP method this transport sends.',
                $method,
            ));
        }

        $this->method = $method;

        $normalised = [];

        foreach ($headers as $name => $value) {
            if (1 === preg_match('/[\r\n]/', $name . $value)) {
                throw new \InvalidArgumentException(sprintf(
                    'Header "%s" carries a line break — a header value must not be able to start another header.',
                    $name,
                ));
            }

            $normalised[strtolower($name)] = $value;
        }

        $this->headers = $normalised;
    }

    /**
     * A JSON request, which is what every resource endpoint in this module
     * speaks. The caller's headers win, so a service that wants a different
     * `Accept` can say so.
     *
     * @param array<string, string> $headers
     */
    public static function json(string $method, string $url, mixed $payload, array $headers = []): self
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers += [
            'content-type' => 'application/json',
            'accept'       => 'application/json',
        ];

        return new self($method, $url, $headers, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Authorization headers and the body are redacted on inspection.
     *
     * This object is exactly what a crash report, a `var_dump()` or an error
     * tracker prints, and a create form's body may carry a password. The body
     * says its size instead of its content: a request is not a place to learn
     * what a credential was.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $headers = $this->headers;

        foreach (['authorization', 'cookie', 'proxy-authorization', 'x-api-key'] as $secret) {
            if (isset($headers[$secret])) {
                // @mago-expect lint:no-literal-password
                $headers[$secret] = '***redacted***'; // audit-ok: redaction placeholder
            }
        }

        return [
            'method'  => $this->method,
            'url'     => $this->url,
            'headers' => $headers,
            'body'    => null === $this->body ? null : sprintf('(%d bytes)', strlen($this->body)),
        ];
    }
}
