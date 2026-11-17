<?php

declare(strict_types = 1);

namespace Modules\RestPanel\Interfaces\MCP\Tools;

use Modules\RestPanel\Domain\Model\FieldSpec;
use Modules\RestPanel\Domain\Model\ResourceSpec;
use Modules\RestPanel\Domain\Model\ServiceSpec;
use Modules\RestPanel\Interfaces\MCP\AbstractTool;
use Modules\RestPanel\Interfaces\MCP\Schemas;
use Modules\RestPanel\Interfaces\MCP\ToolResult;
use Modules\RestPanel\Interfaces\HTTP\RestPanelServices;

/**
 * RemoveFieldTool — stop declaring one API key.
 *
 * Removing a field never touches the remote API; it removes the panel's
 * knowledge of the key. The whole document is re-validated before the save, so
 * a removal that would leave a relation pointing at the field still changes
 * nothing.
 */
final class RemoveFieldTool extends AbstractTool
{
    public function name(): string
    {
        return 'remove_field';
    }

    public function description(): string
    {
        return 'Remove a field declaration from a resource. The remote API is not called.';
    }

    public function schema(): array
    {
        return Schemas::object([
            'service_id' => Schemas::text('Registered service id'),
            'resource'   => Schemas::text('Resource name'),
            'field'      => Schemas::text('Field name to remove'),
        ], ['service_id', 'resource', 'field']);
    }

    public function call(array $arguments): ToolResult
    {
        $spec = $this->service($arguments);
        $resource = $this->resource($spec, $this->requireString($arguments, 'resource'));
        $name = $this->requireString($arguments, 'field');

        $remaining = array_values(array_filter(
            $resource->fields,
            static fn (FieldSpec $field): bool => $field->name !== $name,
        ));

        if (count($remaining) === count($resource->fields)) {
            throw new \InvalidArgumentException(sprintf('Resource "%s" has no field "%s".', $resource->name, $name));
        }

        $updated = $this->withFields($spec, $resource, $remaining);
        RestPanelServices::save($updated);

        return ToolResult::ok([
            'resource' => $resource->name,
            'fields'   => array_map(static fn (FieldSpec $field): string => $field->name, $remaining),
        ]);
    }

    /**
     * @param list<FieldSpec> $fields
     */
    private function withFields(ServiceSpec $spec, ResourceSpec $resource, array $fields): ServiceSpec
    {
        $replacement = new ResourceSpec(
            serviceId: $spec->service->id,
            name: $resource->name,
            path: $resource->path,
            itemPath: $resource->itemPath,
            idField: $resource->idField,
            fields: $fields,
            actions: $resource->actions,
            pagination: $resource->pagination,
            relations: $resource->relations,
        );

        return RestPanelServices::validator()->validate([
            'service'   => $spec->service->toArray(),
            'resources' => array_map(
                static fn (ResourceSpec $candidate): array => $candidate->name === $replacement->name
                    ? $replacement->toArray()
                    : $candidate->toArray(),
                $spec->resources,
            ),
        ]);
    }
}
