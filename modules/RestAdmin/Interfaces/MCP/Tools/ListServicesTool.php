<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP\Tools;

use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Interfaces\MCP\AbstractTool;
use Modules\RestAdmin\Interfaces\MCP\Schemas;
use Modules\RestAdmin\Interfaces\MCP\ToolResult;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * ListServicesTool — the registered services and what each one exposes.
 *
 * The answer carries `token_env` (the variable NAME) and never its value; the
 * env var is read by the transport at request time, so a listing that reprinted
 * it would be the one place a secret could leak into a model's context.
 */
final class ListServicesTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_services';
    }

    public function description(): string
    {
        return 'List registered REST services, their base URLs, token environment variable names, and the resources each exposes.';
    }

    public function schema(): array
    {
        return Schemas::object([]);
    }

    public function call(array $arguments): ToolResult
    {
        $services = [];

        foreach (RestAdminServices::repository()->all() as $spec) {
            $services[] = [
                'id'        => $spec->service->id,
                'name'      => $spec->service->name,
                'base_url'  => $spec->service->baseUrl,
                'token_env' => $spec->service->tokenEnv(),
                'resources' => array_map(
                    static fn (ResourceSpec $resource): array => [
                        'name'       => $resource->name,
                        'path'       => $resource->path,
                        'item_path'  => $resource->itemPath,
                        'actions'    => $resource->actions->toArray(),
                        'pagination' => $resource->pagination->strategy,
                        'fields'     => array_map(
                            static fn ($field): string => $field->name,
                            $resource->fields,
                        ),
                    ],
                    $spec->resources,
                ),
            ];
        }

        return ToolResult::ok(['services' => $services]);
    }
}
