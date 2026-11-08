<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure\Http;

/**
 * HTTP Response.
 */
class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    public function __construct(
        private string $content = '',
        private readonly int $statusCode = 200,
    ) {
    }

    public static function html(string $content, int $statusCode = 200): self
    {
        $response = new self($content, $statusCode);
        $response->setHeader('Content-Type', 'text/html; charset=UTF-8');

        return $response;
    }

    public static function json(mixed $data, int $statusCode = 200): JsonResponse
    {
        return JsonResponse::from($data, $statusCode);
    }

    /**
     * XML response with an explicit Content-Type (e.g. RSS/Atom feeds).
     */
    public static function xml(string $content, string $contentType = 'application/xml; charset=UTF-8'): self
    {
        $response = new self($content, 200);
        $response->setHeader('Content-Type', $contentType);

        return $response;
    }

    public static function redirect(string $url, int $statusCode = 302): self
    {
        $response = new self('', $statusCode);
        $response->setHeader('Location', $url);

        return $response;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    public function setHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function isRedirect(): bool
    {
        return in_array($this->statusCode, [301, 302, 303, 307, 308], true);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        echo $this->content;
    }
}
