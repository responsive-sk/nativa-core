<?php

declare(strict_types = 1);

namespace Modules\Validation\Tests;

use InvalidArgumentException;
use Modules\Validation\Rules\Max;
use Modules\Validation\Validation\ValidationException;
use Modules\Validation\Validation\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ValidatorTest — the Validation module's contract, beside the code it
 * covers (see TaxonomyRegistryTest for why module tests live here): the
 * suite drives the INSTALLED copy and skips on a kernel-only tree.
 */
// Every password-shaped value in this file is a fixture the rules are asserted
// against: the literal IS the subject, and a constant would only move it one
// name away from the assertion while hiding what the rule was given.
// @mago-expect lint:no-literal-password
final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(Validator::class)) {
            $this->markTestSkipped('Validation is not installed.');
        }

        $this->validator = new Validator();
    }

    #[Test]
    public function registrationPayloadValidates(): void
    {
        $errors = $this->validator->validate(
            ['name' => 'Eva', 'email' => 'eva@example.test', 'password' => 'correct-horse', 'password_confirmation' => 'correct-horse'],
            ['name' => 'required|max:255', 'email' => 'required|email', 'password' => 'required|min:8|confirmed'],
        );

        $this->assertTrue($errors->isEmpty());
    }

    #[Test]
    public function emptyPasswordReportsOnce(): void
    {
        // A failed Required stops the field: an empty `required|min:8`
        // password is one error, not two (deliberate Marko deviation).
        $errors = $this->validator->validate(
            ['password' => '', 'password_confirmation' => ''],
            ['password' => 'required|min:8|confirmed'],
        );

        $this->assertSame(1, $errors->count());
    }

    #[Test]
    public function customMessagesUseFieldRuleKeys(): void
    {
        $errors = $this->validator->validate(
            ['name' => '', 'email' => 'nope', 'password' => 'short', 'password_confirmation' => 'other'],
            ['name' => 'required|max:255', 'email' => 'required|email', 'password' => 'required|min:8|confirmed'],
            [
                'name.required'       => 'Name is required (max 255 characters).',
                'name.max'            => 'Name is required (max 255 characters).',
                'email.email'         => 'A valid email address is required.',
                'password.min'        => 'Password must be at least 8 characters.',
                'password.confirmed'  => 'Password confirmation does not match.',
            ],
        );

        $this->assertSame(
            [
                'Name is required (max 255 characters).',
                'A valid email address is required.',
                'Password must be at least 8 characters.',
                'Password confirmation does not match.',
            ],
            $errors->all(),
        );
    }

    #[Test]
    public function numericModeComparesByValue(): void
    {
        // `string|min:8` measures length: the one-char password "9" fails.
        $this->assertTrue($this->validator->fails(['age' => '9'], ['age' => 'string|min:8']));
        // `integer|min:18` measures value: 9 < 18 fails, 21 passes.
        $this->assertTrue($this->validator->fails(['age' => '9'], ['age' => 'integer|min:18']));
        $this->assertTrue($this->validator->passes(['age' => '21'], ['age' => 'integer|min:18']));
        $this->assertTrue($this->validator->fails(['age' => 'old'], ['age' => 'integer|min:18']));
    }

    #[Test]
    public function wildcardsErrorUnderConcretePaths(): void
    {
        $errors = $this->validator->validate(
            ['items' => [['email' => 'ok@example.test'], ['email' => 'nope']]],
            ['items.*.email' => 'required|email'],
        );

        $this->assertSame(['items.1.email'], array_keys($errors->toArray()));
    }

    #[Test]
    public function wildcardConfirmationResolvesRelatively(): void
    {
        $valid = $this->validator->validate(
            ['items' => [['password' => 'secret123', 'password_confirmation' => 'secret123']]],
            ['items.*.password' => 'required|min:8|confirmed'],
        );
        $this->assertTrue($valid->isEmpty());

        $invalid = $this->validator->validate(
            ['items' => [['password' => 'secret123', 'password_confirmation' => 'other']]],
            ['items.*.password' => 'required|min:8|confirmed'],
        );
        $this->assertSame(['items.0.password'], array_keys($invalid->toArray()));
    }

    #[Test]
    public function nullableAndOptionalEmptyValuesAreSkipped(): void
    {
        $this->assertTrue($this->validator->passes(['nick' => ''], ['nick' => 'nullable|min:8']));
        $this->assertTrue($this->validator->passes(['nick' => ''], ['nick' => 'min:8']));
        $this->assertTrue($this->validator->fails(['nick' => 'abc'], ['nick' => 'nullable|min:8']));
        $this->assertTrue($this->validator->fails(['nick' => ''], ['nick' => 'required|min:8']));
    }

    #[Test]
    public function scalarRules(): void
    {
        $this->assertTrue($this->validator->passes(['a' => 'x'], ['a' => 'required|string']));
        $this->assertTrue($this->validator->fails(['a' => 42], ['a' => 'string']));
        $this->assertTrue($this->validator->passes(['a' => '3'], ['a' => 'integer|between:1,5']));
        $this->assertTrue($this->validator->fails(['a' => '9'], ['a' => 'integer|between:1,5']));
        $this->assertTrue($this->validator->passes(['a' => 'abcde'], ['a' => 'between:3,5']));
        $this->assertTrue($this->validator->passes(['a' => '1'], ['a' => 'boolean']));
        $this->assertTrue($this->validator->fails(['a' => 'yes'], ['a' => 'boolean']));
        $this->assertTrue($this->validator->passes(['a' => [1]], ['a' => 'array|min:1']));
        $this->assertTrue($this->validator->passes(['a' => '2026-10-13'], ['a' => 'date']));
        $this->assertTrue($this->validator->fails(['a' => 'yesterdayish'], ['a' => 'date']));
        $this->assertTrue($this->validator->passes(['a' => 'https://example.test/x'], ['a' => 'url']));
        $this->assertTrue($this->validator->fails(['a' => 'example.test'], ['a' => 'url']));
        $this->assertTrue($this->validator->passes(['a' => 'sk_SK'], ['a' => 'regex:/^[a-z]{2}_[A-Z]{2}$/']));
        $this->assertTrue($this->validator->fails(['a' => 'sk'], ['a' => 'regex:/^[a-z]{2}_[A-Z]{2}$/']));
        $this->assertTrue($this->validator->passes(['a' => 'editor'], ['a' => 'in:admin,editor']));
        $this->assertTrue($this->validator->fails(['a' => 'root'], ['a' => 'in:admin,editor']));
        $this->assertTrue($this->validator->passes(['a' => 'root'], ['a' => 'not_in:admin,editor']));
    }

    #[Test]
    public function crossFieldRules(): void
    {
        $this->assertTrue($this->validator->passes(
            ['password' => 'secret123', 'password_confirmation' => 'secret123'],
            ['password' => 'confirmed'],
        ));
        $this->assertTrue($this->validator->fails(
            ['password' => 'secret123', 'password_confirmation' => 'other'],
            ['password' => 'confirmed'],
        ));
        $this->assertTrue($this->validator->passes(['a' => 'x', 'b' => 'x'], ['a' => 'same:b']));
        $this->assertTrue($this->validator->fails(['a' => 'x', 'b' => 'y'], ['a' => 'same:b']));
        $this->assertTrue($this->validator->passes(['a' => 'x', 'b' => 'y'], ['a' => 'different:b']));
        $this->assertTrue($this->validator->fails(['a' => 'x', 'b' => 'x'], ['a' => 'different:b']));
    }

    #[Test]
    public function ruleObjectsMixWithStrings(): void
    {
        $errors = $this->validator->validate(
            ['name' => str_repeat('n', 300)],
            ['name' => ['required', new Max(255)]],
        );

        $this->assertSame(['The name field must be at most 255.'], $errors->all());
    }

    #[Test]
    public function unknownRuleFailsLoud(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator->passes(['a' => 'x'], ['a' => 'frobnicator']);
    }

    #[Test]
    public function validateOrFailThrowsWithErrors(): void
    {
        try {
            $this->validator->validateOrFail(['email' => 'nope'], ['email' => 'required|email']);
            $this->fail('validateOrFail must throw.');
        } catch (ValidationException $e) {
            $this->assertSame(['The email field must be a valid email address.'], $e->errors()->all());
        }
    }
}
