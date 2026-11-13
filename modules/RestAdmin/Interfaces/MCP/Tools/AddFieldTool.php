<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP\Tools;

use Modules\RestAdmin\Domain\Model\FieldSpec;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Interfaces\MCP\AbstractTool;
use Modules\RestAdmin\Interfaces\MCP\Schemas;
use Modules\RestAdmin\Interfaces\MCP\ToolResult;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * AddFieldTool — declare one more API key the panel should read.
 *
 * Adding a field is additive: the write happens only after the whole service
 * document passes `SpecValidator`, so a field with an unknown type or a
 * duplicate name changes nothing at all.
 */
final class AddFieldTool extends AbstractTool
{
    public function name(): string
    {
        return 'add_field';
    }

    public function description(): string
    {
        return 'Add one typed field to a resource. Fields are the API keys the panel lists, reads and writes.';
    }

    public function schema(): array
    {
        return Schemas::object([
            'service_id' => Schemas::text('Registered service id'),
            'resource'   => Schemas::text('Resource name'),
            'field'      => [
                'type'        => 'object',
                'description' => 'name, label, type (text/textarea/number/checkbox/select/date/json/relation), required, read_only, in_list',
            ],
        ], ['service_id', 'resource', 'field']);
    }

    public function call(array $arguments): ToolResult
    {
        $spec = $this->service($arguments);
        $resource = $this->resource($spec, $this->requireString($arguments, 'resource'));
        $field = $this->requireArray($arguments, 'field');
        $newField = $this->fields([$field])[0];

        foreach ($resource->fields as $existing) {
            if ($existing->name === $newField->name) {
                throw new \InvalidArgumentException(sprintf(
                    'Resource "%s" already declares field "%s". Use rename_field to change it.',
                    $resource->name,
                    $newField->name,
                ));
            }
        }

        $updated = $this->withFields($spec, $resource, [...$resource->fields, $newField]);
        RestAdminServices::save($updated);

        $saved = $this->resource($updated, $resource->name);

        return ToolResult::ok([
            'resource' => $saved->name,
            'fields'   => array_map(static fn (FieldSpec $field): string => $field->name, $saved->fields),
        ]);
    }

    /**
     * @param list<FieldSpec> $fields
     */
    private function withFields(\Modules\RestAdmin\Domain\Model\ServiceSpec $spec, ResourceSpec $resource, array $fields): \Modules\RestAdmin\Domain\Model\ServiceSpec
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

        return RestAdminServices::validator()->validate([
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
