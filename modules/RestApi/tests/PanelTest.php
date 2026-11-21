<?php

declare(strict_types = 1);

namespace Modules\RestApi\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\RestApi\Domain\Model\ServiceSpec;
use Modules\RestApi\Infrastructure\Http\FakeTransport;
use Modules\RestApi\Infrastructure\Http\HttpResponse;
use Modules\RestApi\Infrastructure\Persistence\Migrations\RestApiDatabaseMigrator;
use Modules\RestApi\Interfaces\HTTP\Actions\ItemDeleteAction;
use Modules\RestApi\Interfaces\HTTP\Actions\ItemFormAction;
use Modules\RestApi\Interfaces\HTTP\Actions\ItemsIndexAction;
use Modules\RestApi\Interfaces\HTTP\Actions\ResourceFormAction;
use Modules\RestApi\Interfaces\HTTP\Actions\RestApiIndexAction;
use Modules\RestApi\Interfaces\HTTP\Actions\ServiceFormAction;
use Modules\RestApi\Interfaces\HTTP\RestApiServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

/**
 * PanelTest — the F4 panel against fixtures, not the internet.
 *
 * Four properties are worth a test each because each looks like working
 * software from the outside:
 *
 * 1. **An operator's text becomes markup.** The service list is the first page
 *    that echoes a stored name; a `<script>` in a service name must arrive as
 *    text.
 * 2. **A write happens for a read-only resource.** The button is hidden, but
 *    the route must refuse the POST too — the transport is the witness.
 * 3. **A 422 becomes a page error instead of a field error.** The form must
 *    re-render at 422 with the service's message beside the field, because a
 *    generic error page makes the operator retype everything.
 * 4. **A delete is a GET.** The request method on the wire is asserted, not
 *    the button's label.
 *
 * `RestApiServices::useTransport()` is what keeps the suite offline: every
 * action reads the fake, and `remaining()`/`requests()` say exactly what the
 * panel sent.
 */
final class PanelTest extends TestCase
{
    use SqliteDatabase;

    private const string MODULE = 'RestApi';

