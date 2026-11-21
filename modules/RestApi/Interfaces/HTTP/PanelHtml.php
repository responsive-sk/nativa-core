<?php

declare(strict_types = 1);

namespace Modules\RestApi\Interfaces\HTTP;

use Modules\Cardboard\Interfaces\HTTP\CardboardLayout;

/**
 * PanelHtml — the panel's small HTML vocabulary.
 *
 * Every helper escapes its text. The one exception is a cell shaped
 * `['html' => '…']`, which exists for the action links a table needs and for
 * nothing else: a spec value never becomes markup by accident, which is the
 * property `PanelEscapeTest` measures.
 *
 * The markup uses the `m-` class names `CardboardLayout` already styles; a missing
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
            return CardboardLayout::emptyState($emptyTitle, $emptyDescription, $emptyHref, $emptyLabel);
        }

        $html = '<div class="m-table-wrap"><table class="m-table"><thead><tr>';

        foreach ($headers as $header) {
            $html .= '<th>' . CardboardLayout::e($header) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';

            foreach ($row as $cell) {
                $html .= '<td>' . (is_array($cell) ? $cell['html'] : CardboardLayout::e($cell)) . '</td>';
            }

            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    public static function badge(string $label, bool $on = true): string
    {
        $class = $on ? 'm-badge m-badge--ok' : 'm-badge';

        return '<span class="' . $class . '">' . CardboardLayout::e($label) . '</span>';
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
            $html .= '<li>' . CardboardLayout::e($error) . '</li>';
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

        return '<p class="m-field__error">' . CardboardLayout::e(implode(' ', $messages)) . '</p>';
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
            . '<label class="m-field__label" for="' . $id . '">' . CardboardLayout::e($label)
            . ($required ? ' <span aria-hidden="true">*</span>' : '') . '</label>'
            . '<input class="m-input" id="' . $id . '" type="' . CardboardLayout::e($type) . '" name="'
            . CardboardLayout::e($name) . '" value="' . CardboardLayout::e($value) . '"'
            . ($required ? ' required' : '') . '>'
            . ('' === $help ? '' : '<p class="m-field__help">' . CardboardLayout::e($help) . '</p>')
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
            . '<label class="m-field__label" for="' . $id . '">' . CardboardLayout::e($label)
            . ($required ? ' <span aria-hidden="true">*</span>' : '') . '</label>'
            . '<textarea class="m-textarea" id="' . $id . '" name="' . CardboardLayout::e($name)
            . '" rows="' . $rows . '"' . ($required ? ' required' : '') . '>'
            . CardboardLayout::e($value) . '</textarea>'
            . ('' === $help ? '' : '<p class="m-field__help">' . CardboardLayout::e($help) . '</p>')
            . '</div>';
    }

    /**
     * @param array<string, string> $options value => label
     */
    public static function select(string $name, string $label, array $options, string $selected): string
    {
        $id = self::id($name);
        $html = '<div class="m-field"><label class="m-field__label" for="' . $id . '">'
            . CardboardLayout::e($label) . '</label><select class="m-select" id="' . $id . '" name="'
            . CardboardLayout::e($name) . '">';

        foreach ($options as $value => $optionLabel) {
            $html .= '<option value="' . CardboardLayout::e($value) . '"'
                . ($value === $selected ? ' selected' : '') . '>'
                . CardboardLayout::e($optionLabel) . '</option>';
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
        return '<form method="post" action="' . CardboardLayout::e($action) . '">'
            . CardboardLayout::csrfField()
            . $content
            . '<button class="m-btn m-btn--primary" type="submit">' . CardboardLayout::e($submit) . '</button>'
            . '</form>';
    }

    private static function id(string $name): string
    {
        return 'f-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
    }
}
