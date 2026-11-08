<?php

declare(strict_types = 1);

namespace Modules\Forms\Tests;

use Modules\Audit\Infrastructure\Persistence\Migrations\AuditDatabaseMigrator;
use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Forms\Application\FormsServices;
use Modules\Forms\Interfaces\HTTP\Actions\FormsCreateAction;
use Modules\Forms\Interfaces\HTTP\Actions\FormsEditAction;
use Modules\Forms\Interfaces\HTTP\Actions\FormsIndexAction;
use Modules\Forms\Infrastructure\Persistence\Migrations\FormsDatabaseMigrator;
use Modules\Users\Infrastructure\Persistence\Migrations\UsersDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FormsCrudTest — the admin surface, through the actions the router calls.
 *
 * The tests drive the INSTALLED slice and point every database the actions
 * touch (`forms`, `audit`, `users`) at throwaway files: an admin write also
 * writes an audit row and reads the session, and a test that left those on the
 * working tree would be editing the running site's log.
 */
final class FormsCrudTest extends TestCase
{
    private string $dir;

    private RouterInterface $router;

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Forms')) {
            $this->markTestSkipped('Forms is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/forms-crud-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);

        putenv('DB_FORMS=' . $this->dir . '/forms.db');
        putenv('DB_AUDIT=' . $this->dir . '/audit.db');
        putenv('DB_USERS=' . $this->dir . '/users.db');
        DatabaseConnectionManager::resetInstance();
        FormsServices::reset();
        AuditServices::reset();

        $output = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };

        $manager = DatabaseConnectionManager::instance();
        (new FormsDatabaseMigrator($manager->connection('forms')))->run($output);
        (new AuditDatabaseMigrator($manager->connection('audit')))->run($output);
        (new UsersDatabaseMigrator($manager->connection('users')))->run($output);

        $this->router = new class implements RouterInterface {
            public function match(Request $request): ?array
            {
                return null;
            }

            public function url(string $name, array $params = []): string
            {
                $slug = (string) ($params['slug'] ?? '');
                $id = (string) ($params['id'] ?? '');

                return match ($name) {
                    'mark.forms' => '/mark/forms',
                    'mark.forms.create' => '/mark/forms/create',
                    'mark.forms.edit' => '/mark/forms/' . $slug . '/edit',
                    'mark.forms.publish-toggle' => '/mark/forms/' . $slug . '/publish',
                    'mark.forms.delete' => '/mark/forms/' . $slug . '/delete',
                    'mark.forms.submissions' => '/mark/forms/' . $slug . '/submissions',
                    'mark.forms.submissions.handled' => '/mark/forms/' . $slug . '/submissions/' . $id . '/handled',
                    'mark.forms.submissions.delete' => '/mark/forms/' . $slug . '/submissions/' . $id . '/delete',
                    default => '/mark/stub/' . $name,
                };
            }
        };
    }

    protected function tearDown(): void
    {
        if (!isset($this->dir)) {
            return;
        }

        FormsServices::reset();
        AuditServices::reset();
        DatabaseConnectionManager::resetInstance();
        putenv('DB_FORMS');
        putenv('DB_AUDIT');
        putenv('DB_USERS');

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }

        is_dir($this->dir) && rmdir($this->dir);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function body(string $slug, string $locale = 'sk_SK', array $fields = []): array
    {
        return [
            'locale'          => $locale === 'en_US' ? 'en' : 'sk',
            'title'           => 'Contact',
            'slug'            => $slug,
            'intro'           => 'Write to us',
            'success_message' => 'Thank you',
            'published'       => '1',
            'fields'          => $fields ?: [
                ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '1', 'max' => '120'],
            ],
        ];
    }

    private static function post(string $path, array $body): Request
    {
        $request = Request::create('POST', $path, [], $body);
        $request->setAttribute('locale', 'sk_SK');

        return $request;
    }

    #[Test]
    public function theEditorHasAFormSaveButton(): void
    {
        $response = (new FormsCreateAction($this->router))->handle(Request::create('GET', '/mark/forms/create', ['locale' => 'sk']));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<button type="submit" class="m-btn m-btn-primary">Save form</button>', $response->getContent());
    }

    #[Test]
    public function theIndexIsServed(): void
    {
        (new FormsCreateAction($this->router))->handle(self::post('/mark/forms/create', self::body('contact')));

        $response = (new FormsIndexAction($this->router))->handle(Request::create('GET', '/mark/forms', ['locale' => 'sk']));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Contact', $response->getContent());
        self::assertStringContainsString('{{form:contact}}', $response->getContent());
    }

    #[Test]
    public function aDuplicateSlugIsRefused(): void
    {
        $action = new FormsCreateAction($this->router);

        $first = $action->handle(self::post('/mark/forms/create', self::body('contact')));
        $second = $action->handle(self::post('/mark/forms/create', self::body('contact')));

        self::assertSame(302, $first->getStatusCode());
        self::assertSame(400, $second->getStatusCode());
        self::assertStringContainsString('already used', $second->getContent());
    }

    #[Test]
    public function theSameSlugExistsOncePerLocale(): void
    {
        $action = new FormsCreateAction($this->router);

        self::assertSame(302, $action->handle(self::post('/mark/forms/create', self::body('contact', 'sk_SK')))->getStatusCode());
        self::assertSame(302, $action->handle(self::post('/mark/forms/create', self::body('contact', 'en_US')))->getStatusCode());

        self::assertNotNull(FormsServices::forms()->findBySlug('contact', 'sk_SK'));
        self::assertNotNull(FormsServices::forms()->findBySlug('contact', 'en_US'));
    }

    #[Test]
    public function aDefinitionRoundTripsThroughCreateAndEdit(): void
    {
        (new FormsCreateAction($this->router))->handle(self::post('/mark/forms/create', self::body('contact')));

        $body = self::body('contact', 'sk_SK', [
            ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '1', 'max' => '120'],
            ['key' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => '1', 'max' => '200'],
            ['key' => 'body', 'label' => 'Message', 'type' => 'textarea', 'required' => '1', 'max' => '2000'],
        ]);

        $request = self::post('/mark/forms/contact/edit', $body);
        $request->setRouteParams(['slug' => 'contact']);

        $response = (new FormsEditAction($this->router))->handle($request);

        self::assertSame(302, $response->getStatusCode());

        $form = FormsServices::forms()->findBySlug('contact', 'sk_SK');

        self::assertNotNull($form);
        self::assertSame(['name', 'email', 'body'], array_map(static fn ($field): string => $field->key, $form->schema->fields()));
        self::assertSame([], FormsServices::submissions()->listForForm($form->id), 'A fresh definition has no submissions.');
    }

    #[Test]
    public function aDefinitionEditFlushesTheCachedPage(): void
    {
        (new FormsCreateAction($this->router))->handle(self::post('/mark/forms/create', self::body('contact')));

        $dir = APP_BASE_PATH . '/storage/cache/pages';
        is_dir($dir) || mkdir($dir, 0o777, true);
        $probe = $dir . '/forms-flush-probe-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($probe, '{}');

        $request = self::post('/mark/forms/contact/edit', self::body('contact', 'sk_SK', [
            ['key' => 'name', 'label' => 'Your full name', 'type' => 'text', 'required' => '1', 'max' => '120'],
        ]));
        $request->setRouteParams(['slug' => 'contact']);

        (new FormsEditAction($this->router))->handle($request);

        self::assertFileDoesNotExist($probe);
        self::assertSame('Your full name', FormsServices::forms()->findBySlug('contact', 'sk_SK')?->schema->fields()[0]->label);
    }
}
