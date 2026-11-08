<?php

declare(strict_types = 1);

namespace Modules\Forms\Tests;

use Modules\Forms\Domain\Model\FormFieldType;
use Modules\Forms\Domain\Model\FormSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FormSchemaTest — the limit table in `design.md`, one case per row.
 *
 * A schema is data an editor typed, so every rule answers with a message
 * rather than an exception. The cases below pin both halves: what is refused
 * and what a refused editor still sees (the rows it typed, keyed by row).
 */
final class FormSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Forms')) {
            $this->markTestSkipped('Forms is not installed.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(array $override = []): array
    {
        return [
            'key'      => 'name',
            'label'    => 'Your name',
            'type'     => FormFieldType::TEXT->value,
            'required' => '1',
            'max'      => '120',
            'options'  => '',
            ...$override,
        ];
    }

    #[Test]
    public function aValidDefinitionRoundTripsThroughJson(): void
    {
        $schema = FormSchema::fromRows([
            self::row(),
            self::row(['key' => 'topic', 'label' => 'Topic', 'type' => 'select', 'options' => "Sales\nSupport", 'required' => '']),
        ]);

        self::assertTrue($schema->isValid(), implode('; ', $schema->errorList()));
        self::assertSame(['name', 'topic'], array_map(static fn ($field): string => $field->key, $schema->fields()));

        $restored = FormSchema::fromJson($schema->toJson());

        self::assertTrue($restored->isValid());
        self::assertSame(['Sales', 'Support'], $restored->field('topic')?->options);
    }

    #[Test]
    public function aBlankRowIsDropped(): void
    {
        $schema = FormSchema::fromRows([self::row(), self::row(['key' => '', 'label' => '', 'max' => '', 'required' => '', 'options' => ''])]);

        self::assertTrue($schema->isValid());
        self::assertCount(1, $schema->fields());
    }

    #[Test]
    public function aFormNeedsAtLeastOneField(): void
    {
        $schema = FormSchema::fromRows([]);

        self::assertFalse($schema->isValid());
        self::assertArrayHasKey('fields', $schema->errors());
    }

    #[Test]
    public function theFieldCountIsCapped(): void
    {
        $rows = [];

        for ($i = 0; $i < FormSchema::MAX_FIELDS + 1; ++$i) {
            $rows[] = self::row(['key' => 'f' . $i]);
        }

        $schema = FormSchema::fromRows($rows);

        self::assertFalse($schema->isValid());
        self::assertArrayHasKey('fields', $schema->errors());
    }

    #[Test]
    public function aKeyMustBeUrlSafeAndUnique(): void
    {
        $unsafe = FormSchema::fromRows([self::row(['key' => 'Not A Key'])]);
        self::assertFalse($unsafe->isValid());
        self::assertArrayHasKey('fields.0.key', $unsafe->errors());

        $reserved = FormSchema::fromRows([self::row(['key' => 'form_return'])]);
        self::assertFalse($reserved->isValid());

        $duplicate = FormSchema::fromRows([self::row(), self::row()]);
        self::assertFalse($duplicate->isValid());
        self::assertArrayHasKey('fields.1.key', $duplicate->errors());
    }

    #[Test]
    public function anUnknownTypeIsRefused(): void
    {
        $schema = FormSchema::fromRows([self::row(['type' => 'file'])]);

        self::assertFalse($schema->isValid());
        self::assertArrayHasKey('fields.0.type', $schema->errors());
    }

    #[Test]
    public function optionsBelongToSelectAndRadioOnly(): void
    {
        $missing = FormSchema::fromRows([self::row(['type' => 'select', 'options' => ''])]);
        self::assertFalse($missing->isValid());

        $onCheckbox = FormSchema::fromRows([self::row(['type' => 'checkbox', 'options' => 'Yes'])]);
        self::assertFalse($onCheckbox->isValid());

        $tooMany = FormSchema::fromRows([self::row([
            'type' => 'radio',
            'options' => implode("\n", array_map(static fn (int $i): string => 'o' . $i, range(1, FormSchema::MAX_OPTIONS + 1))),
        ])]);
        self::assertFalse($tooMany->isValid());
    }

    #[Test]
    public function maxLengthIsBounded(): void
    {
        self::assertFalse(FormSchema::fromRows([self::row(['max' => '0'])])->isValid());
        self::assertFalse(FormSchema::fromRows([self::row(['max' => '5001'])])->isValid());
        self::assertTrue(FormSchema::fromRows([self::row(['max' => '5000'])])->isValid());
    }

    #[Test]
    public function resultsAreOnlyForChoiceFields(): void
    {
        $onText = FormSchema::fromRows([self::row(['show_results' => '1'])]);
        self::assertFalse($onText->isValid());

        $onChoice = FormSchema::fromRows([self::row(['type' => 'select', 'options' => 'A', 'show_results' => '1'])]);
        self::assertTrue($onChoice->isValid());
    }

    #[Test]
    public function anUnparseableStoredSchemaIsInvalid(): void
    {
        $schema = FormSchema::fromJson('{not json');

        self::assertFalse($schema->isValid());
        self::assertArrayHasKey('schema', $schema->errors());
    }
}
