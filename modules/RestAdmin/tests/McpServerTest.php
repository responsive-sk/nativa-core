<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Tests;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\RestAdmin\Infrastructure\Http\FakeTransport;
use Modules\RestAdmin\Infrastructure\Http\HttpResponse;
use Modules\RestAdmin\Infrastructure\Persistence\Migrations\RestAdminDatabaseMigrator;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;
use Modules\RestAdmin\Interfaces\MCP\Server;
use Modules\RestAdmin\Interfaces\MCP\ToolRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * McpServerTest — the protocol and the tools, without a process.
 *
 * `Server::handle()` is the whole protocol seam, so these tests speak the same
 * JSON a client would: `initialize`, `tools/list`, `tools/call`, a notification
 * and a batch. The acceptance scenario from the plan (register a service,
 * create a resource, read items) runs through `tools/call` against the blog
 * fixture, so the binary's behaviour is covered without stdin/stdout.
 *
 * Refusals are tested as carefully as happy paths: a delete without `confirm`
 * must not reach the transport, and a `data` key the spec does not declare must
 * be refused rather than forwarded to the remote API.
 */
final class McpServerTest extends TestCase
{
    private const string MODULE = 'RestAdmin';

    private string $tmpDir = '';

    private int $nextId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-rest-mcp-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_RESTADMIN=' . $this->tmpDir . '/restadmin.db');
        putenv('RESTADMIN_SPEC_DIR=' . $this->tmpDir . '/specs');
        DatabaseConnectionManager::resetInstance();
        RestAdminServices::reset();

