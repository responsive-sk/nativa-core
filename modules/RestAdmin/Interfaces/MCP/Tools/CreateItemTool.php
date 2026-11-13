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
 * CreateItemTool — POST one item, only to a resource that allows it.
 *
 * `data` is checked against the resource's declared fields when it has any:
 * a key the spec does not name is a typo the panel would have prevented with
 * a form, and accepting it here would send an arbitrary property to the remote
 * API. Read-only fields are refused rather than silently dropped, because a
 * dropped value looks like a successful write of something else.
 */
final class CreateItemTool extends AbstractTool
{
    public function name(): string
    {
        return 'create_item';
    }

    public function description(): string
    {
        return 'Create one item in a resource that allows create. The data keys must be declared fields; read-only fields are refused.';
    }

    public function schema(): array
    {
        return Schemas::object([
            'service_id' => Schemas::text('Registered service id'),
            'resource'   => Schemas::text('Resource name'),
            'data'       => ['type' => 'object', 'description' => 'Field name => value'],
        ], ['service_id', 'resource', 'data']);
    }

    public function call(array $arguments): ToolResult
    {
        $spec = $this->service($arguments);
        $resource = $this->resource($spec, $this->requireString($arguments, 'resource'));

        if (!$resource->actions->allows('create')) {
            throw new \InvalidArgumentException(sprintf(
                'Resource "%s" is read-only. Add "create" to its actions to allow writes.',
                $resource->name,
            ));
        }

        $data = $this->data($resource, $this->requireArray($arguments, 'data'));
        $response = RestAdminServices::transport()->send(
            (new RequestFactory())->create($spec->service, $resource, $data),
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
                    'Field "%s" is not declared on resource "%s". Use list_resources to see the fields.',
                    (string) $key,
                    $resource->name,
                ));
            }

            if ($declared[$key]->readOnly) {
                throw new \InvalidArgumentException(sprintf('Field "%s" is read-only and cannot be created.', $key));
            }
        }

        return $data;
    }
}
