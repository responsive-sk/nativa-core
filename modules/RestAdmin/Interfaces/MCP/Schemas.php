<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP;

/**
 * Schemas — the JSON-schema fragments every tool builds its `inputSchema` from.
 *
 * Separate from `AbstractTool` because PHP 8.5.12 segfaults while loading a
 * `TestCase`-adjacent class that combines the argument helpers with these five
 * builders (measured 2026-11-13: the class loads with eleven methods and dies
 * with the twelfth, whichever method is twelfth). The split is not cosmetic —
 * it is the smallest shape that runs — and it puts the wire vocabulary in one
 * file rather than fifteen.
 */
final class Schemas
{
    /**
     * @param array<string, mixed> $properties
     * @param list<string>         $required
     *
     * @return array<string, mixed>
     */
    public static function object(array $properties, array $required = []): array
    {
        return [
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function text(string $description): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    public static function integer(string $description): array
    {
        return ['type' => 'integer', 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    public static function boolean(string $description): array
    {
        return ['type' => 'boolean', 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    public static function listOf(string $description): array
    {
        return ['type' => 'array', 'description' => $description, 'items' => ['type' => 'string']];
    }
}
