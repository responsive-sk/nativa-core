<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP\Tools;

use Modules\RestAdmin\Domain\Model\ServiceSpec;
use Modules\RestAdmin\Interfaces\MCP\AbstractTool;
use Modules\RestAdmin\Interfaces\MCP\Schemas;
use Modules\RestAdmin\Interfaces\MCP\ToolResult;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * CreateResourceTool — append a collection to a service.
 *
 * `actions` defaults to `list, read`. That default is the whole write-safety
 * story (plan D6): a model that says `create_resource` has not thereby said
 * "and delete my data", so the write actions have to be named.
 */
final class CreateResourceTool extends AbstractTool
{
    public function name(): string
    {
        return 'create_resource';
    }

    public function description(): string
    {
        return 'Add a resource (collection) to a service. Actions default to list and read; name create/update/delete to allow writes.';
    }

    public function schema(): array
    {
        return Schemas::object([
            'service_id' => Schemas::text('Registered service id'),
            'name'       => Schemas::text('Resource name the panel addresses it by, e.g. posts'),
            'path'       => Schemas::text('Collection path relative to the base URL, e.g. /posts'),
            'item_path'  => Schemas::text('Item path containing {id}; defaults to path + /{id}'),
            'id_field'   => Schemas::text('API key that fills {id}; defaults to id'),
            'actions'    => Schemas::listOf('list, read, create, update, delete'),
            'pagination' => Schemas::text('page, link, cursor or none; defaults to page'),
            'fields'     => [
                'type'        => 'array',
                'description' => 'Fields: name, label, type, required, read_only, in_list',
                'items'       => ['type' => 'object'],
            ],
        ], ['service_id', 'name', 'path']);
    }

    public function call(array $arguments): ToolResult
    {
        $spec = $this->service($arguments);
        $name = $this->requireString($arguments, 'name');
        $path = $this->requireString($arguments, 'path');
        $itemPath = $this->optionalString($arguments, 'item_path') ?? rtrim($path, '/') . '/{id}';

        $this->assertNameIsNew($spec, $name);

        $document = [
            'service'   => $spec->service->toArray(),
            'resources' => array_merge(
                array_map(static fn ($resource): array => $resource->toArray(), $spec->resources),
                [[
                    'name'       => $name,
                    'path'       => $path,
                    'item_path'  => $itemPath,
                    'id_field'   => $this->optionalString($arguments, 'id_field') ?? 'id',
                    'actions'    => $this->actions($arguments['actions'] ?? null),
                    'pagination' => $this->optionalString($arguments, 'pagination') ?? 'page',
                    'fields'     => array_map(
                        static fn (\Modules\RestAdmin\Domain\Model\FieldSpec $field): array => $field->toArray(),
                        $this->fields($this->fieldInput($arguments)),
                    ),
                    'relations'  => [],
                ]],
            ),
        ];

        $updated = RestAdminServices::validator()->validate($document);
        RestAdminServices::save($updated);
        $resource = $this->resource($updated, $name);

        return ToolResult::ok([
            'service'   => $updated->service->id,
            'resource'  => $resource->name,
            'path'      => $resource->path,
            'item_path' => $resource->itemPath,
            'actions'   => $resource->actions->toArray(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fieldInput(array $arguments): array
    {
        $fields = $arguments['fields'] ?? [];

        if (!is_array($fields)) {
            throw new \InvalidArgumentException('"fields" must be a list of field objects.');
        }

        return array_values(array_map(
            static fn (mixed $field): array => is_array($field) ? $field : [],
            $fields,
        ));
    }

    private function assertNameIsNew(ServiceSpec $spec, string $name): void
    {
        foreach ($spec->resources as $resource) {
            if ($resource->name === $name) {
                throw new \InvalidArgumentException(sprintf(
                    'Service "%s" already has a resource "%s". Use update_resource to change it.',
                    $spec->service->id,
                    $name,
                ));
            }
        }
    }
}
