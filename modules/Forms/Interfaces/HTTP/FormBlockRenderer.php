<?php

declare(strict_types = 1);

namespace Modules\Forms\Interfaces\HTTP;

use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\Middleware\CsrfMiddleware;
use Modules\Forms\Application\Services\FormResultsService;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Entities\FormField;
use Modules\Forms\Domain\Model\FormFieldType;

/**
 * FormBlockRenderer — the markup contract `form.css` already styles.
 *
 * Every class is built from the ACTIVE THEME'S PREFIX, exactly like
 * `QuestionBlockRenderer`: a form on a magazine page is `mag-form`, on
 * jednotka `je-form`, never `st-`. That is why `form.css` exists once per
 * theme, generated from the starter copy, and why this renderer emits no
 * unprefixed class at all.
 *
 * The action is the form's own public endpoint, locale-prefixed. The return
 * path travels as a hidden field carrying the embedding request path, which is
 * what lets a failed submission re-render the page the reader was on.
 *
 * Every value is escaped with `FrontendLayout::e()`. The one inline style is a
 * bar width computed from stored counts, never from visitor input.
 */
final readonly class FormBlockRenderer
{
    /**
     * @param array{errors?: list<string>, invalid?: list<string>, old?: array<string, string>, sent?: bool} $state
     */
    public static function render(Form $form, string $prefix, string $locale, array $state = [], string $returnPath = ''): string
    {
        $errors = self::strings($state['errors'] ?? []);
        $invalid = self::strings($state['invalid'] ?? []);
        $old = self::fields($state['old'] ?? []);
        $sent = true === ($state['sent'] ?? false);

        return '<section id="form-' . FrontendLayout::e($form->slug) . '" class="' . $prefix . '-form">'
            . self::header($form, $prefix)
            . ($sent ? self::thanks($form, $prefix) : '')
            . self::errors($errors, $prefix)
            . self::form($form, $prefix, $locale, $old, $invalid, $returnPath)
            . ($sent ? self::results($form, $prefix) : '')
            . '</section>';
    }

    private static function header(Form $form, string $prefix): string
    {
        $html = '<h2 class="' . $prefix . '-form__title">' . FrontendLayout::e($form->title) . '</h2>';

        if ('' !== $form->intro) {
            $html .= '<p class="' . $prefix . '-form__intro">' . FrontendLayout::e($form->intro) . '</p>';
        }

        return $html;
    }

    private static function thanks(Form $form, string $prefix): string
    {
        $message = '' !== $form->successMessage ? $form->successMessage : 'Thank you, your message was sent.';

        return '<p class="' . $prefix . '-form__thanks" role="status">' . FrontendLayout::e($message) . '</p>';
    }

    /**
     * The validation summary, announced as a region.
     *
     * A failed submission re-renders the page the reader was on, so nothing
     * moves their focus to the message above the form: without a live region
     * the only signal is that the page came back. `role="alert"` is that
     * signal, and the wrapper is why the messages are one region instead of
     * one announcement per field.
     *
     * @param list<string> $errors
     */
    private static function errors(array $errors, string $prefix): string
    {
        if ([] === $errors) {
            return '';
        }

        $html = '<div class="' . $prefix . '-form__errors" role="alert">';

        foreach ($errors as $error) {
            $html .= '<p class="' . $prefix . '-form__error">' . FrontendLayout::e($error) . '</p>';
        }

        return $html . '</div>';
    }

    /**
     * @param array<string, string> $old
     * @param list<string>          $invalid
     */
    private static function form(Form $form, string $prefix, string $locale, array $old, array $invalid, string $returnPath): string
    {
        $action = ContentServices::mapper()->urlPrefix($locale) . '/forms/' . rawurlencode($form->slug);
        $return = self::returnPath($returnPath, $prefix);

        $html = '<form class="' . $prefix . '-form__form" method="post" action="' . FrontendLayout::e($action) . '">'
            . CsrfMiddleware::field()
            . $return
            . '<!-- honeypot: leave blank, a bot that fills every field is silently discarded -->'
            . '<div class="' . $prefix . '-form__hp" hidden>'
            . '<label class="' . $prefix . '-form__label" for="' . $prefix . '-form-website">Website</label>'
            . '<input class="' . $prefix . '-form__input" id="' . $prefix . '-form-website" type="text"'
            . ' name="form_website" value="" autocomplete="off" tabindex="-1">'
            . '</div>';

        foreach ($form->schema->fields() as $field) {
            $html .= self::field($field, $prefix, $form->slug, $old[$field->key] ?? '', \in_array($field->key, $invalid, true));
        }

        return $html . '<button class="' . $prefix . '-form__submit" type="submit">'
            . FrontendLayout::e('Send') . '</button></form>';
    }

    /**
     * The hidden return path, omitted when the renderer was handed no path
     * (CLI, tests). Without it, the endpoint answers 400 on an invalid
     * submission instead of guessing a page.
     */
    private static function returnPath(string $path, string $prefix): string
    {
        if ('' === $path) {
            return '';
        }

        return '<input type="hidden" name="form_return" value="' . FrontendLayout::e($path) . '">';
    }

    private static function field(FormField $field, string $prefix, string $slug, string $value, bool $invalid): string
    {
        $id = $prefix . '-form-' . $slug . '-' . $field->key;
        $invalidAttr = $invalid ? ' aria-invalid="true"' : '';
        $required = $field->required ? ' required' : '';
        $requiredMark = $field->required
            ? ' <span class="' . $prefix . '-form__req" aria-hidden="true">*</span>'
            : '';

        $label = '<label class="' . $prefix . '-form__label" for="' . FrontendLayout::e($id) . '">'
            . FrontendLayout::e($field->label) . $requiredMark . '</label>';

        if (FormFieldType::CHECKBOX === $field->type) {
            $checked = '1' === $value ? ' checked' : '';

            return '<div class="' . $prefix . '-form__field">'
                . '<label class="' . $prefix . '-form__option" for="' . FrontendLayout::e($id) . '">'
                . '<input type="checkbox" id="' . FrontendLayout::e($id) . '" name="' . FrontendLayout::e($field->key) . '"'
                . ' value="1"' . $checked . $required . $invalidAttr . '> '
                . FrontendLayout::e($field->label) . $requiredMark
                . '</label></div>';
        }

        // A select is ONE control with ONE accessible name, so it takes the
        // ordinary label. A fieldset is what a group of controls needs, and
        // that is the radio list only: shipping a fieldset around a select put
        // the browser's own border, padding and `min-inline-size` around the
        // field, which every theme then had to reset.
        if ($field->type->isChoice() && 'select' === $field->type->choiceWidget()) {
            return '<div class="' . $prefix . '-form__field">' . $label
                . self::choices($field, $prefix, $slug, $value, $invalidAttr, $required) . '</div>';
        }

        if ($field->type->isChoice()) {
            return '<fieldset class="' . $prefix . '-form__field">'
                . '<legend class="' . $prefix . '-form__label">' . FrontendLayout::e($field->label) . $requiredMark . '</legend>'
                . self::choices($field, $prefix, $slug, $value, $invalidAttr, $required)
                . '</fieldset>';
        }

        if (FormFieldType::TEXTAREA === $field->type) {
            return '<div class="' . $prefix . '-form__field">' . $label
                . '<textarea class="' . $prefix . '-form__input" id="' . FrontendLayout::e($id) . '"'
                . ' name="' . FrontendLayout::e($field->key) . '" rows="5" maxlength="' . $field->max . '"'
                . $required . $invalidAttr . '>' . FrontendLayout::e($value) . '</textarea></div>';
        }

        $type = $field->type->inputType();

        return '<div class="' . $prefix . '-form__field">' . $label
            . '<input class="' . $prefix . '-form__input" id="' . FrontendLayout::e($id) . '" type="' . $type . '"'
            . ' name="' . FrontendLayout::e($field->key) . '" value="' . FrontendLayout::e($value) . '"'
            . ' maxlength="' . $field->max . '"' . $required . $invalidAttr . '></div>';
    }

    private static function choices(
        FormField $field,
        string $prefix,
        string $slug,
        string $value,
        string $invalidAttr,
        string $required,
    ): string {
        $type = $field->type->choiceWidget();

        if ('select' === $type) {
            $html = '<select class="' . $prefix . '-form__input" id="' . FrontendLayout::e($prefix . '-form-' . $slug . '-' . $field->key) . '"'
                . ' name="' . FrontendLayout::e($field->key) . '"' . $required . $invalidAttr . '>'
                . '<option value="">' . FrontendLayout::e('Choose one') . '</option>';

            foreach ($field->options as $option) {
                $selected = $value === $option ? ' selected' : '';
                $html .= '<option value="' . FrontendLayout::e($option) . '"' . $selected . '>'
                    . FrontendLayout::e($option) . '</option>';
            }

            return $html . '</select>';
        }

        $html = '';
        $index = 0;

        foreach ($field->options as $option) {
            $id = $prefix . '-form-' . $slug . '-' . $field->key . '-' . $index;
            $checked = $value === $option ? ' checked' : '';
            ++$index;

            $html .= '<label class="' . $prefix . '-form__option" for="' . FrontendLayout::e($id) . '">'
                . '<input type="radio" id="' . FrontendLayout::e($id) . '" name="' . FrontendLayout::e($field->key) . '"'
                . ' value="' . FrontendLayout::e($option) . '"' . $checked . $required . $invalidAttr . '> '
                . FrontendLayout::e($option) . '</label>';
        }

        return $html;
    }

    private static function results(Form $form, string $prefix): string
    {
        $counts = (new FormResultsService())->counts($form);

        if ([] === $counts) {
            return '';
        }

        $html = '';

        foreach ($form->schema->fields() as $field) {
            $fieldCounts = $counts[$field->key] ?? null;

            if (null === $fieldCounts || $fieldCounts['total'] <= 0) {
                continue;
            }

            $html .= '<div class="' . $prefix . '-form__question">'
                . '<p class="' . $prefix . '-form__prompt">' . FrontendLayout::e($field->label) . '</p>';

            foreach ($field->options as $option) {
                $percent = FormResultsService::percent($fieldCounts, $option);

                $html .= '<div class="' . $prefix . '-form__result">'
                    . '<div class="' . $prefix . '-form__bar">'
                    . '<span class="' . $prefix . '-form__bar-label">' . FrontendLayout::e($option) . '</span>'
                    . '<span class="' . $prefix . '-form__bar-track">'
                    . '<span class="' . $prefix . '-form__bar-fill" style="width:' . $percent . '%"></span>'
                    . '</span>'
                    . '<span class="' . $prefix . '-form__bar-value">' . $percent . '%</span>'
                    . '</div></div>';
            }

            $html .= '</div>';
        }

        return $html;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) || is_numeric($value)) {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, string>
     */
    private static function fields(array $values): array
    {
        $fields = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $fields[$key] = (string) $value;
            }
        }

        return $fields;
    }
}
