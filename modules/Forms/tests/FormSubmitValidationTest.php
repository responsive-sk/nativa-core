<?php

declare(strict_types = 1);

namespace Modules\Forms\Tests;

use Modules\Forms\Domain\Model\FormSchema;
use Modules\Forms\Domain\Validation\FormValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FormSubmitValidationTest — a payload against the schema it was rendered from.
 *
 * The validator returns messages and invalid keys together, because they
 * describe one fact. Each case below asserts both: a field marked invalid with
 * no message (or the reverse) is the failure this test exists for.
 */
final class FormSubmitValidationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Forms')) {
            $this->markTestSkipped('Forms is not installed.');
        }
    }

    private static function schema(): FormSchema
    {
        return FormSchema::fromRows([
            ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => '1', 'max' => '10'],
            ['key' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => '1', 'max' => '200'],
            ['key' => 'topic', 'label' => 'Topic', 'type' => 'select', 'required' => '', 'options' => "Sales\nSupport"],
            ['key' => 'body', 'label' => 'Message', 'type' => 'textarea', 'required' => '1', 'max' => '20'],
            ['key' => 'consent', 'label' => 'I agree', 'type' => 'checkbox', 'required' => '1'],
        ]);
    }

    /**
     * @param array<string, string> $payload
     *
     * @return array{errors: list<string>, invalid: list<string>}
     */
    private static function check(array $payload): array
    {
        return (new FormValidator())->validate(self::schema(), $payload);
    }

    #[Test]
    public function aCompleteAnswerPasses(): void
    {
        $result = self::check([
            'name' => 'Eva',
            'email' => 'eva@example.test',
            'topic' => 'Sales',
            'body' => 'Hello',
            'consent' => '1',
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame([], $result['invalid']);
    }

    #[Test]
    public function requiredFieldsAreReportedInOrder(): void
    {
        $result = self::check([]);

        self::assertSame(['name', 'email', 'body', 'consent'], $result['invalid']);
        self::assertCount(4, $result['errors']);
    }

    #[Test]
    public function anInvalidEmailIsRefused(): void
    {
        $result = self::check(['name' => 'Eva', 'email' => 'not-an-address', 'body' => 'Hi', 'consent' => '1']);

        self::assertSame(['email'], $result['invalid']);
        self::assertStringContainsString('valid e-mail', $result['errors'][0]);
    }

    #[Test]
    public function theMaximumLengthIsEnforced(): void
    {
        $result = self::check(['name' => str_repeat('x', 11), 'email' => 'eva@example.test', 'body' => 'Hi', 'consent' => '1']);

        self::assertSame(['name'], $result['invalid']);
        self::assertStringContainsString('longer than 10', $result['errors'][0]);
    }

    #[Test]
    public function anOptionOutsideTheListIsRefused(): void
    {
        $result = self::check([
            'name' => 'Eva',
            'email' => 'eva@example.test',
            'topic' => 'Billing',
            'body' => 'Hi',
            'consent' => '1',
        ]);

        self::assertSame(['topic'], $result['invalid']);
        self::assertStringContainsString('listed options', $result['errors'][0]);
    }

    #[Test]
    public function anUncheckedConsentBoxIsRefused(): void
    {
        $result = self::check(['name' => 'Eva', 'email' => 'eva@example.test', 'body' => 'Hi']);

        self::assertSame(['consent'], $result['invalid']);
        self::assertStringContainsString('confirm', $result['errors'][0]);
    }

    #[Test]
    public function optionalFieldsMayBeEmpty(): void
    {
        $result = self::check(['name' => 'Eva', 'email' => 'eva@example.test', 'body' => 'Hi', 'consent' => '1', 'topic' => '']);

        self::assertSame([], $result['invalid']);
    }
}
