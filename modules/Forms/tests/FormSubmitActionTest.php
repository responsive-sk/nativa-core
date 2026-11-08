<?php

declare(strict_types = 1);

namespace Modules\Forms\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Forms\Application\FormsServices;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Model\FormSchema;
use Modules\Forms\Infrastructure\Persistence\Migrations\FormsDatabaseMigrator;
use Modules\Forms\Interfaces\HTTP\Actions\FormSubmitAction;
use Modules\Pages\Application\Services\PageCrudService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FormSubmitActionTest — the public write path.
 *
 * The pages these cases return to are real files under `storage/pages/`,
 * created in setUp and removed in tearDown: the re-render path resolves the
 * return target against PUBLISHED content, and a fixture that skipped that
 * would prove a branch the endpoint never takes.
 */
final class FormSubmitActionTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $pages = [];

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Forms')) {
            $this->markTestSkipped('Forms is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/forms-submit-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);

        putenv('DB_FORMS=' . $this->dir . '/forms.db');
        putenv('DB_USERS=' . $this->dir . '/users.db');
        DatabaseConnectionManager::resetInstance();
        FormsServices::reset();

        $output = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };

        (new FormsDatabaseMigrator(DatabaseConnectionManager::instance()->connection('forms')))->run($output);
    }

    protected function tearDown(): void
    {
        if (!isset($this->dir)) {
            return;
        }

        foreach ($this->pages as $slug) {
            $file = APP_BASE_PATH . '/storage/pages/' . $slug . '.md';

            if (is_file($file)) {
                unlink($file);
            }
        }

        FormsServices::reset();
        DatabaseConnectionManager::resetInstance();
        putenv('DB_FORMS');
        putenv('DB_USERS');

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }

        is_dir($this->dir) && rmdir($this->dir);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function form(array $rows, string $slug = 'contact'): Form
    {
        $form = Form::create(
            $slug,
            'sk_SK',
            'Contact',
            '',
            'Thank you',
            FormSchema::fromRows($rows),
            true,
        );

        FormsServices::forms()->insert($form);

        return $form;
    }

    private function publishedPage(string $slug = 'about-forms-test', string $body = '{{form:contact}}'): string
    {
        (new PageCrudService())->save($slug, ['title' => 'About', 'published' => true], $body, 'sk_SK');
        $this->pages[] = $slug;

        return '/' . $slug;
    }

    /**
     * @param array<string, string> $body
     */
    private static function submit(array $body, string $slug = 'contact'): Request
    {
        $request = Request::create('POST', '/forms/' . $slug, [], $body);
        $request->setAttribute('locale', 'sk_SK');
        $request->setRouteParams(['slug' => $slug]);

        return $request;
    }

    #[Test]
    public function aValidSubmissionIsStoredOnce(): void
    {
        $form = self::form([
            ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '1', 'max' => '120'],
            ['key' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => '1', 'max' => '200'],
        ]);

        $response = (new FormSubmitAction())->handle(self::submit([
            'name' => 'Eva',
            'email' => 'eva@example.test',
            'admin' => 'injected',
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('?form-sent=1#form-contact', (string) $response->getHeader('Location'));

        $submissions = FormsServices::submissions()->listForForm($form->id);

        self::assertCount(1, $submissions);
        self::assertSame(['name' => 'Eva', 'email' => 'eva@example.test'], $submissions[0]->payload);
    }

    #[Test]
    public function successRedirectsToTheEmbeddingForm(): void
    {
        self::form([['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '', 'max' => '120']]);
        $path = $this->publishedPage();

        $response = (new FormSubmitAction())->handle(self::submit(['name' => 'Eva', 'form_return' => $path]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($path . '?form-sent=1#form-contact', $response->getHeader('Location'));
    }

    #[Test]
    public function anUnsafeReturnPathFallsBackToTheLocaleHome(): void
    {
        self::form([['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '', 'max' => '120']]);

        $response = (new FormSubmitAction())->handle(self::submit([
            'name' => 'Eva',
            'form_return' => '//evil.test/steal',
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/?form-sent=1#form-contact', $response->getHeader('Location'));
    }

    #[Test]
    public function invalidAndUndeclaredValuesAreRefused(): void
    {
        $form = self::form([
            ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '1', 'max' => '120'],
            ['key' => 'topic', 'label' => 'Topic', 'type' => 'select', 'required' => '', 'options' => "Sales\nSupport"],
        ]);
        $path = $this->publishedPage();

        $response = (new FormSubmitAction())->handle(self::submit([
            'name' => '',
            'topic' => 'Billing',
            'admin' => 'injected',
            'form_return' => $path,
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Please fill in', $response->getContent());
        self::assertStringContainsString('listed options', $response->getContent());
        self::assertSame([], FormsServices::submissions()->listForForm($form->id));
    }

    #[Test]
    public function anInvalidSubmissionKeepsTheOldInput(): void
    {
        self::form([['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '1', 'max' => '120']]);
        $path = $this->publishedPage();

        $response = (new FormSubmitAction())->handle(self::submit([
            'name' => '',
            'form_return' => $path,
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('aria-invalid="true"', $response->getContent());
    }

    #[Test]
    public function anInvalidSubmissionWithoutATargetAnswers400(): void
    {
        $form = self::form([['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '1', 'max' => '120']]);

        $response = (new FormSubmitAction())->handle(self::submit(['name' => '', 'form_return' => '/missing-page']));

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($response->getHeader('Location'));
        self::assertSame([], FormsServices::submissions()->listForForm($form->id));
    }

    #[Test]
    public function aRequiredConsentBoxIsEnforced(): void
    {
        self::form([
            ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '', 'max' => '120'],
            ['key' => 'consent', 'label' => 'I agree', 'type' => 'checkbox', 'required' => '1'],
        ]);
        $path = $this->publishedPage();

        $response = (new FormSubmitAction())->handle(self::submit(['name' => 'Eva', 'form_return' => $path]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('confirm', $response->getContent());
    }

    #[Test]
    public function aFilledHoneypotWritesNothing(): void
    {
        $form = self::form([['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '', 'max' => '120']]);

        $response = (new FormSubmitAction())->handle(self::submit([
            'name' => 'Eva',
            'form_website' => 'https://spam.test',
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame([], FormsServices::submissions()->listForForm($form->id));
    }

    #[Test]
    public function theSixthSubmissionIsRefusedWithoutARedirect(): void
    {
        self::form([['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '', 'max' => '120']]);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(302, (new FormSubmitAction())->handle(self::submit(['name' => 'Eva']))->getStatusCode());
        }

        $response = (new FormSubmitAction())->handle(self::submit(['name' => 'Eva']));

        self::assertSame(429, $response->getStatusCode());
        self::assertNull($response->getHeader('Location'));
    }

    #[Test]
    public function anUnpublishedOrUnknownFormIsANotFoundRefusal(): void
    {
        $form = Form::create('draft', 'sk_SK', 'Draft', '', '', FormSchema::fromRows([
            ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '', 'max' => '120'],
        ]), false);
        FormsServices::forms()->insert($form);

        $draft = (new FormSubmitAction())->handle(self::submit(['name' => 'Eva'], 'draft'));
        $unknown = (new FormSubmitAction())->handle(self::submit(['name' => 'Eva'], 'unknown'));

        self::assertSame(404, $draft->getStatusCode());
        self::assertSame(404, $unknown->getStatusCode());
        self::assertNull($draft->getHeader('Location'));
    }
}
