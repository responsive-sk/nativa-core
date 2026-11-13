<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP;

/**
 * ToolResult — one MCP `tools/call` answer.
 *
 * MCP has two failure channels: a JSON-RPC error means the *call* was malformed
 * or named a tool that does not exist, while `isError: true` means the tool ran
 * and the operation failed (a 422, a missing service, a refused delete). A
 * client that treats both the same retries a request that can never succeed.
 *
 * The payload is JSON text, not a bespoke model object: the MCP contract is
 * `content: [{type: "text", text: "…"}]`, and a model reads JSON more reliably
 * than a PHP `var_export`. No tool ever puts a token in here — the spec stores
 * an env variable NAME, and the transport reads the value at call time.
 */
final readonly class ToolResult
{
    private function __construct(
        public string $text,
        public bool $isError,
    ) {
    }

    /**
     * @param array<string, mixed>|string $payload
     */
    public static function ok(array|string $payload): self
    {
        return new self(is_string($payload) ? $payload : self::encode($payload), false);
    }

    /**
     * @param array<string, mixed> $detail Structured detail a client may branch on
     */
    public static function error(string $message, array $detail = []): self
    {
        if ([] === $detail) {
            return new self($message, true);
        }

        return new self($message . "\n" . self::encode($detail), true);
    }

    /**
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     */
    public function toArray(): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $this->text]],
            'isError' => $this->isError,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
