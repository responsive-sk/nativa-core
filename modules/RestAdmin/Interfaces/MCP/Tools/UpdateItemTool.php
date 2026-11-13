<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP\Tools;

use Modules\RestAdmin\Application\Mapping\ApiException;
use Modules\RestAdmin\Application\Mapping\ErrorMapper;
use Modules\RestAdmin\Application\Mapping\RequestFactory;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Interfaces\MCP\AbstractTool;
use Modules\RestAdmin\Interfaces\MCP\Schemas;
use Modules\RestAdmin\Interfaces\MCP\ToolResult;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * UpdateItemTool — PATCH one item, only to a resource that allows update.
 *
 * The same field allowlist as `create_item`, for the same reason. The request
 * is a PATCH: an id field sent in `data` is refused by the allowlist only when
 * the spec marks it read-only, so marking an id read-only is how an operator
 * stops a rename, rather than the transport guessing.
 */
final class UpdateItemTool extends AbstractTool
{
    public function name(): string
    {
        return 'update_item';
    }

    public function description(): string
    {
        return 'Update one item in a resource that allows update. Sends a PATCH with the declared fields in data.';
    }

    public function schema(): array
    {
        return Schemas::object([
            'service_id' => Schemas::text('Registered service id'),
            'resource'   => Schemas::text('Resource name'),
            'id'         => Schemas::text('Item id'),
            'data'       => ['type' => 'object', 'description' => 'Field name => value'],
        ], ['service_id', 'resource', 'id', 'data']);
    }

    public function call(array $arguments): ToolResult
    {
        $spec = $this->service($arguments);
        $resource = $this->resource($spec, $this->requireString($arguments, 'resource'));

        if (!$resource->actions->allows('update')) {
            throw new \InvalidArgumentException(sprintf(
                'Resource "%s" does not allow updates. Add "update" to its actions to allow them.',
                $resource->name,
            ));
        }

        $data = $this->data($resource, $this->requireArray($arguments, 'data'));
        $response = RestAdminServices::transport()->send(
            (new RequestFactory())->update($spec->service, $resource, $this->requireString($arguments, 'id'), $data),
        );

        if (!$response->isSuccess()) {
            throw new ApiException((new ErrorMapper())->map($response));
        }

        return ToolResult::ok([
            'service'  => $spec->service->id,
            'resource' => $resource->name,
            'status'   => $response->status,
            'response' => json_decode($response->body, true),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function data(ResourceSpec $resource, array $data): array
    {
        if ([] === $resource->fields) {
            return $data;
        }

        $declared = [];

        foreach ($resource->fields as $field) {
            $declared[$field->name] = $field;
        }

        foreach ($data as $key => $value) {
            if (!is_string($key) || !isset($declared[$key])) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" is not declared on resource "%s".',
                    (string) $key,
                    $resource->name,
                ));
            }

            if ($declared[$key]->readOnly) {
                throw new \InvalidArgumentException(sprintf('Field "%s" is read-only and cannot be updated.', $key));
            }
        }

        return $data;
    }
}
