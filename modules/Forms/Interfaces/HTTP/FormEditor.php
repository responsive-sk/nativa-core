<?php

declare(strict_types = 1);

namespace Modules\Forms\Interfaces\HTTP;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\View\InlineJs;
use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Entities\FormField;
use Modules\Forms\Domain\Model\FormFieldType;

/**
 * FormEditor — one editor for create and edit.
 *
 * Two nearly identical forms is how a create path accepts a field the edit
 * path refuses. Both actions hand their normalised rows to this class, which
 * renders the same template and the same script.
 *
 * The rows travel as plain arrays because that is what a refused POST still
 * has: when the schema is invalid there is no `FormSchema` to render, and the
 * editor must show back exactly what the editor typed.
 */
final readonly class FormEditor
{
    /**
     * @param array{slug: string, title: string, intro: string, success_message: string, published: bool, fields: list<array<string, mixed>>} $data
     * @param array<string, string> $fieldErrors keyed as FormSchema keys them
     */
    public static function render(
        string $actionUrl,
        array $data,
        string $locale,
        array $errors = [],
        array $fieldErrors = [],
    ): string {
        $localeInput = '<input type="hidden" name="locale" value="' . AdminLayout::e($locale) . '">';

        $content = '<form method="POST" action="' . AdminLayout::e($actionUrl) . '">'
            . AdminLayout::csrfField()
            . AdminLayout::messages($errors, 'err')
            . ViewRenderer::render('Modules/Forms/Interfaces/HTTP/templates/form-editor.phtml', [
                'localeInput'      => $localeInput,
                'slugValue'        => AdminLayout::e($data['slug']),
                'titleValue'       => AdminLayout::e($data['title']),
                'introValue'       => AdminLayout::e($data['intro']),
                'successValue'     => AdminLayout::e($data['success_message']),
                'publishedChecked' => $data['published'] ? 'checked' : '',
                'rows'             => self::rows($data['fields'], $fieldErrors),
            ])
            . '</form>'
            . self::script();

        return $content;
    }

    /**
     * Prefill from a stored definition.
     *
     * @return array{slug: string, title: string, intro: string, success_message: string, published: bool, fields: list<array<string, mixed>>}
     */
    public static function data(Form $form): array
    {
        $fields = [];

        foreach ($form->schema->fields() as $field) {
            $fields[] = self::row($field);
        }

        return [
            'slug'            => $form->slug,
            'title'           => $form->title,
            'intro'           => $form->intro,
            'success_message' => $form->successMessage,
            'published'       => $form->published,
            'fields'          => $fields,
        ];
    }

    /**
     * An empty editor: one blank row, so the first field needs no click.
     *
     * @return array{slug: string, title: string, intro: string, success_message: string, published: bool, fields: list<array<string, mixed>>}
     */
    public static function blank(): array
    {
        return [
            'slug'            => '',
            'title'           => '',
            'intro'           => '',
            'success_message' => '',
            'published'       => true,
            'fields'          => [[
                'key' => '', 'label' => '', 'type' => FormFieldType::TEXT->value,
                'required' => false, 'max' => '', 'options' => '', 'show_results' => false,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(FormField $field): array
    {
        return [
            'key'          => $field->key,
            'label'        => $field->label,
            'type'         => $field->type->value,
            'required'     => $field->required,
            'max'          => $field->type->isTextLike() ? $field->max : '',
            'options'      => implode("\n", $field->options),
            'show_results' => $field->showResults,
        ];
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, string>      $fieldErrors
     *
     * @return list<array{index: int, key: string, label: string, type: string, required: string, max: string, options: string, show_results: string, error: string}>
     */
    private static function rows(array $fields, array $fieldErrors): array
    {
        $rows = [];

        foreach (array_values($fields) as $index => $field) {
            $rows[] = [
                'index'        => $index,
                'key'          => (string) ($field['key'] ?? ''),
                'label'        => (string) ($field['label'] ?? ''),
                'type'         => (string) ($field['type'] ?? FormFieldType::TEXT->value),
                'required'     => true === ($field['required'] ?? false) || '1' === ($field['required'] ?? '') ? 'checked' : '',
                'max'          => (string) ($field['max'] ?? ''),
                'options'      => (string) ($field['options'] ?? ''),
                'show_results' => true === ($field['show_results'] ?? false) || '1' === ($field['show_results'] ?? '') ? 'checked' : '',
                'error'        => $fieldErrors['fields.' . $index . '.key']
                    ?? $fieldErrors['fields.' . $index . '.label']
                    ?? $fieldErrors['fields.' . $index . '.type']
                    ?? $fieldErrors['fields.' . $index . '.options']
                    ?? $fieldErrors['fields.' . $index . '.max']
                    ?? $fieldErrors['fields.' . $index . '.show_results']
                    ?? '',
            ];
        }

        return $rows;
    }

    private static function script(): string
    {
        $js = InlineJs::file('Modules/Forms/Interfaces/HTTP/assets/scripts/forms-editor.js');

        return '<script nonce="__CSP_NONCE_PLACEHOLDER__">' . $js . '</script>';
    }
}
