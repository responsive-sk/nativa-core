<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\RestAdmin\Infrastructure\Http\FakeTransport;
use Modules\RestAdmin\Infrastructure\Http\HttpResponse;
use Modules\RestAdmin\Infrastructure\Persistence\Migrations\RestAdminDatabaseMigrator;
use Modules\RestAdmin\Interfaces\HTTP\Actions\ItemDeleteAction;
use Modules\RestAdmin\Interfaces\HTTP\Actions\ItemDetailAction;
use Modules\RestAdmin\Interfaces\HTTP\Actions\ItemFormAction;
use Modules\RestAdmin\Interfaces\HTTP\Actions\ItemsIndexAction;
use Modules\RestAdmin\Interfaces\HTTP\Actions\ResourceFormAction;
use Modules\RestAdmin\Interfaces\HTTP\Actions\ServiceFormAction;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ScenarioTest — the F6 acceptance path through the HTTP layer, in one test.
 *
 * PanelTest covers each behaviour where it lives; this file walks the path an
 * operator actually walks, because the failures that matter are between the
 * steps: a resource saved with fields the item form cannot render, a list that
 * paginates but a detail that 404s, a create that succeeds and an update that
 * sends the wrong method. The fake transport is the wire, so the last request's
 * method, URL and body are asserted, not inferred from the response.
 *
 * XSS is measured at both surfaces that echo API data (list and detail), and a
 * 401 is measured as a panel error that names the token, not a stack trace.
 */
final class ScenarioTest extends TestCase
{
    private const string MODULE = 'RestAdmin';

    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-rest-scenario-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_RESTADMIN=' . $this->tmpDir . '/restadmin.db');
        putenv('RESTADMIN_SPEC_DIR=' . $this->tmpDir . '/specs');
        putenv('RESTADMIN_BLOG_TOKEN=blog-secret');
        // The scenario walks every audited write. Point Audit at the temp
        // directory too: storage/data/audit.db is append-only by trigger, so a
        // test row written there cannot be taken back out.
        putenv('DB_AUDIT=' . $this->tmpDir . '/audit.db');
        // Audit resolves the actor through AuthService over users.db. No
        // session exists here, so nothing is queried, but the connection must
        // still not be the developer's file.
        putenv('DB_USERS=' . $this->tmpDir . '/users.db');
        DatabaseConnectionManager::resetInstance();
        RestAdminServices::reset();
        $this->resetAudit();

        (new RestAdminDatabaseMigrator(new \PDO('sqlite:' . $this->tmpDir . '/restadmin.db')))
            ->run(ScenarioDoubles::output());

