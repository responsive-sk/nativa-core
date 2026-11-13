<?php

declare(strict_types = 1);

namespace Modules\Blog\Tests;

use Modules\Blog\Interfaces\HTTP\Actions\ArticlesCreateAction;
use Modules\Blog\Interfaces\HTTP\Actions\ArticlesEditAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * ArticleValidationTest — the article form's legacy error strings survive
 * the Validation-module refactor, in create and edit alike.
 *
 * Both actions expose the same private `validate(array): list<string>`; the
 * test reaches it via reflection with constructor-less instances (the router
 * instantiates actions without arguments, and the method only touches its
 * own parameter).
 *
 * Like every module test it drives the INSTALLED copy and skips when the
 * module (or its Validation dependency) is not installed.
 */
final class ArticleValidationTest extends TestCase
{
    /** @var callable(array<string, string>): list<string> */
    private $create;

    /** @var callable(array<string, string>): list<string> */
    private $edit;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Blog', 'Validation'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $this->create = self::validatorFor(ArticlesCreateAction::class);
        $this->edit = self::validatorFor(ArticlesEditAction::class);
    }

    /** @return callable(array<string, string>): list<string> */
    private static function validatorFor(string $class): callable
    {
        $method = new ReflectionMethod($class, 'validate');
        $action = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return static fn (array $data): array =>
            /** @var list<string> */
            $method->invoke($action, $data);
    }

    #[Test]
    public function validPayloadPasses(): void
    {
        $data = ['title' => 'Hello', 'date' => '2026-10-13', 'body' => 'World'];

        $this->assertSame([], ($this->create)($data));
        $this->assertSame([], ($this->edit)($data));
    }

    #[Test]
    public function missingFieldsReportLegacyMessagesInOrder(): void
    {
        $expected = ['Title is required.', 'Date is required.', 'Content is required.'];
        $data = ['title' => '', 'date' => '', 'body' => ''];

        $this->assertSame($expected, ($this->create)($data));
        $this->assertSame($expected, ($this->edit)($data));
    }

    #[Test]
    public function blankBodyIsRejected(): void
    {
        // Tightening vs the old `=== ''` check (documented on the method):
        // body travels raw, so whitespace-only is not content.
        $data = ['title' => 'Hello', 'date' => '2026-10-13', 'body' => '   '];

        $this->assertSame(['Content is required.'], ($this->create)($data));
        $this->assertSame(['Content is required.'], ($this->edit)($data));
    }
}
