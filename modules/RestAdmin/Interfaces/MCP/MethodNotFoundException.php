<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP;

/**
 * MethodNotFoundException — JSON-RPC `-32601`, not `-32602`.
 *
 * `dispatch()`'s default arm used to throw `InvalidArgumentException`, which
 * the server mapped to invalid params. A client then "fixed" a method name that
 * does not exist in this protocol version instead of falling back to
 * `tools/list`. The two are different failures and get different codes.
 */
final class MethodNotFoundException extends \RuntimeException
{
    public function __construct(public readonly string $method)
    {
        parent::__construct(sprintf('Method not found: "%s".', $method));
    }
}
