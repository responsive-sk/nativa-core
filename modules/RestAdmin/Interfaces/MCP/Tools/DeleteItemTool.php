<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP\Tools;

use Modules\RestAdmin\Application\Mapping\ApiException;
use Modules\RestAdmin\Application\Mapping\ErrorMapper;
use Modules\RestAdmin\Application\Mapping\RequestFactory;
use Modules\RestAdmin\Interfaces\MCP\AbstractTool;
use Modules\RestAdmin\Interfaces\MCP\Schemas;
use Modules\RestAdmin\Interfaces\MCP\ToolResult;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * DeleteItemTool — the one irreversible tool, so it asks out loud.
 *
 * Two independent refusals: the resource must allow `delete` (the spec's
 * opt-in), and the call must carry `confirm: true` (the caller's intent). A
 * remote DELETE cannot be undone by retrying the conversation, so the
 * confirmation is not ceremony — it is the only place the model has to say
 * "yes, this one" rather than inferring it from a previous turn.
 */
final class DeleteItemTool extends AbstractTool
{
    public function name(): string
    {
        return 'delete_item';
    }

    public function description(): string
    {
        return 'Delete one item. Requires confirm=true and a resource whose actions include delete.';
    }

    public function schema(): array
    {
        return Schemas::object([
            'service_id' => Schemas::text('Registered service id'),
            'resource'   => Schemas::text('Resource name'),
            'id'         => Schemas::text('Item id'),
            'confirm'    => Schemas::boolean('Must be true — this operation cannot be undone'),
        ], ['service_id', 'resource', 'id', 'confirm']);
    }

    public function call(array $arguments): ToolResult
    {
        $spec = $this->service($arguments);
        $resource = $this->resource($spec, $this->requireString($arguments, 'resource'));

        if (!$resource->actions->allows('delete')) {
            throw new \InvalidArgumentException(sprintf(
                'Resource "%s" does not allow deletes. Add "delete" to its actions to allow them.',
                $resource->name,
            ));
        }

        if (!$this->confirm($arguments)) {
            throw new \InvalidArgumentException('Deleting an item requires confirm=true; the remote DELETE cannot be undone.');
        }

        $response = RestAdminServices::transport()->send(
            (new RequestFactory())->delete($spec->service, $resource, $this->requireString($arguments, 'id')),
        );

        if (!$response->isSuccess()) {
            throw new ApiException((new ErrorMapper())->map($response));
        }

        return ToolResult::ok([
            'service'  => $spec->service->id,
            'resource' => $resource->name,
            'status'   => $response->status,
            'deleted'  => true,
        ]);
    }
}
