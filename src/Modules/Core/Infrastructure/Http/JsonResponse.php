<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure\Http;

/**
 * JSON Response — encodes data as JSON.
 */
final class JsonResponse extends Response
{
    private function __construct(string $content, int $statusCode)
    {
        parent::__construct($content, $statusCode);
        $this->setHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    public static function from(mixed $data, int $statusCode = 200): self
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new self($json, $statusCode);
    }
}
