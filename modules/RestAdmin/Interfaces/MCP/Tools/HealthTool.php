<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP\Tools;

use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Interfaces\MCP\AbstractTool;
use Modules\RestAdmin\Interfaces\MCP\Schemas;
use Modules\RestAdmin\Interfaces\MCP\ToolResult;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * HealthTool — is the specification readable, and what is in it?
 *
 * A tool that only said "ok" would be indistinguishable from a hardcoded true.
 * This one reads `restadmin.db` (so a missing migration is a real failure) and
 * reports counts, which is the first thing to check when a panel page is empty.
 * It does not call a remote service: health of the panel is not health of the
 * APIs it points at, and a health check that could hang on a third party is a
 * health check nobody runs.
 */
final class HealthTool extends AbstractTool
{
    public function name(): string
    {
        return 'health';
    }

    public function description(): string
    {
        return 'Report whether the REST admin specification database is readable, and how many services and resources it holds.';
    }

    public function schema(): array
    {
        return Schemas::object([]);
    }

    public function call(array $arguments): ToolResult
    {
        try {
            $specs = RestAdminServices::repository()->all();
        } catch (\PDOException $exception) {
            return ToolResult::error(
                'restadmin.db is not readable. Run `php bin/cms migrate` to create its tables.',
                ['reason' => $exception->getMessage()],
            );
        }

        $resources = 0;

        foreach ($specs as $spec) {
            $resources += count($spec->resources);
        }

        return ToolResult::ok([
            'ok'        => true,
            'database'  => 'restadmin',
            'services'  => count($specs),
            'resources' => $resources,
            'services_detail' => array_map(
                static fn ($spec): array => [
                    'id'        => $spec->service->id,
                    'resources' => array_map(static fn (ResourceSpec $resource): string => $resource->name, $spec->resources),
                ],
                $specs,
            ),
        ]);
    }
}