    private string $tmpDir = '';

    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed.');
        }

        $this->session = $_SESSION ?? [];
        $this->tmpDir = sys_get_temp_dir() . '/nativa-rest-api-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_RESTAPI=' . $this->tmpDir . '/restapi.db');
        putenv('RESTAPI_SPEC_DIR=' . $this->tmpDir . '/specs');
        // Panel writes are audited when Audit is installed. Without this the
        // suite appends test rows to the developer's storage/data/audit.db,
        // which is append-only by trigger and therefore cannot be cleaned up.
        putenv('DB_AUDIT=' . $this->tmpDir . '/audit.db');
        DatabaseConnectionManager::resetInstance();
        RestApiServices::reset();
        $this->resetAudit();

        (new RestApiDatabaseMigrator(new \PDO('sqlite:' . $this->tmpDir . '/restapi.db')))
            ->run(PanelTestDoubles::output());

        if (class_exists('Modules\\Audit\\Infrastructure\\Persistence\\Migrations\\AuditDatabaseMigrator')) {
            (new \Modules\Audit\Infrastructure\Persistence\Migrations\AuditDatabaseMigrator(
                new \PDO('sqlite:' . $this->tmpDir . '/audit.db'),
            ))->run(PanelTestDoubles::output());
        }
    }

    protected function tearDown(): void
    {
        $this->restoreDatabaseEnvironment();

        if (is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            putenv('DB_RESTAPI');
            putenv('RESTAPI_SPEC_DIR');
            putenv('DB_AUDIT');
            $this->resetAudit();
            DatabaseConnectionManager::resetInstance();
            RestApiServices::reset();
            $_SESSION = $this->session;

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

        $this->restoreDatabaseEnvironment();
    }

    /**
     * Audit is optional: the module may not be installed, and its services
     * cache the connection it was built over.
     */
    private function resetAudit(): void
    {
        if (class_exists('Modules\\Audit\\Interfaces\\HTTP\\AuditServices')) {
            \Modules\Audit\Interfaces\HTTP\AuditServices::reset();
        }
    }

    #[Test]
    public function theServiceListEscapesAnOperatorSuppliedName(): void
    {
        $this->seed('<script>alert(1)</script>', []);

        $response = (new RestApiIndexAction(PanelTestDoubles::router()))->handle(Request::create('GET', '/mark/rest'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $response->getContent());
        self::assertStringNotContainsString('<script>alert(1)</script>', $response->getContent());
    }

    #[Test]
    public function creatingAServiceStoresItAndRedirects(): void
    {
        $response = (new ServiceFormAction(PanelTestDoubles::router()))->createSubmit(Request::create('POST', '/mark/rest/services', body: [
            'name'            => 'Blog',
            'base_url'        => 'https://api.example.test',
            'token_env'       => 'RESTAPI_BLOG_TOKEN',
            'auth_header'     => 'Authorization',
            'timeout_seconds' => '10',
            'headers'         => '{}',
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertNotNull($response->getHeader('Location'));

        $spec = RestApiServices::repository()->find('blog');
        self::assertInstanceOf(ServiceSpec::class, $spec);
        self::assertSame('https://api.example.test', $spec->service->baseUrl);
        self::assertSame('RESTAPI_BLOG_TOKEN', $spec->service->tokenEnv());
    }

    #[Test]
    public function aNewResourceDefaultsToReadOnly(): void
    {
        $this->seed('Blog', []);

        $request = Request::create('POST', '/mark/rest/services/blog/resources', body: [
                'name'       => 'posts',
                'path'       => '/posts',
                'item_path'  => '/posts/{id}',
                'id_field'   => 'id',
                'pagination' => 'page',
                'actions'    => ['list', 'read'],
                'fields'     => '[{"name":"id","label":"ID","type":"text"},{"name":"title","label":"Title","type":"text"}]',
        ]);
        $request->setRouteParams(['id' => 'blog']);

        $response = (new \Modules\RestApi\Interfaces\HTTP\Actions\ResourceFormAction(PanelTestDoubles::router()))->createSubmit($request);

        self::assertSame(302, $response->getStatusCode());

        $spec = RestApiServices::repository()->find('blog');
        self::assertNotNull($spec);
        self::assertCount(1, $spec->resources);
        self::assertSame(['list', 'read'], $spec->resources[0]->actions->toArray());
        self::assertFalse($spec->resources[0]->actions->writes());
    }

    #[Test]
    public function theCollectionPageRendersTheBlogFixture(): void
    {
        $this->seed('Blog', [[
            'name'       => 'posts',
            'path'       => '/posts',
            'item_path'  => '/posts/{id}',
            'actions'    => ['list', 'read'],
            'pagination' => 'page',
            'fields'     => [
                ['name' => 'id', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'title', 'label' => 'Title', 'type' => 'text'],
            ],
        ]]);

        $transport = new FakeTransport(new HttpResponse(200, $this->fixture('blog-page-1.json')));
        RestApiServices::useTransport($transport);

        $request = Request::create('GET', '/mark/rest/services/blog/resources/posts/items');
        $request->setRouteParams(['id' => 'blog', 'resource' => 'posts']);

        $response = (new ItemsIndexAction(PanelTestDoubles::router()))->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Hello, Nativa', $response->getContent());
        self::assertStringContainsString('/posts?page=1&per_page=25', $transport->lastRequest()->url);
    }

    #[Test]
    public function aValidationFailureIsRenderedBesideTheField(): void
    {
        $this->seed('Blog', [[
            'name'       => 'posts',
            'path'       => '/posts',
            'item_path'  => '/posts/{id}',
            'actions'    => ['list', 'read', 'create'],
            'pagination' => 'page',
            'fields'     => [['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true]],
        ]]);

        $transport = new FakeTransport(new HttpResponse(422, $this->fixture('error-422-laravel.json')));
        RestApiServices::useTransport($transport);

        $request = Request::create('POST', '/mark/rest/services/blog/resources/posts/items/create', body: ['title' => 'Too short']);
        $request->setRouteParams(['id' => 'blog', 'resource' => 'posts']);

        $response = (new ItemFormAction(PanelTestDoubles::router()))->createSubmit($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('m-field__error', $response->getContent());
        self::assertCount(1, $transport->requests());
    }

    #[Test]
    public function aReadOnlyResourceRefusesTheWriteBeforeTheTransport(): void
    {
        $this->seed('Blog', [[
            'name'       => 'posts',
            'path'       => '/posts',
            'item_path'  => '/posts/{id}',
            'actions'    => ['list', 'read'],
            'pagination' => 'page',
            'fields'     => [['name' => 'title', 'label' => 'Title', 'type' => 'text']],
        ]]);

        $transport = new FakeTransport();
        RestApiServices::useTransport($transport);

        $request = Request::create('POST', '/mark/rest/services/blog/resources/posts/items/create', body: ['title' => 'No']);
        $request->setRouteParams(['id' => 'blog', 'resource' => 'posts']);

        $response = (new ItemFormAction(PanelTestDoubles::router()))->createSubmit($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $transport->requests());
    }

    #[Test]
    public function deletingAnItemSendsDeleteToTheMemberPath(): void
    {
        $this->seed('Blog', [[
            'name'       => 'posts',
            'path'       => '/posts',
            'item_path'  => '/posts/{id}',
            'actions'    => ['list', 'read', 'delete'],
            'pagination' => 'page',
            'fields'     => [['name' => 'title', 'label' => 'Title', 'type' => 'text']],
        ]]);

        $transport = new FakeTransport(new HttpResponse(204));
        RestApiServices::useTransport($transport);

        $request = Request::create('POST', '/mark/rest/services/blog/resources/posts/items/7/delete');
        $request->setRouteParams(['id' => 'blog', 'resource' => 'posts', 'item' => '7']);

        $response = (new ItemDeleteAction(PanelTestDoubles::router()))->handle($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('DELETE', $transport->lastRequest()->method);
        self::assertStringEndsWith('/posts/7', $transport->lastRequest()->url);
    }

    /**
     * A vocabulary the form does not own must be a field error, not a crash:
     * both the pagination strategy and the action allowlist refuse an unknown
     * word in their constructor, and a POST is just text.
     */
    #[Test]
    public function aTamperedResourceFormIsRefusedAsAFieldError(): void
    {
        $this->seed('Blog', []);

        $pagination = (new ResourceFormAction(PanelTestDoubles::router()))
            ->createSubmit($this->resourceRequest(['pagination' => 'offset']));

        self::assertSame(422, $pagination->getStatusCode());
        self::assertStringContainsString('not a pagination strategy', $pagination->getContent());

        $actions = (new ResourceFormAction(PanelTestDoubles::router()))
            ->createSubmit($this->resourceRequest(['actions' => ['list', 'list']]));

        self::assertSame(422, $actions->getStatusCode());
        self::assertStringContainsString('listed twice', $actions->getContent());

        $spec = RestApiServices::repository()->find('blog');
        self::assertNotNull($spec);
        self::assertSame([], $spec->resources);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function resourceRequest(array $overrides): Request
    {
        $request = Request::create('POST', '/mark/rest/services/blog/resources', body: $overrides + [
            'name'       => 'posts',
            'path'       => '/posts',
            'item_path'  => '/posts/{id}',
            'id_field'   => 'id',
            'pagination' => 'page',
            'actions'    => ['list', 'read'],
            'fields'     => '[{"name":"id","label":"ID","type":"text"}]',
        ]);
        $request->setRouteParams(['id' => 'blog']);

        return $request;
    }

    /**
     * @param list<array<string, mixed>> $resources
     */
    private function seed(string $name, array $resources): void
    {
        $spec = RestApiServices::validator()->validate([
            'service' => [
                'id'       => 'blog',
                'name'     => $name,
                'base_url' => 'https://api.example.test',
                'auth'     => ['header' => null, 'token_env' => null],
            ],
            'resources' => $resources,
        ]);

        RestApiServices::save($spec);
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/fixtures/' . $name);

        self::assertIsString($contents);

        return $contents;
    }

}

/**
 * The panel's test doubles live outside the TestCase class.
 *
 * Measured 2026-11-13 on PHP 8.5.12: a TestCase whose two private helper
 * methods return anonymous classes (one implementing `RouterInterface`, one
 * `CliOutput`) segfaults while the file loads, before PHPUnit can run a single
 * test. The same two methods on a plain class load fine, and so does the
 * TestCase without them. Keeping the doubles here, where they are plain code,
 * is the smallest shape that runs — and it makes the fake router reusable by
 * the next panel test.
 */
final class PanelTestDoubles
{
    public static function router(): \Modules\Core\Kernel\Contracts\RouterInterface
    {
        return new class implements \Modules\Core\Kernel\Contracts\RouterInterface {
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
