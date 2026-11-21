<?php

declare(strict_types = 1);

namespace Modules\RestApi\Interfaces\MCP\Tools;

use Modules\RestApi\Application\Mapping\ListReader;
use Modules\RestApi\Application\Mapping\RequestFactory;
use Modules\RestApi\Interfaces\MCP\AbstractTool;
use Modules\RestApi\Interfaces\MCP\Schemas;
use Modules\RestApi\Interfaces\MCP\ToolResult;
use Modules\RestApi\Interfaces\HTTP\RestApiServices;

/**
 * FetchItemsTool — one page of a resource, as the service answered.
 *
 * Rows are returned in the API's shape rather than mapped through `FieldSpec`:
 * the caller is a model that may want a key the panel does not display, and
 * `fetch_item` has the same rule. The page state is returned beside them, so a
 * caller knows whether another page exists without guessing from a row count.
 */
final class FetchItemsTool extends AbstractTool
{
    public function name(): string
    {
        return 'fetch_items';
    }

    public function description(): string
    {
        return 'Read one page of a resource. Returns the rows, pagination state, and whether the collection has more.';
    }

    public function schema(): array
    {
        return Schemas::object([
            'service_id' => Schemas::text('Registered service id'),
            'resource'   => Schemas::text('Resource name'),
            'page'       => Schemas::integer('Page number; defaults to 1'),
            'cursor'     => Schemas::text('Cursor for a cursor-paginated resource'),
            'per_page'   => Schemas::integer('Page size for a page-paginated resource; defaults to 25, max 100'),
        ], ['service_id', 'resource']);
    }

    public function call(array $arguments): ToolResult
    {
        $spec = $this->service($arguments);
        $resource = $this->resource($spec, $this->requireString($arguments, 'resource'));
        $perPage = max(1, min(100, $this->optionalInt($arguments, 'per_page', 25)));

        $reader = new ListReader(
            RestApiServices::transport(),
            requests: new RequestFactory($perPage),
        );
        $page = $reader->read(
            $spec->service,
            $resource,
            max(1, $this->optionalInt($arguments, 'page', 1)),
            $this->optionalString($arguments, 'cursor'),
        );

        return ToolResult::ok([
            'service'  => $spec->service->id,
            'resource' => $resource->name,
            'items'    => $page->items,
            'page'     => $page->state->toArray(),
        ]);
    }
}
