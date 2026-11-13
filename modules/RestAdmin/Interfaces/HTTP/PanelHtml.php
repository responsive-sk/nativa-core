<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\HTTP;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;

/**
 * PanelHtml — the panel's small HTML vocabulary.
 *
 * Every helper escapes its text. The one exception is a cell shaped
 * `['html' => '…']`, which exists for the action links a table needs and for
 * nothing else: a spec value never becomes markup by accident, which is the
 * property `PanelEscapeTest` measures.
 *
 * The markup uses the `m-` class names `AdminLayout` already styles; a missing
 * class is a cosmetic regression, not a broken page, so the panel does not
 * grow its own stylesheet for four tables.
 */
final class PanelHtml
{
    /**
     * @param list<string>                                       $headers
     * @param list<list<string|array{html: string}>>             $rows
     */
    public static function table(
        array $headers,
        array $rows,
        string $emptyTitle,
        string $emptyDescription = '',
        string $emptyHref = '',
        string $emptyLabel = '',
    ): string {
        if ([] === $rows) {
            return AdminLayout::emptyState($emptyTitle, $emptyDescription, $emptyHref, $emptyLabel);
        }

        $html = '<div class="m-table-wrap"><table class="m-table"><thead><tr>';

        foreach ($headers as $header) {
            $html .= '<th>' . AdminLayout::e($header) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';

            foreach ($row as $cell) {
                $html .= '<td>' . (is_array($cell) ? $cell['html'] : AdminLayout::e($cell)) . '</td>';
            }

            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    public static function badge(string $label, bool $on = true): string
    {
        $class = $on ? 'm-badge m-badge--ok' : 'm-badge';

        return '<span class="' . $class . '">' . AdminLayout::e($label) . '</span>';
    }

    /**
     * @param list<string> $errors
     */
    public static function errors(array $errors): string
    {
        if ([] === $errors) {
            return '';
        }

        $html = '<div class="m-alert m-alert--err" role="alert"><ul>';

        foreach ($errors as $error) {
            $html .= '<li>' . AdminLayout::e($error) . '</li>';
        }

        return $html . '</ul></div>';
    }

    /**
     * Field errors sit beside the control they belong to; the general message
     * sits above the form, because the API did not say which field it meant.
     *
     * @param array<string, list<string>> $fieldErrors
     */
    public static function fieldErrors(array $fieldErrors): string
    {
        $general = $fieldErrors['_general'] ?? [];

        return self::errors($general);
    }

    /**
     * @param array<string, list<string>> $fieldErrors
     */
    public static function fieldError(array $fieldErrors, string $name): string
    {
        $messages = $fieldErrors[$name] ?? [];

        if ([] === $messages) {
            return '';
        }

        return '<p class="m-field__error">' . AdminLayout::e(implode(' ', $messages)) . '</p>';
    }

    public static function input(
        string $name,
        string $label,
        string $value = '',
        bool $required = false,
        string $type = 'text',
        string $help = '',
    ): string {
        $id = self::id($name);

        return '<div class="m-field">'
            . '<label class="m-field__label" for="' . $id . '">' . AdminLayout::e($label)
            . ($required ? ' <span aria-hidden="true">*</span>' : '') . '</label>'
            . '<input class="m-input" id="' . $id . '" type="' . AdminLayout::e($type) . '" name="'
            . AdminLayout::e($name) . '" value="' . AdminLayout::e($value) . '"'
            . ($required ? ' required' : '') . '>'
            . ('' === $help ? '' : '<p class="m-field__help">' . AdminLayout::e($help) . '</p>')
            . '</div>';
    }

    public static function textarea(
        string $name,
        string $label,
        string $value = '',
        bool $required = false,
        string $help = '',
        int $rows = 6,
    ): string {
        $id = self::id($name);

        return '<div class="m-field">'
            . '<label class="m-field__label" for="' . $id . '">' . AdminLayout::e($label)
            . ($required ? ' <span aria-hidden="true">*</span>' : '') . '</label>'
            . '<textarea class="m-textarea" id="' . $id . '" name="' . AdminLayout::e($name)
            . '" rows="' . $rows . '"' . ($required ? ' required' : '') . '>'
            . AdminLayout::e($value) . '</textarea>'
            . ('' === $help ? '' : '<p class="m-field__help">' . AdminLayout::e($help) . '</p>')
            . '</div>';
    }

    /**
     * @param array<string, string> $options value => label
     */
    public static function select(string $name, string $label, array $options, string $selected): string
    {
        $id = self::id($name);
        $html = '<div class="m-field"><label class="m-field__label" for="' . $id . '">'
            . AdminLayout::e($label) . '</label><select class="m-select" id="' . $id . '" name="'
            . AdminLayout::e($name) . '">';

        foreach ($options as $value => $optionLabel) {
            $html .= '<option value="' . AdminLayout::e($value) . '"'
                . ($value === $selected ? ' selected' : '') . '>'
                . AdminLayout::e($optionLabel) . '</option>';
        }

        return $html . '</select></div>';
    }

    public static function format(mixed $value): string
    {
        if (null === $value) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        return (string) $value;
    }

    public static function csrfForm(string $action, string $content, string $submit = 'Save'): string
    {
        return '<form method="post" action="' . AdminLayout::e($action) . '">'
            . AdminLayout::csrfField()
            . $content
            . '<button class="m-btn m-btn--primary" type="submit">' . AdminLayout::e($submit) . '</button>'
            . '</form>';
    }

    private static function id(string $name): string
    {
        return 'f-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
    }
}
