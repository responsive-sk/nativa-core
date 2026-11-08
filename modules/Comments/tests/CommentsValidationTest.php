<?php

declare(strict_types = 1);

namespace Modules\Comments\Tests;

use Modules\Comments\Application\Services\CommentsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * CommentsValidationTest — the comment form's legacy error strings survive
 * the Validation-module refactor, in the same order.
 *
 * `CommentsService::validate()` is private and submit() needs the database
 * plus rate limiting, so the test reaches it via reflection with a
 * constructor-less instance (the method only touches its own parameters and
 * the length constants).
 *
 * Like every module test it drives the INSTALLED copy and skips when the
 * module (or its Validation dependency) is not installed.
 */
final class CommentsValidationTest extends TestCase
{
    /** @var callable(string, ?string, ?string, ?string, string): list<string> */
    private $validate;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Comments', 'Validation'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $method = new ReflectionMethod(CommentsService::class, 'validate');
        $service = (new \ReflectionClass(CommentsService::class))->newInstanceWithoutConstructor();

        $this->validate = static fn (string $slug, ?string $userId, ?string $guestName, ?string $guestEmail, string $body): array =>
            /** @var list<string> */
            $method->invoke($service, $slug, $userId, $guestName, $guestEmail, $body);
    }

    #[Test]
    public function memberSubmitPasses(): void
    {
        $this->assertSame([], ($this->validate)('hello-world', 'uid-1', null, null, 'Nice post!'));
    }

    #[Test]
    public function memberSkipsGuestRules(): void
    {
        // Guest fields are not even read for signed-in authors.
        $this->assertSame([], ($this->validate)('hello-world', 'uid-1', null, 'not-an-email', 'Nice post!'));
    }

    #[Test]
    public function badSlugReportsInvalidArticle(): void
    {
        $this->assertSame(['Invalid article.'], ($this->validate)('no good!!', 'uid-1', null, null, 'Nice post!'));
        $this->assertSame(['Invalid article.'], ($this->validate)('', 'uid-1', null, null, 'Nice post!'));
    }

    #[Test]
    public function bodyLengthIsEnforced(): void
    {
        $this->assertSame(['Comment body is required.'], ($this->validate)('hello-world', 'uid-1', null, null, ''));
        $this->assertSame(
            ['Comment is too long (max 4000 characters).'],
            ($this->validate)('hello-world', 'uid-1', null, null, str_repeat('x', 4001)),
        );
    }

    #[Test]
    public function guestMustProvideNameAndEmail(): void
    {
        $this->assertSame(
            ['Name is required.', 'A valid email address is required.'],
            ($this->validate)('hello-world', null, null, null, 'Hi!'),
        );
        $this->assertSame(
            ['Name is required.', 'A valid email address is required.'],
            ($this->validate)('hello-world', null, '', 'not-an-email', 'Hi!'),
        );
    }

    #[Test]
    public function guestNameLengthIsEnforced(): void
    {
        $this->assertSame(
            ['Name is too long (max 120 characters).'],
            ($this->validate)('hello-world', null, str_repeat('n', 121), 'eva@example.test', 'Hi!'),
        );
    }

    #[Test]
    public function guestValidSubmitPasses(): void
    {
        $this->assertSame([], ($this->validate)('hello-world', null, 'Eva', 'eva@example.test', 'Hi!'));
    }

    #[Test]
    public function errorsKeepFieldOrder(): void
    {
        $this->assertSame(
            ['Invalid article.', 'Comment body is required.', 'Name is required.', 'A valid email address is required.'],
            ($this->validate)('bad slug!', null, null, null, ''),
        );
    }
}
