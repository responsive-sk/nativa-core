<?php

declare(strict_types = 1);

namespace Modules\Questions\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Questions\Interfaces\HTTP\Actions\QuestionSubmitAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * QuestionValidationTest — the question form's legacy error strings survive
 * the Validation-module refactor, in the same order.
 *
 * `QuestionSubmitAction::errors()` is private, so the test reaches it via
 * reflection with a constructor-less instance and real Request objects built
 * by `Request::create()` (no HTTP stack involved).
 *
 * Like every module test it drives the INSTALLED copy and skips when the
 * module (or its Validation dependency) is not installed.
 */
final class QuestionValidationTest extends TestCase
{
    /** @var callable(Request): list<string> */
    private $errors;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Questions', 'Validation'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $method = new ReflectionMethod(QuestionSubmitAction::class, 'errors');
        $action = (new \ReflectionClass(QuestionSubmitAction::class))->newInstanceWithoutConstructor();

        $this->errors = static fn (Request $request): array =>
            /** @var list<string> */
            $method->invoke($action, $request);
    }

    /** @param array<string, string> $body */
    private static function request(array $body): Request
    {
        return Request::create('POST', '/q/ask', [], $body);
    }

    #[Test]
    public function validPayloadPasses(): void
    {
        $request = self::request(['display_name' => 'Eva', 'text' => 'How?', 'attempted_answer' => 'This.']);

        $this->assertSame([], ($this->errors)($request));
    }

    #[Test]
    public function missingFieldsReportLegacyMessagesInOrder(): void
    {
        $this->assertSame(
            ['Please enter your name.', 'Please enter your question.', 'Please describe what you tried.'],
            ($this->errors)(self::request([])),
        );
    }

    #[Test]
    public function blankFieldsAreRejected(): void
    {
        // stringOrNull() trims and maps blanks to null — identical to before.
        $request = self::request(['display_name' => '   ', 'text' => '', 'attempted_answer' => '  ']);

        $this->assertSame(
            ['Please enter your name.', 'Please enter your question.', 'Please describe what you tried.'],
            ($this->errors)($request),
        );
    }

    #[Test]
    public function partialPayloadReportsOnlyMissing(): void
    {
        $request = self::request(['display_name' => 'Eva', 'text' => 'How?']);

        $this->assertSame(['Please describe what you tried.'], ($this->errors)($request));
    }
}
