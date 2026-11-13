<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\HTTP;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\RestAdmin\Domain\Model\FieldSpec;
use Modules\RestAdmin\Domain\Model\FieldType;

/**
 * FieldControl — the item form's control for one stored field.
 *
 * One factory rather than eight classes: the eight types differ in one tag and
 * one formatting rule, and a class each would be eight names for a `match`.
 *
 * `select` renders a text input because `FieldSpec` carries no option list in
 * v1; inventing an empty `<select>` would be a control that can only lose
 * data. `relation` is a text input for the foreign id for the same reason.
 * A `readOnly` field renders disabled, but that is a UI hint only — the write
 * decision belongs to `ActionSet`.
 */
final class FieldControl
{
    public static function render(FieldSpec $field, mixed $value, string $error = ''): string
    {
        $id = 'f-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $field->name);
        $name = AdminLayout::e($field->name);
        $label = '<label class="m-field__label" for="' . $id . '">'
            . AdminLayout::e($field->label) . ($field->required ? ' <span aria-hidden="true">*</span>' : '')
            . '</label>';

        $control = match ($field->type) {
            FieldType::Textarea => '<textarea class="m-textarea" id="' . $id . '" name="' . $name
                . '" rows="5"' . ($field->readOnly ? ' readonly' : '') . '>'
                . AdminLayout::e(is_string($value) ? $value : '') . '</textarea>',
            FieldType::Number => '<input class="m-input" id="' . $id . '" type="number" step="any" name="'
                . $name . '" value="' . AdminLayout::e(is_scalar($value) ? (string) $value : '') . '"'
                . ($field->readOnly ? ' readonly' : '') . '>',
            FieldType::Checkbox => '<input type="hidden" name="' . $name . '" value="0">'
                . '<input class="m-checkbox" id="' . $id . '" type="checkbox" name="' . $name
                . '" value="1"' . (self::truthy($value) ? ' checked' : '')
                . ($field->readOnly ? ' disabled' : '') . '>',
            FieldType::Date => '<input class="m-input" id="' . $id . '" type="date" name="' . $name
                . '" value="' . AdminLayout::e(is_string($value) ? $value : '')
                . '"' . ($field->readOnly ? ' readonly' : '') . '>',
            FieldType::Json => '<textarea class="m-textarea" id="' . $id . '" name="' . $name
                . '" rows="6"' . ($field->readOnly ? ' readonly' : '') . '>'
                . AdminLayout::e(self::json($value)) . '</textarea>',
            FieldType::Select, FieldType::Relation => '<input class="m-input" id="' . $id . '" type="text" name="'
                . $name . '" value="' . AdminLayout::e(is_scalar($value) ? (string) $value : '') . '"'
                . ($field->readOnly ? ' readonly' : '') . '>',
            default => '<input class="m-input" id="' . $id . '" type="text" name="' . $name
                . '" value="' . AdminLayout::e(is_string($value) ? $value : '')
                . '"' . ($field->readOnly ? ' readonly' : '') . '>',
        };

        return '<div class="m-field">' . $label . $control
            . ('' === $error ? '' : '<p class="m-field__error">' . AdminLayout::e($error) . '</p>')
            . '</div>';
    }

    public static function truthy(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value || 'on' === $value || 'true' === $value;
    }

    private static function json(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
