<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Interfaces\HTTP\Actions\UsersAction;
use Modules\Security\Domain\PasswordPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * UsersValidationTest — the admin user form's legacy error strings survive
 * the Validation-module refactor, in the same order.
 *
 * `UsersAction::validate()` is private and the HTTP stack around it is
 * heavy, so the test reaches it via reflection with a constructor-less
 * instance (the router instantiates actions without arguments, and the
 * method only touches its own parameters).
 *
 * The password MINIMUM is not asserted here as a literal (audit finding #3):
 * the admin form composes `PasswordPolicy::minRule()` — the same owner the
 * public register and reset flows ask — so the assertions read the message
 * from the policy and exercise its boundary. One admin hiding a weaker form
 * from the reader is exactly the drift the shared owner removes.
 *
 * Like every module test it drives the INSTALLED copy and skips when the
 * module (or its Validation dependency) is not installed.
 */
final class UsersValidationTest extends TestCase
{
    /** @var callable(string, string, string, bool): list<string> */
    private $validate;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Cardboard', 'Validation', 'Security'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $method = new ReflectionMethod(UsersAction::class, 'validate');
        $action = (new \ReflectionClass(UsersAction::class))->newInstanceWithoutConstructor();

        $this->validate = static fn (string $name, string $email, #[\SensitiveParameter] string $password, bool $required): array =>
            /** @var list<string> */
            $method->invoke($action, $name, $email, $password, $required);
    }

    #[Test]
    public function validCreatePasses(): void
    {
        $this->assertSame([], ($this->validate)('Eva', 'eva@example.test', 'long-enough-pw', true));
    }

    #[Test]
    public function invalidCreateReportsLegacyMessagesInOrder(): void
    {
        $this->assertSame(
            [
                'Name is required.',
                'A valid email is required.',
                'Password is required.',
            ],
            ($this->validate)('', 'not-an-email', '', true),
        );
    }

    #[Test]
    public function shortPasswordReportsThePolicyMessage(): void
    {
        $this->assertSame(
            [PasswordPolicy::tooShortMessage()],
            ($this->validate)('Eva', 'eva@example.test', 'short', true),
        );
    }

    /**
     * The policy's boundary, exercised through the admin form: one character
     * under the minimum is refused, exactly at the minimum is accepted.
     */
    #[Test]
    public function theMinimumLengthIsTheBoundary(): void
    {
        $under = str_repeat('p', PasswordPolicy::MIN_LENGTH - 1);

        $this->assertSame(
            [PasswordPolicy::tooShortMessage()],
            ($this->validate)('Eva', 'eva@example.test', $under, true),
        );

        $at = str_repeat('p', PasswordPolicy::MIN_LENGTH);

        $this->assertSame([], ($this->validate)('Eva', 'eva@example.test', $at, true));
    }

    #[Test]
    public function editModeLeavesBlankPasswordAlone(): void
    {
        $this->assertSame([], ($this->validate)('Eva', 'eva@example.test', '', false));
    }

    #[Test]
    public function blankNamesAreRejected(): void
    {
        // Tightening vs the old `=== ''` check (documented on the method):
        // a whitespace-only name is not a name.
        $this->assertSame(['Name is required.'], ($this->validate)('   ', 'eva@example.test', 'long-enough-pw', true));
    }
}
