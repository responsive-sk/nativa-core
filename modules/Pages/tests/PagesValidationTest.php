<?php

declare(strict_types = 1);

namespace Modules\Pages\Tests;

use Modules\Pages\Interfaces\HTTP\Actions\PagesCreateAction;
use Modules\Pages\Interfaces\HTTP\Actions\PagesEditAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * PagesValidationTest — the page form's legacy error strings survive the
 * Validation-module refactor, in create and edit alike.
 *
 * Both actions expose the same private `validate(array): list<string>`; the
 * test reaches it via reflection with constructor-less instances (the router
 * instantiates actions without arguments, and the method only touches its
 * own parameter).
 *
 * Like every module test it drives the INSTALLED copy and skips when the
 * module (or its Validation dependency) is not installed.
 */
final class PagesValidationTest extends TestCase
{
    /** @var callable(array<string, string>): list<string> */
    private $create;

    /** @var callable(array<string, string>): list<string> */
    private $edit;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Pages', 'Validation'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $this->create = self::validatorFor(PagesCreateAction::class);
        $this->edit = self::validatorFor(PagesEditAction::class);
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

    /** @return array<string, string> */
    private static function data(string $title = 'Hello', string $body = 'World', string $translation = ''): array
    {
        return ['title' => $title, 'body' => $body, 'translation' => $translation];
    }

    #[Test]
    public function validPayloadPasses(): void
    {
        $this->assertSame([], ($this->create)(self::data()));
        $this->assertSame([], ($this->edit)(self::data()));
    }

    #[Test]
    public function missingFieldsReportLegacyMessagesInOrder(): void
    {
        $expected = ['Title is required.', 'Content is required.'];

        $this->assertSame($expected, ($this->create)(self::data('', '')));
        $this->assertSame($expected, ($this->edit)(self::data('', '')));
    }

    #[Test]
    public function translationSlugMustBeUrlSafe(): void
    {
        $this->assertSame(
            ['Translation slug must be URL-safe.'],
            ($this->create)(self::data('Hello', 'World', 'No Good!')),
        );
        $this->assertSame([], ($this->create)(self::data('Hello', 'World', 'dobre-ano_1')));
        $this->assertSame([], ($this->edit)(self::data('Hello', 'World', '')));
    }
}
