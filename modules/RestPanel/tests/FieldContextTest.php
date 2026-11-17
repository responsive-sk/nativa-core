<?php

declare(strict_types = 1);

namespace Modules\RestPanel\Tests;

use Modules\RestPanel\Application\Spec\SpecValidationException;
use Modules\RestPanel\Application\Spec\SpecValidator;
use Modules\RestPanel\Domain\Model\FieldContext;
use Modules\RestPanel\Domain\Model\FieldSpec;
use Modules\RestPanel\Interfaces\HTTP\FieldControl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FieldContextTest — one definition, three presentations, and no renderer
 * deciding for itself.
 *
 * The defect this guards is narrow: before contexts, the table read `inList`,
 * the form read `readOnly` and the detail page read nothing, so a field that
 * should be visible in a table but absent from a detail page had no way to say
 * so. Every case below is a sentence an operator would say about a field, and
 * the assertion is that `FieldSpec` answers it — not that three call sites were
 * edited to agree.
 */
final class FieldContextTest extends TestCase
{
    private const string MODULE = 'RestPanel';

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — its slice ships with the module.');
        }
    }

    #[Test]
    public function oneDefinitionAnswersAllThreeContexts(): void
    {
        $field = new FieldSpec(name: 'slug', label: 'Slug', readOnlyIn: [FieldContext::Form]);

        self::assertTrue($field->visibleIn(FieldContext::List));
        self::assertTrue($field->visibleIn(FieldContext::Form));
        self::assertTrue($field->visibleIn(FieldContext::Detail));
        self::assertFalse($field->editableIn(FieldContext::Form));

        // Only the form submits, so a list cell and a detail row are not
        // editable regardless of what the spec says.
        self::assertFalse($field->editableIn(FieldContext::List));
        self::assertFalse($field->editableIn(FieldContext::Detail));
    }

    #[Test]
    public function theListCellAndTheDetailRowCanDisagree(): void
    {
        $hiddenInDetail = new FieldSpec(name: 'summary', label: 'Summary', hiddenIn: [FieldContext::Detail]);

        self::assertTrue($hiddenInDetail->visibleIn(FieldContext::List));
        self::assertFalse($hiddenInDetail->visibleIn(FieldContext::Detail));

        $notInList = new FieldSpec(name: 'body', label: 'Body', inList: false);

        self::assertFalse($notInList->visibleIn(FieldContext::List));
        self::assertTrue($notInList->visibleIn(FieldContext::Detail));
    }

    #[Test]
    public function theFormRendersNothingForAFieldItCannotShow(): void
    {
        $field = new FieldSpec(name: 'slug', label: 'Slug', hiddenIn: [FieldContext::Form]);

        self::assertSame('', FieldControl::render($field, 'hello'));
    }

    #[Test]
    public function aFieldReadOnlyOnTheFormStillRendersATableCell(): void
    {
        $field = new FieldSpec(name: 'slug', label: 'Slug', readOnlyIn: [FieldContext::Form]);

        self::assertStringContainsString('readonly', FieldControl::render($field, 'hello'));
        self::assertTrue($field->visibleIn(FieldContext::List));
        self::assertSame('slug', $field->toArray()['name']);
        self::assertSame('text', $field->toArray()['type']);
        self::assertSame(['form'], $field->toArray()['read_only_in'] ?? null);
    }

    #[Test]
    public function anOldDocumentSavesBackUnchanged(): void
    {
        $field = new FieldSpec(name: 'slug', label: 'Slug');

        self::assertSame(
            [
                'name'      => 'slug',
                'label'     => 'Slug',
                'type'      => 'text',
                'required'  => false,
                'read_only' => false,
                'in_list'   => true,
            ],
            $field->toArray(),
        );
    }

    #[Test]
    public function perContextOverridesAreWrittenOnlyWhenTheySaySomething(): void
    {
        $field = new FieldSpec(
            name: 'slug',
            label: 'Slug',
            hiddenIn: [FieldContext::Detail],
            readOnlyIn: [FieldContext::Form],
        );

        $document = $field->toArray();

        self::assertSame(['detail'], $document['hidden_in'] ?? null);
        self::assertSame(['form'], $document['read_only_in'] ?? null);
    }

    #[Test]
    public function theValidatorRefusesAContextItDoesNotHave(): void
    {
        $this->expectException(SpecValidationException::class);
        $this->expectExceptionMessage('"table" is not a context; use list, form, detail.');

        (new SpecValidator())->validate([
            'service'   => ['id' => 'blog', 'name' => 'Blog API', 'base_url' => 'https://api.blog.test'],
            'resources' => [[
                'name'      => 'posts',
                'path'      => '/posts',
                'item_path' => '/posts/{id}',
                'fields'    => [[
                    'name'      => 'slug',
                    'label'     => 'Slug',
                    'type'      => 'text',
                    'hidden_in' => ['table'],
                ]],
            ]],
        ]);
    }

    #[Test]
    public function aRequiredFieldReadOnlyOnTheFormIsRefused(): void
    {
        $this->expectException(SpecValidationException::class);
        $this->expectExceptionMessage('a read-only field is never submitted');

        (new SpecValidator())->validate([
            'service'   => ['id' => 'blog', 'name' => 'Blog API', 'base_url' => 'https://api.blog.test'],
            'resources' => [[
                'name'      => 'posts',
                'path'      => '/posts',
                'item_path' => '/posts/{id}',
                'fields'    => [[
                    'name'         => 'slug',
                    'label'        => 'Slug',
                    'type'         => 'text',
                    'required'     => true,
                    'read_only_in' => ['form'],
                ]],
            ]],
        ]);
    }

    #[Test]
    public function theValidatorCarriesContextsThrough(): void
    {
        $spec = (new SpecValidator())->validate([
            'service'   => ['id' => 'blog', 'name' => 'Blog API', 'base_url' => 'https://api.blog.test'],
            'resources' => [[
                'name'      => 'posts',
                'path'      => '/posts',
                'item_path' => '/posts/{id}',
                'fields'    => [[
                    'name'         => 'slug',
                    'label'        => 'Slug',
                    'type'         => 'text',
                    'hidden_in'    => ['detail'],
                    'read_only_in' => ['form'],
                ]],
            ]],
        ]);

        $field = $spec->resources[0]->fields[0];

        self::assertSame([FieldContext::Detail], $field->hiddenIn);
        self::assertSame([FieldContext::Form], $field->readOnlyIn);
        self::assertTrue($field->visibleIn(FieldContext::List));
        self::assertFalse($field->visibleIn(FieldContext::Detail));
        self::assertFalse($field->editableIn(FieldContext::Form));
    }
}
