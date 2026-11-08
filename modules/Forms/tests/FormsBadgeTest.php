<?php

declare(strict_types = 1);

namespace Modules\Forms\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Forms\Application\FormsServices;
use Modules\Forms\Application\Services\FormsBadge;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Entities\FormSubmission;
use Modules\Forms\Domain\Model\FormSchema;
use Modules\Forms\Infrastructure\Persistence\Migrations\FormsDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FormsBadgeTest — the nav badge is queue depth, not an unread marker.
 *
 * Handling a submission clears one from the number, because handling is the
 * work. A provider that counted every row would never clear, and one that
 * threw on a missing table would blank the sidebar on a fresh install.
 */
final class FormsBadgeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Forms')) {
            $this->markTestSkipped('Forms is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/forms-badge-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);

        putenv('DB_FORMS=' . $this->dir . '/forms.db');
        DatabaseConnectionManager::resetInstance();
        FormsServices::reset();
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

    private function migrate(): void
    {
        $output = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };

        (new FormsDatabaseMigrator(DatabaseConnectionManager::instance()->connection('forms')))->run($output);
    }

    private static function form(): Form
    {
        $form = Form::create('contact', 'sk_SK', 'Contact', '', '', FormSchema::fromRows([
            ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '', 'max' => '120'],
        ]), true);

        FormsServices::forms()->insert($form);

        return $form;
    }

    #[Test]
    public function handlingOneSubmissionClearsOneFromTheBadge(): void
    {
        $this->migrate();
        $form = self::form();

        FormsServices::submissions()->insert(FormSubmission::create($form->id, $form->slug, $form->locale, ['name' => 'A'], null));
        FormsServices::submissions()->insert(FormSubmission::create($form->id, $form->slug, $form->locale, ['name' => 'B'], null));

        $badge = new FormsBadge();

        self::assertSame('/mark/forms', $badge->navHref());
        self::assertSame(2, $badge->count());

        FormsServices::submissions()->markHandled(FormsServices::submissions()->listForForm($form->id)[0]->id);

        self::assertSame(1, $badge->count());
    }

    #[Test]
    public function aMissingDatabaseAnswersZero(): void
    {
        // No migrate: the file is created on first connection, the TABLE is not.
        self::assertSame(0, (new FormsBadge())->count());
    }
}
