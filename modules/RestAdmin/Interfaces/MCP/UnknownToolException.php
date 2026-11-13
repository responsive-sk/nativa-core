<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP;

/**
 * UnknownToolException — `tools/call` named a tool this server does not have.
 *
 * Separate from a tool failure: JSON-RPC `-32602` says the request was wrong,
 * so a client should fix the call, not retry it as if the service had refused.
 */
final class UnknownToolException extends \RuntimeException
{
    public function __construct(public readonly string $toolName)
    {
        parent::__construct(sprintf('Unknown tool "%s". Call tools/list to see the names.', $toolName));
    }
}
