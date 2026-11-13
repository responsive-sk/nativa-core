<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP\Tools;

use Modules\RestAdmin\Domain\Model\FieldSpec;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Domain\Model\ServiceSpec;
use Modules\RestAdmin\Interfaces\MCP\AbstractTool;
use Modules\RestAdmin\Interfaces\MCP\Schemas;
use Modules\RestAdmin\Interfaces\MCP\ToolResult;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * RenameFieldTool — change the key or the human label of one field.
 *
 * The two are separate on purpose: changing `name` changes which API key is
 * read, while changing `label` changes only what the panel prints. A tool that
 * mixed them would make a cosmetic edit look like a mapping change.
 */
final class RenameFieldTool extends AbstractTool
{
    public function name(): string
    {
        return 'rename_field';
    }

    public function description(): string
    {
        return 'Rename a field key (new_name) or its display label (label). At least one is required.';
    }

    public function schema(): array
    {
        return Schemas::object([
            'service_id' => Schemas::text('Registered service id'),
            'resource'   => Schemas::text('Resource name'),
            'field'      => Schemas::text('Current field name'),
            'new_name'   => Schemas::text('New API key'),
            'label'      => Schemas::text('New human-readable label'),
        ], ['service_id', 'resource', 'field']);
    }

    public function call(array $arguments): ToolResult
    {
        $spec = $this->service($arguments);
        $resource = $this->resource($spec, $this->requireString($arguments, 'resource'));
        $currentName = $this->requireString($arguments, 'field');
        $newName = $this->optionalString($arguments, 'new_name');
        $label = $this->optionalString($arguments, 'label');

        if (null === $newName && null === $label) {
            throw new \InvalidArgumentException('Give new_name, label, or both.');
        }

        $replacement = null;
        $fields = [];

        foreach ($resource->fields as $field) {
            if ($field->name !== $currentName) {
                if (null !== $newName && $field->name === $newName) {
                    throw new \InvalidArgumentException(sprintf('Resource "%s" already has a field "%s".', $resource->name, $newName));
                }

                $fields[] = $field;

                continue;
            }

            $replacement = new FieldSpec(
                name: $newName ?? $field->name,
                label: $label ?? $field->label,
                type: $field->type,
                required: $field->required,
                readOnly: $field->readOnly,
                inList: $field->inList,
            );
            $fields[] = $replacement;
        }

        if (null === $replacement) {
            throw new \InvalidArgumentException(sprintf('Resource "%s" has no field "%s".', $resource->name, $currentName));
        }

        $updated = $this->withFields($spec, $resource, $fields);
        RestAdminServices::save($updated);

        return ToolResult::ok([
            'resource' => $resource->name,
            'from'     => $currentName,
            'to'       => $replacement->name,
            'label'    => $replacement->label,
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