        (new RestAdminDatabaseMigrator(new \PDO('sqlite:' . $this->tmpDir . '/restadmin.db')))
            ->run(McpTestDoubles::output());
    }

    protected function tearDown(): void
    {
        if (is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            putenv('DB_RESTADMIN');
            putenv('RESTADMIN_SPEC_DIR');
            putenv('RESTADMIN_BLOG_TOKEN');
            DatabaseConnectionManager::resetInstance();
            RestAdminServices::reset();

            foreach (glob($this->tmpDir . '/specs/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir . '/specs');

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function initializeAnswersAProtocolVersionAndToolCapability(): void
    {
        $response = $this->callJson('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');

        self::assertSame('2.0', $response['jsonrpc']);
        self::assertSame('2024-11-05', $response['result']['protocolVersion']);
        self::assertArrayHasKey('tools', $response['result']['capabilities']);
        self::assertSame('nativa-rest-admin', $response['result']['serverInfo']['name']);
    }

    #[Test]
    public function toolsListNamesEveryToolWithAnObjectSchema(): void
    {
        $response = $this->callJson('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
        $tools = $response['result']['tools'];

        self::assertCount(15, $tools);

        $names = array_column($tools, 'name');

        foreach (['list_services', 'register_service', 'probe_endpoint', 'create_resource', 'update_resource', 'add_field', 'remove_field', 'rename_field', 'list_resources', 'fetch_items', 'fetch_item', 'create_item', 'update_item', 'delete_item', 'health'] as $expected) {
            self::assertContains($expected, $names);
        }

        foreach ($tools as $tool) {
            self::assertSame('object', $tool['inputSchema']['type']);
        }
    }

    #[Test]
    public function aMalformedLineIsAParseError(): void
    {
        $responses = $this->server()->handle('{not json');

        self::assertNotNull($responses);
        self::assertSame(-32_700, $responses[0]['error']['code']);
    }

    #[Test]
    public function anUnknownMethodIsMethodNotFound(): void
    {
        $response = $this->callJson('{"jsonrpc":"2.0","id":1,"method":"resources/list"}');

        self::assertSame(-32_601, $response['error']['code']);
    }

    #[Test]
    public function aNotificationGetsNoResponse(): void
    {
        self::assertNull($this->server()->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}'));
    }

    #[Test]
    public function aBatchIsOneArrayOfResponses(): void
    {
        $responses = $this->server()->handle(
            '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"tools/list"}]',
        );

        self::assertNotNull($responses);
        self::assertCount(2, $responses);
        self::assertSame(1, $responses[0]['id']);
        self::assertSame(2, $responses[1]['id']);
    }

    #[Test]
    public function anUnknownToolIsInvalidParams(): void
    {
        $response = $this->toolCall('not_a_tool', []);

        self::assertSame(-32_602, $response['error']['code']);
    }

    #[Test]
    public function theAcceptanceScenarioRunsThroughTheTools(): void
    {
        putenv('RESTADMIN_BLOG_TOKEN=secret-value');

        $registered = $this->tool('register_service', [
            'id'        => 'blog',
            'name'      => 'Blog',
            'base_url'  => 'https://api.example.test',
            'token_env' => 'RESTADMIN_BLOG_TOKEN',
        ]);

        self::assertFalse($registered['isError']);
        self::assertStringNotContainsString('secret-value', $this->text($registered));

        $resource = $this->tool('create_resource', [
            'service_id' => 'blog',
            'name'       => 'posts',
            'path'       => '/posts',
            'actions'    => ['list', 'read', 'create', 'update', 'delete'],
            'fields'     => [
                ['name' => 'id', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'title', 'label' => 'Title', 'type' => 'text'],
            ],
        ]);

        self::assertFalse($resource['isError']);

        $listed = $this->tool('list_resources', ['service_id' => 'blog']);
        self::assertStringContainsString('posts', $this->text($listed));

        self::assertFalse($this->tool('add_field', [
            'service_id' => 'blog',
            'resource'   => 'posts',
            'field'      => ['name' => 'published_at', 'label' => 'Published', 'type' => 'date'],
        ])['isError']);

        $renamed = $this->tool('rename_field', [
            'service_id' => 'blog',
            'resource'   => 'posts',
            'field'      => 'title',
            'new_name'   => 'headline',
        ]);
        self::assertStringContainsString('headline', $this->text($renamed));

        self::assertFalse($this->tool('update_resource', [
            'service_id' => 'blog',
            'name'       => 'posts',
            'path'       => '/articles',
            'item_path'  => '/articles/{id}',
        ])['isError']);

        $transport = new FakeTransport(
            new HttpResponse(200, $this->fixture('blog-page-1.json')),
            new HttpResponse(200, $this->fixture('blog-page-1.json')),
            new HttpResponse(200, $this->fixture('blog-item.json')),
            new HttpResponse(201, '{"data":{"id":9,"headline":"New"}}'),
            new HttpResponse(200, '{"data":{"id":9,"headline":"Edited"}}'),
            new HttpResponse(204),
        );
        RestAdminServices::useTransport($transport);

        $probe = $this->tool('probe_endpoint', ['service_id' => 'blog', 'resource' => 'posts']);
        self::assertFalse($probe['isError']);
        self::assertStringContainsString('"rows": 2', $this->text($probe));

        $items = $this->tool('fetch_items', ['service_id' => 'blog', 'resource' => 'posts', 'per_page' => 10]);
        self::assertStringContainsString('Hello, Nativa', $this->text($items));
        self::assertStringContainsString('/articles?page=1&per_page=10', $transport->lastRequest()->url);

        self::assertFalse($this->tool('fetch_item', ['service_id' => 'blog', 'resource' => 'posts', 'id' => '1'])['isError']);

        self::assertFalse($this->tool('create_item', [
            'service_id' => 'blog',
            'resource'   => 'posts',
            'data'       => ['headline' => 'New'],
        ])['isError']);
        self::assertSame('POST', $transport->lastRequest()->method);

        self::assertFalse($this->tool('update_item', [
            'service_id' => 'blog',
            'resource'   => 'posts',
            'id'         => '9',
            'data'       => ['headline' => 'Edited'],
        ])['isError']);
        self::assertSame('PATCH', $transport->lastRequest()->method);

        $deleted = $this->tool('delete_item', [
            'service_id' => 'blog',
            'resource'   => 'posts',
            'id'         => '9',
            'confirm'    => true,
        ]);
        self::assertFalse($deleted['isError']);
        self::assertSame('DELETE', $transport->lastRequest()->method);

        self::assertFalse($this->tool('remove_field', [
            'service_id' => 'blog',
            'resource'   => 'posts',
            'field'      => 'published_at',
        ])['isError']);

        $health = $this->tool('health', []);
        self::assertStringContainsString('"services": 1', $this->text($health));

        $services = $this->tool('list_services', []);
        self::assertStringNotContainsString('secret-value', $this->text($services));
    }

    #[Test]
    public function aDeleteWithoutConfirmNeverReachesTheTransport(): void
    {
        $this->seedDeletableService();
        $transport = new FakeTransport();
        RestAdminServices::useTransport($transport);

        $result = $this->tool('delete_item', [
            'service_id' => 'blog',
            'resource'   => 'posts',
            'id'         => '1',
        ]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('confirm=true', $this->text($result));
        self::assertSame([], $transport->requests());
    }

    #[Test]
    public function aDataKeyTheSpecDoesNotDeclareIsRefused(): void
    {
        $this->seedDeletableService();
        $transport = new FakeTransport();
        RestAdminServices::useTransport($transport);

        $result = $this->tool('create_item', [
            'service_id' => 'blog',
            'resource'   => 'posts',
            'data'       => ['headline' => 'ok', 'not_declared' => 'no'],
        ]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('not_declared', $this->text($result));
        self::assertSame([], $transport->requests());
    }

    /**
     * The vocabulary refusals the panel shows beside a field are tool errors
     * here: the domain still throws InvalidArgumentException, and the registry
     * turns it into `isError: true` rather than letting it escape the stdio
     * loop. The repository must be unchanged after a refused call.
     */
    #[Test]
    public function anInvalidResourceIsAToolErrorAndIsNotSaved(): void
    {
        $this->tool('register_service', [
            'id'       => 'blog',
            'name'     => 'Blog',
            'base_url' => 'https://api.example.test',
        ]);

        $pagination = $this->tool('create_resource', [
            'service_id' => 'blog',
            'name'       => 'posts',
            'path'       => '/posts',
            'pagination' => 'offset',
        ]);

        self::assertTrue($pagination['isError']);
        self::assertStringContainsString('pagination', $this->text($pagination));

        $actions = $this->tool('create_resource', [
            'service_id' => 'blog',
            'name'       => 'posts',
            'path'       => '/posts',
            'actions'    => ['list', 'list'],
        ]);

        self::assertTrue($actions['isError']);
        self::assertStringContainsString('listed twice', $this->text($actions));

        $missing = $this->tool('create_resource', [
            'service_id' => 'blog',
            'path'       => '/posts',
        ]);

        self::assertTrue($missing['isError']);
        self::assertStringContainsString('name', $this->text($missing));

        $spec = RestAdminServices::repository()->find('blog');
        self::assertNotNull($spec);
        self::assertSame([], $spec->resources);
    }

    private function seedDeletableService(): void
    {
        $this->tool('register_service', [
            'id'       => 'blog',
            'name'     => 'Blog',
            'base_url' => 'https://api.example.test',
        ]);

        $this->tool('create_resource', [
            'service_id' => 'blog',
            'name'       => 'posts',
            'path'       => '/posts',
            'actions'    => ['list', 'read', 'create', 'delete'],
            'fields'     => [['name' => 'headline', 'label' => 'Headline', 'type' => 'text']],
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        $response = $this->toolCall($name, $arguments);

        self::assertArrayHasKey('result', $response, 'Tool call failed: ' . json_encode($response));

        return $response['result'];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function toolCall(string $name, array $arguments): array
    {
        $id = $this->nextId++;
        $json = json_encode([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'method'  => 'tools/call',
            'params'  => ['name' => $name, 'arguments' => (object) $arguments],
        ], JSON_THROW_ON_ERROR);

        return $this->callJson($json);
    }

    /**
     * @return array<string, mixed>
     */
    private function callJson(string $json): array
    {
        $responses = $this->server()->handle($json);
        self::assertNotNull($responses);
        self::assertCount(1, $responses);

        return $responses[0];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function text(array $result): string
    {
        self::assertArrayHasKey('content', $result);

        return $result['content'][0]['text'];
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/fixtures/' . $name);
        self::assertIsString($contents);

        return $contents;
    }

    private function server(): Server
    {
        return new Server(ToolRegistry::default());
    }

}

/**
 * The migrator's quiet output lives outside the TestCase for the same reason
 * `PanelTestDoubles` does: a TestCase method returning an anonymous class
 * segfaulted PHP 8.5.12 while loading the file (see PanelTest's note).
 */
final class McpTestDoubles
{
    public static function output(): \Modules\Core\Interfaces\CLI\CliOutput
    {
        return new class implements \Modules\Core\Interfaces\CLI\CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
