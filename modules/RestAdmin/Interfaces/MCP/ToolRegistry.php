<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP;

use Modules\RestAdmin\Application\Mapping\ApiException;
use Modules\RestAdmin\Interfaces\MCP\Tools\AddFieldTool;
use Modules\RestAdmin\Interfaces\MCP\Tools\CreateItemTool;
use Modules\RestAdmin\Interfaces\MCP\Tools\CreateResourceTool;
use Modules\RestAdmin\Interfaces\MCP\Tools\DeleteItemTool;
use Modules\RestAdmin\Interfaces\MCP\Tools\FetchItemTool;
use Modules\RestAdmin\Interfaces\MCP\Tools\FetchItemsTool;
use Modules\RestAdmin\Interfaces\MCP\Tools\HealthTool;
use Modules\RestAdmin\Interfaces\MCP\Tools\ListResourcesTool;
use Modules\RestAdmin\Interfaces\MCP\Tools\ListServicesTool;
use Modules\RestAdmin\Interfaces\MCP\Tools\ProbeEndpointTool;
use Modules\RestAdmin\Interfaces\MCP\Tools\RegisterServiceTool;
use Modules\RestAdmin\Interfaces\MCP\Tools\RemoveFieldTool;
use Modules\RestAdmin\Interfaces\MCP\Tools\RenameFieldTool;
use Modules\RestAdmin\Interfaces\MCP\Tools\UpdateItemTool;
use Modules\RestAdmin\Interfaces\MCP\Tools\UpdateResourceTool;

/**
 * ToolRegistry — the closed list of tools this server exposes.
 *
 * A tool that is not in the list cannot be called, which is the same allowlist
 * property `ActionSet` gives resources: adding a capability is a code change,
 * not a request field. `call()` turns the module's typed failures into
 * `isError: true` answers, and an unknown name into `UnknownToolException` —
 * the server maps that to JSON-RPC `-32602`, because the *request* was wrong,
 * not the operation.
 *
 * The registry is constructed with tools rather than looked up by convention:
 * a test can call one tool with no server, and the binary and the test run the
 * same list.
 */
final readonly class ToolRegistry
{
    /**
     * @param list<Tool> $tools
     */
    public function __construct(private array $tools)
    {
    }

    public static function default(): self
    {
        return new self([
            new ListServicesTool(),
            new RegisterServiceTool(),
            new ProbeEndpointTool(),
            new CreateResourceTool(),
            new UpdateResourceTool(),
            new AddFieldTool(),
            new RemoveFieldTool(),
            new RenameFieldTool(),
            new ListResourcesTool(),
            new FetchItemsTool(),
            new FetchItemTool(),
            new CreateItemTool(),
            new UpdateItemTool(),
            new DeleteItemTool(),
            new HealthTool(),
        ]);
    }

    /**
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function list(): array
    {
        $tools = [];

        foreach ($this->tools as $tool) {
            $tools[] = [
                'name'        => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->schema(),
            ];
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws UnknownToolException when no tool has that name
     */
    public function call(string $name, array $arguments): ToolResult
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() !== $name) {
                continue;
            }

            try {
                return $tool->call($arguments);
            } catch (ApiException $exception) {
                return ToolResult::error($exception->error->message, [
                    'kind'         => $exception->error->kind,
                    'status'       => $exception->error->status,
                    'field_errors' => $exception->error->fieldErrors,
                ]);
            } catch (\RuntimeException|\InvalidArgumentException $exception) {
                return ToolResult::error($exception->getMessage());
            }
        }

        throw new UnknownToolException($name);
    }
}
