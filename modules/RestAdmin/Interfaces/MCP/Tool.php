<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP;

/**
 * Tool — one callable operation in the REST admin's MCP surface.
 *
 * The schema is the contract a model reads before calling: an object with
 * named properties, no positional shorthand. `additionalProperties` is false
 * because a silently ignored argument looks like a setting that was applied;
 * the panel's forms have the same rule for the same reason.
 */
interface Tool
{
    public function name(): string;

    public function description(): string;

    /**
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws \RuntimeException when the service refuses the operation
     */
    public function call(array $arguments): ToolResult;
}
