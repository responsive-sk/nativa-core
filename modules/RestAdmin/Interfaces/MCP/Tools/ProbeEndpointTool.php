<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP\Tools;

use Modules\RestAdmin\Application\Mapping\ListReader;
use Modules\RestAdmin\Infrastructure\Http\HttpRequestSpec;
use Modules\RestAdmin\Interfaces\MCP\AbstractTool;
use Modules\RestAdmin\Interfaces\MCP\Schemas;
use Modules\RestAdmin\Interfaces\MCP\ToolResult;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * ProbeEndpointTool — one read, reported as status and shape.
 *
 * With a `resource`, the call goes through `ListReader`, so it proves the whole
 * mapping path (envelope, pagination, error mapping), not just reachability.
 * Without one, it asks the service's base URL for a status.
 *
 * The answer deliberately does not echo the response body: a probe is a
 * connectivity check, and an API's first page may carry customer data the
 * operator did not ask to copy into a transcript. `rows` and `status` are
 * enough to tell "reachable and shaped as declared" from "reachable, wrong
 * shape".
 */
final class ProbeEndpointTool extends AbstractTool
{
    public function name(): string
    {
        return 'probe_endpoint';
    }

    public function description(): string
    {
        return 'Read a resource once (or the base URL) and report the HTTP status and the mapped page shape. Does not return the body.';
    }

    public function schema(): array
    {
        return Schemas::object([
            'service_id' => Schemas::text('Registered service id'),
            'resource'   => Schemas::text('Optional resource name; omit to probe the base URL'),
            'page'       => Schemas::integer('Page number for a page-shaped collection; defaults to 1'),
        ], ['service_id']);
    }

    public function call(array $arguments): ToolResult
    {
        $spec = $this->service($arguments);
        $resourceName = $this->optionalString($arguments, 'resource');

        if (null === $resourceName) {
            $url = rtrim($spec->service->baseUrl, '/');
            $response = RestAdminServices::transport()->send(new HttpRequestSpec(
                'GET',
                $url,
                (new \Modules\RestAdmin\Application\Mapping\RequestFactory())->headers($spec->service),
            ));

            return ToolResult::ok([
                'service'      => $spec->service->id,
                'url'          => $url,
                'status'       => $response->status,
                'content_type' => $response->headers['content-type'] ?? null,
            ]);
        }

        $resource = $this->resource($spec, $resourceName);
        $page = (new ListReader(RestAdminServices::transport()))->read(
            $spec->service,
            $resource,
            max(1, $this->optionalInt($arguments, 'page', 1)),
        );

        return ToolResult::ok([
            'service'    => $spec->service->id,
            'resource'   => $resource->name,
            'rows'       => count($page->items),
            'page'       => $page->state->toArray(),
            'envelope'   => array_keys($page->payload),
        ]);
    }
}
