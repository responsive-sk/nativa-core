<?php

declare(strict_types = 1);

namespace Modules\Forms\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Forms\Application\FormsServices;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Entities\FormSubmission;
use Modules\Forms\Domain\Model\FormSchema;
use Modules\Forms\Infrastructure\Persistence\Migrations\FormsDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FormsPersistenceTest — the two tables, against a throwaway file.
 *
 * Every case points `DB_FORMS` at a fresh SQLite file. The connection manager
 * caches one connection per name and reads the environment only when a
 * connection is opened, so setUp resets the singleton AFTER `putenv()`; a
 * suite that forgot that would carry the previous test's rows into the next
 * one and still pass.
 */
final class FormsPersistenceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Forms')) {
            $this->markTestSkipped('Forms is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/forms-persistence-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);

        putenv('DB_FORMS=' . $this->dir . '/forms.db');
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

        FormsServices::reset();
        DatabaseConnectionManager::resetInstance();
        putenv('DB_FORMS');

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }

        is_dir($this->dir) && rmdir($this->dir);
    }

    private static function form(string $slug = 'contact', string $locale = 'sk_SK', bool $published = true): Form
    {
        return Form::create(
            $slug,
            $locale,
            'Contact',
            'Write to us',
            'Thank you',
            FormSchema::fromRows([
                ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '1', 'max' => '120'],
            ]),
            $published,
        );
    }

    #[Test]
    public function aSlugExistsOncePerLocale(): void
    {
        FormsServices::forms()->insert(self::form());

        $this->expectException(\PDOException::class);

        FormsServices::forms()->insert(self::form());
    }

    #[Test]
    public function theSameSlugInAnotherLocaleIsASecondDefinition(): void
    {
        FormsServices::forms()->insert(self::form('contact', 'sk_SK'));
        FormsServices::forms()->insert(self::form('contact', 'en_US'));

        self::assertNotNull(FormsServices::forms()->findBySlug('contact', 'sk_SK'));
        self::assertNotNull(FormsServices::forms()->findBySlug('contact', 'en_US'));
    }

    #[Test]
    public function onlyPublishedDefinitionsResolve(): void
    {
        $draft = self::form('draft-form', 'sk_SK', false);
        FormsServices::forms()->insert($draft);

        self::assertNotNull(FormsServices::forms()->findBySlug('draft-form', 'sk_SK'));
        self::assertNull(FormsServices::forms()->findPublished('draft-form', 'sk_SK'));
    }

    #[Test]
    public function publishingIsAToggleOnOneRow(): void
    {
        $form = self::form('draft-form', 'sk_SK', false);
        FormsServices::forms()->insert($form);

        $published = new Form(
            $form->id,
            $form->slug,
            $form->locale,
            $form->title,
            $form->intro,
            $form->successMessage,
            $form->schema,
            true,
            $form->createdAt,
        );

        FormsServices::forms()->update($published);

        self::assertNotNull(FormsServices::forms()->findPublished('draft-form', 'sk_SK'));
    }

    #[Test]
    public function aSubmissionJoinsTheQueueAndHandlingClearsIt(): void
    {
        $form = self::form();
        FormsServices::forms()->insert($form);
        FormsServices::submissions()->insert(FormSubmission::create($form->id, $form->slug, $form->locale, ['name' => 'Eva'], '127.0.0.1'));

        self::assertSame(1, FormsServices::submissions()->countNew());

        $submission = FormsServices::submissions()->listForForm($form->id)[0];
        FormsServices::submissions()->markHandled($submission->id);

        self::assertSame(0, FormsServices::submissions()->countNew());
        self::assertFalse(FormsServices::submissions()->findById($submission->id)?->isNew());
    }

    #[Test]
    public function deletingADefinitionDeletesItsSubmissions(): void
    {
        $form = self::form();
        FormsServices::forms()->insert($form);
        FormsServices::submissions()->insert(FormSubmission::create($form->id, $form->slug, $form->locale, ['name' => 'Eva'], null));

        FormsServices::forms()->delete($form->id);

        self::assertNull(FormsServices::forms()->findById($form->id));
        self::assertSame([], FormsServices::submissions()->listForForm($form->id));
    }

    #[Test]
    public function renamingKeepsSubmissionsAttached(): void
    {
        $form = self::form();
        FormsServices::forms()->insert($form);
        FormsServices::submissions()->insert(FormSubmission::create($form->id, $form->slug, $form->locale, ['name' => 'Eva'], null));

        $renamed = new Form($form->id, 'support', $form->locale, $form->title, $form->intro, $form->successMessage, $form->schema, true, $form->createdAt);
        FormsServices::forms()->update($renamed);

        self::assertCount(1, FormsServices::submissions()->listForForm($form->id));
        self::assertNotNull(FormsServices::forms()->findBySlug('support', $form->locale));
    }
}
