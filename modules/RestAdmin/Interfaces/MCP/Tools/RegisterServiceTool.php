<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP\Tools;

use Modules\RestAdmin\Interfaces\MCP\AbstractTool;
use Modules\RestAdmin\Interfaces\MCP\Schemas;
use Modules\RestAdmin\Interfaces\MCP\ToolResult;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * RegisterServiceTool — add one remote API to the specification.
 *
 * `id` is optional: when omitted it is derived from the name, so the common
 * call is "register this API" rather than "invent an identifier first". The
 * token argument is the NAME of an environment variable, and the description
 * says so twice — a model that puts a token value here would store a secret in
 * restadmin.db, which the spec model exists to prevent.
 */
final class RegisterServiceTool extends AbstractTool
{
    public function name(): string
    {
        return 'register_service';
    }

    public function description(): string
    {
        return 'Register a REST API. The token argument is the NAME of an environment variable (for example RESTADMIN_BLOG_TOKEN), never the token value.';
    }

    public function schema(): array
    {
        return Schemas::object([
            'name'            => Schemas::text('Display name, e.g. Blog'),
            'base_url'        => Schemas::text('Base URL every resource path resolves against, e.g. https://api.example.test'),
            'id'              => Schemas::text('Optional id; derived from the name when omitted'),
            'token_env'       => Schemas::text('Environment variable that holds the token — the name, not the value'),
            'auth_header'     => Schemas::text('Header that carries the token; defaults to Authorization with a Bearer scheme'),
            'timeout_seconds' => Schemas::integer('Request timeout in seconds; defaults to 10'),
            'headers'         => ['type' => 'object', 'description' => 'Extra request headers as name => string'],
        ], ['name', 'base_url']);
    }

    public function call(array $arguments): ToolResult
    {
        $name = $this->requireString($arguments, 'name');
        $baseUrl = $this->requireString($arguments, 'base_url');
        $id = $this->optionalString($arguments, 'id') ?? self::slug($name);

        $document = [
            'service' => [
                'id'              => $id,
                'name'            => $name,
                'base_url'        => $baseUrl,
                'auth'            => [
                    'header'    => $this->optionalString($arguments, 'auth_header'),
                    'token_env' => $this->optionalString($arguments, 'token_env'),
                ],
                'timeout_seconds' => $this->optionalInt($arguments, 'timeout_seconds', 10),
                'headers'         => $this->stringMap($this->optionalArray($arguments, 'headers')),
            ],
            'resources' => [],
        ];

        $spec = RestAdminServices::validator()->validate($document);
        RestAdminServices::save($spec);

        return ToolResult::ok([
            'id'        => $spec->service->id,
            'name'      => $spec->service->name,
            'base_url'  => $spec->service->baseUrl,
            'token_env' => $spec->service->tokenEnv(),
        ]);
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    private function stringMap(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new \InvalidArgumentException('Every header needs a name and a string value.');
            }

            $normalised[$key] = $value;
        }

        return $normalised;
    }

    private static function slug(string $name): string
    {
        return trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? ''), '-') ?: 'service';
    }
}
