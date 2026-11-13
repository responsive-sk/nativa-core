<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP\Tools;

use Modules\RestAdmin\Domain\Model\FieldSpec;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Interfaces\MCP\AbstractTool;
use Modules\RestAdmin\Interfaces\MCP\Schemas;
use Modules\RestAdmin\Interfaces\MCP\ToolResult;

/**
 * ListResourcesTool — one service's resources with their full field list.
 *
 * `list_services` summarises; this answers the question a caller has before it
 * writes an item ("which keys may I send?"), including each field's type and
 * whether it is read-only.
 */
final class ListResourcesTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_resources';
    }

    public function description(): string
    {
        return 'List one service’s resources, paths, actions, pagination strategy and fields (name, type, required, read-only).';
    }

    public function schema(): array
    {
        return Schemas::object([
            'service_id' => Schemas::text('Registered service id'),
        ], ['service_id']);
    }

    public function call(array $arguments): ToolResult
    {
        $spec = $this->service($arguments);

        $resources = array_map(
            static fn (ResourceSpec $resource): array => [
                'name'       => $resource->name,
                'path'       => $resource->path,
                'item_path'  => $resource->itemPath,
                'id_field'   => $resource->idField,
                'actions'    => $resource->actions->toArray(),
                'pagination' => $resource->pagination->strategy,
                'fields'     => array_map(
                    static fn (FieldSpec $field): array => [
                        'name'      => $field->name,
                        'label'     => $field->label,
                        'type'      => $field->type->value,
                        'required'  => $field->required,
                        'read_only' => $field->readOnly,
                        'in_list'   => $field->inList,
                    ],
                    $resource->fields,
                ),
            ],
            $spec->resources,
        );

        return ToolResult::ok([
            'service'   => $spec->service->id,
            'resources' => $resources,
        ]);
    }
}
