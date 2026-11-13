<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP\Tools;

use Modules\RestAdmin\Domain\Model\ActionSet;
use Modules\RestAdmin\Domain\Model\PaginationSpec;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Interfaces\MCP\AbstractTool;
use Modules\RestAdmin\Interfaces\MCP\Schemas;
use Modules\RestAdmin\Interfaces\MCP\ToolResult;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * UpdateResourceTool — change one resource, deliberately.
 *
 * `actions` is the permission surface, so widening it requires `confirm: true`
 * even though the tool is not deleting anything: adding `delete` to a resource
 * hands every later caller a capability the operator did not approve in the
 * same breath as "change the path". The refusal names the confirmation.
 */
final class UpdateResourceTool extends AbstractTool
{
    public function name(): string
    {
        return 'update_resource';
    }

    public function description(): string
    {
        return 'Change a resource path, item path, id field, pagination, fields or actions. Changing actions requires confirm=true.';
    }

    public function schema(): array
    {
        return Schemas::object([
            'service_id' => Schemas::text('Registered service id'),
            'name'       => Schemas::text('Resource to update'),
            'path'       => Schemas::text('New collection path'),
            'item_path'  => Schemas::text('New item path containing {id}'),
            'id_field'   => Schemas::text('New id field'),
            'pagination' => Schemas::text('page, link, cursor or none'),
            'actions'    => Schemas::listOf('New action list; requires confirm=true'),
            'fields'     => ['type' => 'array', 'description' => 'Replacement field list', 'items' => ['type' => 'object']],
            'confirm'    => Schemas::boolean('Must be true when actions change'),
        ], ['service_id', 'name']);
    }

    public function call(array $arguments): ToolResult
    {
        $spec = $this->service($arguments);
        $current = $this->resource($spec, $this->requireString($arguments, 'name'));
        $actions = array_key_exists('actions', $arguments) ? $this->actions($arguments['actions']) : $current->actions->toArray();

        if (array_key_exists('actions', $arguments) && !$this->confirm($arguments)) {
            throw new \InvalidArgumentException('Changing "actions" requires confirm=true: it changes what later callers may do.');
        }

        $fields = $current->fields;

        if (array_key_exists('fields', $arguments)) {
            $input = $arguments['fields'];

            if (!is_array($input)) {
                throw new \InvalidArgumentException('"fields" must be a list of field objects.');
            }

            $fields = $this->fields(array_values(array_map(
                static fn (mixed $field): array => is_array($field) ? $field : [],
                $input,
            )));
        }

        $updated = new ResourceSpec(
            serviceId: $spec->service->id,
            name: $current->name,
            path: $this->optionalString($arguments, 'path') ?? $current->path,
            itemPath: $this->optionalString($arguments, 'item_path') ?? $current->itemPath,
            idField: $this->optionalString($arguments, 'id_field') ?? $current->idField,
            fields: $fields,
            actions: new ActionSet($actions),
            pagination: new PaginationSpec($this->optionalString($arguments, 'pagination') ?? $current->pagination->strategy),
            relations: $current->relations,
        );

        $document = [
            'service'   => $spec->service->toArray(),
            'resources' => array_map(
                static fn (ResourceSpec $resource): array => $resource->name === $updated->name
                    ? $updated->toArray()
                    : $resource->toArray(),
                $spec->resources,
            ),
        ];

        $saved = RestAdminServices::validator()->validate($document);
        RestAdminServices::save($saved);

        return ToolResult::ok([
            'service'   => $saved->service->id,
            'resource'  => $updated->name,
            'path'      => $updated->path,
            'item_path' => $updated->itemPath,
            'actions'   => $updated->actions->toArray(),
        ]);
    }
}
