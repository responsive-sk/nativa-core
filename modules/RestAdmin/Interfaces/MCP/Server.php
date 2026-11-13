<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP;

/**
 * Server — JSON-RPC 2.0 over newline-delimited JSON on stdio.
 *
 * Deliberately small and transport-agnostic: `handle()` takes one JSON document
 * and returns the response document(s), so every protocol rule is testable
 * without a process. `serve()` is the only part that touches stdin/stdout.
 *
 * Covered protocol rules:
 * - `initialize` answers a protocol version, a `tools` capability and a name;
 * - `tools/list` and `tools/call` are the only methods beyond `ping`;
 * - `notifications/initialized` (and any request without `id`) gets no reply;
 * - a batch is answered as one JSON array, in request order;
 * - malformed JSON is `-32_700`, a non-object request `-32_600`, an unknown
 *   method `-32_601`, an unknown tool `-32_602`;
 * - `params` is optional for `tools/list` and required for `tools/call`.
 *
 * The server never logs a request body: a `create_item` body may carry data an
 * operator would not want in a terminal scrollback, and the transport's own
 * `__debugInfo()` already redacts auth headers.
 */
final class Server
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly string $name = 'nativa-rest-admin',
        private readonly string $version = '0.1.0',
        private readonly string $protocolVersion = '2024-11-05',
    ) {
    }

    /**
     * @return list<array<string, mixed>>|null one response per request, or null for a notification
     */
    public function handle(string $json): ?array
    {
        $decoded = json_decode($json, false);

        if (JSON_ERROR_NONE !== json_last_error()) {
            return [$this->error(null, -32_700, 'Parse error: the line is not JSON.')];
        }

        if (is_array($decoded)) {
            if ([] === $decoded) {
                return [$this->error(null, -32_600, 'Invalid Request: an empty batch.')];
            }

            $responses = [];

            foreach ($decoded as $request) {
                $response = $this->one($request);

                if (null !== $response) {
                    $responses[] = $response;
                }
            }

            return $responses;
        }

        $response = $this->one($decoded);

        return null === $response ? null : [$response];
    }

    /**
     * @param resource $in
     * @param resource $out
     */
    public function serve($in, $out): void
    {
        while (false !== ($line = fgets($in))) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            $responses = $this->handle($line);

            if (null === $responses || [] === $responses) {
                continue;
            }

            $payload = 1 === count($responses) ? $responses[0] : $responses;
            fwrite($out, (json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}') . "\n");
            fflush($out);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function one(mixed $request): ?array
    {
        if (!$request instanceof \stdClass) {
            return $this->error(null, -32_600, 'Invalid Request: a request must be a JSON object.');
        }

        $id = property_exists($request, 'id') ? $request->id : null;
        $notification = !property_exists($request, 'id');
        $method = $request->method ?? null;

        if (!is_string($method) || '' === $method) {
            return $notification ? null : $this->error($id, -32_600, 'Invalid Request: "method" must be a string.');
        }

        if ('2.0' !== ($request->jsonrpc ?? null)) {
            return $notification ? null : $this->error($id, -32_600, 'Invalid Request: "jsonrpc" must be "2.0".');
        }

        try {
            $result = $this->dispatch($method, $request->params ?? null);
        } catch (UnknownToolException $exception) {
            return $notification ? null : $this->error($id, -32_602, $exception->getMessage());
        } catch (MethodNotFoundException $exception) {
            return $notification ? null : $this->error($id, -32_601, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            return $notification ? null : $this->error($id, -32_602, $exception->getMessage());
        }

        if ($notification) {
            return null;
        }

        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ];
    }

    private function dispatch(string $method, mixed $params): mixed
    {
        return match ($method) {
            'initialize' => [
                'protocolVersion' => $this->protocolVersion,
                'capabilities'    => ['tools' => new \stdClass()],
                'serverInfo'      => ['name' => $this->name, 'version' => $this->version],
            ],
            'ping'               => new \stdClass(),
            'notifications/initialized' => new \stdClass(),
            'tools/list'         => ['tools' => $this->tools->list()],
            'tools/call'         => $this->callTool($params),
            default              => throw new MethodNotFoundException($method),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function callTool(mixed $params): array
    {
        if (!$params instanceof \stdClass) {
            throw new \InvalidArgumentException('tools/call needs params: {"name": "…", "arguments": {…}}.');
        }

        $name = $params->name ?? null;

        if (!is_string($name) || '' === $name) {
            throw new \InvalidArgumentException('tools/call needs a "name".');
        }

        $arguments = $params->arguments ?? new \stdClass();

        if (!$arguments instanceof \stdClass) {
            throw new \InvalidArgumentException('tools/call "arguments" must be an object.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = $this->toArray($arguments);

        return $this->tools->call($name, $decoded)->toArray();
    }

    /**
     * JSON decodes objects as stdClass; the tools read named keys, so nested
     * objects (`data`, `field`, `fields[]`) are turned into arrays once, here.
     */
    private function toArray(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $converted = [];

        foreach ($value as $key => $item) {
            $converted[$key] = $this->toArray($item);
        }

        return $converted;
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ];
    }
}