        if (class_exists('Modules\\Audit\\Infrastructure\\Persistence\\Migrations\\AuditDatabaseMigrator')) {
            (new \Modules\Audit\Infrastructure\Persistence\Migrations\AuditDatabaseMigrator(
                new \PDO('sqlite:' . $this->tmpDir . '/audit.db'),
            ))->run(ScenarioDoubles::output());
        }
    }

    protected function tearDown(): void
    {
        if (is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            putenv('DB_RESTADMIN');
            putenv('RESTADMIN_SPEC_DIR');
            putenv('RESTADMIN_BLOG_TOKEN');
            putenv('DB_AUDIT');
            putenv('DB_USERS');
            $this->resetAudit();
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
    public function serviceResourceFieldAndItemTravelTheWholePath(): void
    {
        $this->createService();
        $this->createResource();

        // The field step: the same resource with one more declared key.
        $this->addPublishedField();

        $spec = RestAdminServices::repository()->find('blog');
        self::assertNotNull($spec);
        self::assertCount(1, $spec->resources);
        self::assertCount(3, $spec->resources[0]->fields);

        $transport = new FakeTransport(
            new HttpResponse(200, json_encode([
                'data' => [['id' => 1, 'title' => '<script>alert(1)</script>', 'published_at' => '2026-11-13']],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 1],
            ], JSON_THROW_ON_ERROR)),
            new HttpResponse(200, json_encode([
                'data' => ['id' => 1, 'title' => '<img src=x onerror=alert(2)>'],
            ], JSON_THROW_ON_ERROR)),
            new HttpResponse(201, '{"data":{"id":7}}'),
            new HttpResponse(200, '{"data":{"id":7}}'),
            new HttpResponse(204),
        );
        RestAdminServices::useTransport($transport);

        $list = (new ItemsIndexAction(ScenarioDoubles::router()))->handle(
            $this->request('GET', '/mark/rest/services/blog/resources/posts/items', [], ['id' => 'blog', 'resource' => 'posts']),
        );

        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $list->getContent());
        self::assertStringNotContainsString('<script>alert(1)</script>', $list->getContent());
        self::assertStringContainsString('/posts?page=1&per_page=25', $transport->lastRequest()->url);

        $detail = (new ItemDetailAction(ScenarioDoubles::router()))->handle(
            $this->request('GET', '/mark/rest/services/blog/resources/posts/items/1', [], ['id' => 'blog', 'resource' => 'posts', 'item' => '1']),
        );

        self::assertSame(200, $detail->getStatusCode());
        self::assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $detail->getContent());
        self::assertStringNotContainsString('<img src=x onerror=alert(2)>', $detail->getContent());

        $created = (new ItemFormAction(ScenarioDoubles::router()))->createSubmit(
            $this->request('POST', '/mark/rest/services/blog/resources/posts/items/create', [
                'title'        => 'New post',
                'published_at' => '2026-11-13',
            ], ['id' => 'blog', 'resource' => 'posts']),
        );

        self::assertSame(302, $created->getStatusCode());
        self::assertSame('POST', $transport->lastRequest()->method);
        $body = json_decode((string) $transport->lastRequest()->body, true);
        self::assertIsArray($body);
        self::assertSame('New post', $body['title']);

        $updated = (new ItemFormAction(ScenarioDoubles::router()))->editSubmit(
            $this->request('POST', '/mark/rest/services/blog/resources/posts/items/7/edit', [
                'title' => 'Edited post',
            ], ['id' => 'blog', 'resource' => 'posts', 'item' => '7']),
        );

        self::assertSame(302, $updated->getStatusCode());
        self::assertSame('PATCH', $transport->lastRequest()->method);
        self::assertStringEndsWith('/posts/7', $transport->lastRequest()->url);

        $deleted = (new ItemDeleteAction(ScenarioDoubles::router()))->handle(
            $this->request('POST', '/mark/rest/services/blog/resources/posts/items/7/delete', [], ['id' => 'blog', 'resource' => 'posts', 'item' => '7']),
        );

        self::assertSame(302, $deleted->getStatusCode());
        self::assertSame('DELETE', $transport->lastRequest()->method);
        self::assertSame([], $transport->remaining());

        $unauthorized = new FakeTransport(new HttpResponse(401, $this->fixture('error-401.json')));
        RestAdminServices::useTransport($unauthorized);

        $refused = (new ItemsIndexAction(ScenarioDoubles::router()))->handle(
            $this->request('GET', '/mark/rest/services/blog/resources/posts/items', [], ['id' => 'blog', 'resource' => 'posts']),
        );

        self::assertSame(502, $refused->getStatusCode());
        self::assertNotFalse(stripos($refused->getContent(), 'token'));
    }

    /**
     * The audit trail is a second witness: the panel commits a specification
     * without an HTTP trip, so `Audit` is the only record that a service or a
     * resource was registered. A refused submission must leave no row, because
     * an audit line that claims a write that did not happen is worse than none.
     */
    #[Test]
    public function aSuccessfulWriteIsAuditedAndARefusedOneIsNot(): void
    {
        if (!class_exists('Modules\\Audit\\Interfaces\\HTTP\\AuditServices')) {
            $this->markTestSkipped('Audit is not installed.');
        }

        $this->createService();
        $this->createResource();

        self::assertSame([
            ['action' => 'restadmin.service.create', 'target' => 'blog', 'actor_name' => 'system'],
            ['action' => 'restadmin.resource.create', 'target' => 'blog/posts', 'actor_name' => 'system'],
        ], $this->auditRows());

        $refused = (new ServiceFormAction(ScenarioDoubles::router()))->createSubmit(
            Request::create('POST', '/mark/rest/services', body: ['name' => '']),
        );

        self::assertNotSame(302, $refused->getStatusCode());
        self::assertCount(2, $this->auditRows());
    }

    private function createService(): void
    {
        $response = (new ServiceFormAction(ScenarioDoubles::router()))->createSubmit(
            Request::create('POST', '/mark/rest/services', body: [
                'name'            => 'Blog',
                'base_url'        => 'https://api.example.test',
                'token_env'       => 'RESTADMIN_BLOG_TOKEN',
                'auth_header'     => 'Authorization',
                'timeout_seconds' => '10',
                'headers'         => '{}',
            ]),
        );

        self::assertSame(302, $response->getStatusCode());
    }

    private function createResource(): void
    {
        $response = (new ResourceFormAction(ScenarioDoubles::router()))->createSubmit(
            $this->request('POST', '/mark/rest/services/blog/resources', [
                'name'       => 'posts',
                'path'       => '/posts',
                'item_path'  => '/posts/{id}',
                'id_field'   => 'id',
                'pagination' => 'page',
                'actions'    => ['list', 'read', 'create', 'update', 'delete'],
                'fields'     => '[{"name":"id","label":"ID","type":"text"},{"name":"title","label":"Title","type":"text"}]',
            ], ['id' => 'blog']),
        );

        self::assertSame(302, $response->getStatusCode());
    }

    private function addPublishedField(): void
    {
        $response = (new ResourceFormAction(ScenarioDoubles::router()))->editSubmit(
            $this->request('POST', '/mark/rest/services/blog/resources/posts', [
                'name'       => 'posts',
                'path'       => '/posts',
                'item_path'  => '/posts/{id}',
                'id_field'   => 'id',
                'pagination' => 'page',
                'actions'    => ['list', 'read', 'create', 'update', 'delete'],
                'fields'     => '[{"name":"id","label":"ID","type":"text"},{"name":"title","label":"Title","type":"text"},{"name":"published_at","label":"Published","type":"date"}]',
            ], ['id' => 'blog', 'resource' => 'posts']),
        );

        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditRows(): array
    {
        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/audit.db');
        $statement = $pdo->query('SELECT action, target, actor_name FROM audit_log ORDER BY rowid');
        self::assertNotFalse($statement);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    private function resetAudit(): void
    {
        if (class_exists('Modules\\Audit\\Interfaces\\HTTP\\AuditServices')) {
            \Modules\Audit\Interfaces\HTTP\AuditServices::reset();
        }
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $params
     */
    private function request(string $method, string $path, array $body = [], array $params = []): Request
    {
        $request = Request::create($method, $path, body: $body);
        $request->setRouteParams($params);

        return $request;
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/fixtures/' . $name);
        self::assertIsString($contents);

        return $contents;
    }
}

/**
 * The router and migrator doubles, outside the TestCase (PHP 8.5.12 segfaults
 * on a TestCase method returning an anonymous class; see PanelTest's note).
 */
final class ScenarioDoubles
{
    public static function router(): RouterInterface
    {
        return new class implements RouterInterface {
            public function match(Request $request): ?array
            {
                return null;
            }

            public function url(string $name, array $params = []): string
            {
                return '/mark/rest/' . $name . ([] === $params ? '' : '?' . http_build_query($params));
            }
        };
    }

    public static function output(): \Modules\Core\Interfaces\CLI\CliOutput
    {
        return new class implements \Modules\Core\Interfaces\CLI\CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
