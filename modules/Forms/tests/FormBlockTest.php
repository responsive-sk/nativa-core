<?php

declare(strict_types = 1);

namespace Modules\Forms\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\HTTP\Markdown\MarkdownBlockContext;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Forms\Application\FormsServices;
use Modules\Forms\Application\Markdown\FormBlock;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Entities\FormSubmission;
use Modules\Forms\Domain\Model\FormSchema;
use Modules\Forms\Infrastructure\Persistence\Migrations\FormsDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FormBlockTest — `{{form:slug}}` through the kernel seam.
 *
 * The class list is checked against the stylesheet rather than asserted from
 * memory: `form.css` is the owner of the markup contract, and a class this
 * renderer invents is a rule that never matches.
 */
final class FormBlockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Forms')) {
            $this->markTestSkipped('Forms is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/forms-block-' . bin2hex(random_bytes(6));
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

    /**
     * @param array<string, mixed> $overrides
     */
    private static function store(array $overrides = []): Form
    {
        $schema = FormSchema::fromRows($overrides['rows'] ?? [[
            'key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '1', 'max' => '120',
        ]]);

        $form = new Form(
            bin2hex(random_bytes(16)),
            (string) ($overrides['slug'] ?? 'contact'),
            (string) ($overrides['locale'] ?? 'sk_SK'),
            (string) ($overrides['title'] ?? 'Contact us'),
            (string) ($overrides['intro'] ?? 'Write to us'),
            (string) ($overrides['success'] ?? 'Thank you'),
            $overrides['schema'] ?? $schema,
            (bool) ($overrides['published'] ?? true),
            new \DateTimeImmutable(),
        );

        FormsServices::forms()->insert($form);

        return $form;
    }

    private static function expand(string $markdown, string $prefix = 'je', string $path = '/o-nas', array $state = []): string
    {
        return (new FormBlock())->expand($markdown, new MarkdownBlockContext('sk_SK', $prefix, $path, ['forms' => $state]));
    }

    #[Test]
    public function aPublishedFormRendersWithTheThemePrefix(): void
    {
        self::store();

        $html = self::expand('{{form:contact}}');

        self::assertStringContainsString('je-form', $html);
        self::assertStringContainsString('class="je-form__form"', $html);
        self::assertStringContainsString('action="/forms/contact"', $html);
        self::assertStringContainsString('name="form_return" value="/o-nas"', $html);
        self::assertStringNotContainsString('st-form', $html);

        $css = (string) file_get_contents(APP_BASE_PATH . '/Frontend/packages/starter/src/form.css');

        preg_match_all('/je-form(?:__[a-z-]+)?/', $html, $matches);

        foreach (array_unique($matches[0]) as $class) {
            $starter = str_replace('je-form', 'st-form', $class);

            self::assertStringContainsString('.' . $starter, $css, $class . ' is emitted but not styled by form.css.');
        }
    }

    #[Test]
    public function nothingRendersForADraftUnknownOrBrokenForm(): void
    {
        self::store(['slug' => 'draft', 'published' => false]);
        self::store(['slug' => 'broken', 'schema' => FormSchema::fromJson('{not json')]);

        self::assertSame('', self::expand('{{form:draft}}'));
        self::assertSame('', self::expand('{{form:missing}}'));
        self::assertSame('', self::expand('{{form:broken}}'));
        self::assertStringNotContainsString('{{form:', self::expand('a {{form:draft}} b'));
    }

    #[Test]
    public function anotherPlaceholderIsLeftAlone(): void
    {
        self::store();

        $html = self::expand('{{page:contact}} {{form:contact}}');

        self::assertStringStartsWith('{{page:contact}} ', $html);
    }

    #[Test]
    public function theConfirmationAndResultsComeFromTheSentState(): void
    {
        $form = self::store([
            'rows' => [
                ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '1', 'max' => '120'],
                ['key' => 'topic', 'label' => 'Topic', 'type' => 'select', 'required' => '', 'options' => "Sales\nSupport", 'show_results' => '1'],
            ],
        ]);

        FormsServices::submissions()->insert(FormSubmission::create($form->id, $form->slug, $form->locale, ['name' => 'Eva', 'topic' => 'Sales'], null));

        $idle = self::expand('{{form:contact}}');
        self::assertStringNotContainsString('je-form__thanks', $idle);
        self::assertStringNotContainsString('je-form__bar', $idle);

        $sent = self::expand('{{form:contact}}', 'je', '/o-nas', ['sent' => true]);

        self::assertStringContainsString('je-form__thanks', $sent);
        self::assertStringContainsString('je-form__bar', $sent);
        self::assertStringContainsString('100%', $sent);
    }

    #[Test]
    public function invalidInputIsEscapedAndKept(): void
    {
        $form = self::store();

        FormsServices::submissions()->insert(FormSubmission::create($form->id, $form->slug, $form->locale, ['name' => 'Eva'], null));

        $html = self::expand('{{form:contact}}', 'je', '/o-nas', [
            'errors'  => ['Please fill in "Your name".'],
            'invalid' => ['name'],
            'old'     => ['name' => '<img src=x onerror=alert(1)>'],
        ]);

        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    #[Test]
    public function resultBarsCountTheNewestFiveHundredSubmissions(): void
    {
        $form = self::store([
            'rows' => [
                ['key' => 'topic', 'label' => 'Topic', 'type' => 'select', 'required' => '', 'options' => "A\nB", 'show_results' => '1'],
            ],
        ]);

        // The oldest answer is B; the newest 500 are A. An unbounded tally
        // would report A at 99%, the window reports 100%.
        FormsServices::submissions()->insert(FormSubmission::create($form->id, $form->slug, $form->locale, ['topic' => 'B'], null));

        for ($i = 0; $i < 500; ++$i) {
            FormsServices::submissions()->insert(FormSubmission::create($form->id, $form->slug, $form->locale, ['topic' => 'A'], null));
        }

        $html = self::expand('{{form:contact}}', 'je', '/o-nas', ['sent' => true]);

        self::assertStringContainsString('width:100%', $html);
        self::assertStringNotContainsString('width:99%', $html);
    }
}
