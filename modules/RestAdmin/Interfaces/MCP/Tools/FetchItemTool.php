<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP\Tools;

use Modules\RestAdmin\Interfaces\MCP\AbstractTool;
use Modules\RestAdmin\Interfaces\MCP\Schemas;
use Modules\RestAdmin\Interfaces\MCP\ToolResult;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * FetchItemTool — one member, with the id percent-encoded by `RequestFactory`.
 *
 * The id is taken as a string and never concatenated into a URL here: an id
 * containing `/` or `?` must address the member the list showed, not a
 * different endpoint.
 */
final class FetchItemTool extends AbstractTool
{
    public function name(): string
    {
        return 'fetch_item';
    }

    public function description(): string
    {
        return 'Read one item by id from a resource. Returns the raw object the API sent.';
    }

    public function schema(): array
    {
        return Schemas::object([
            'service_id' => Schemas::text('Registered service id'),
            'resource'   => Schemas::text('Resource name'),
            'id'         => Schemas::text('Item id'),
        ], ['service_id', 'resource', 'id']);
    }

    public function call(array $arguments): ToolResult
    {
        $spec = $this->service($arguments);
        $resource = $this->resource($spec, $this->requireString($arguments, 'resource'));
        $item = RestAdminServices::itemReader()->read(
            $spec->service,
            $resource,
            $this->requireString($arguments, 'id'),
        );

        return ToolResult::ok([
            'service'  => $spec->service->id,
            'resource' => $resource->name,
            'item'     => $item,
        ]);
    }
}
