<?php

declare(strict_types = 1);

namespace Modules\Forms\Domain\Model;

use Modules\Forms\Domain\Entities\FormField;

/**
 * FormSchema — the field grammar, in one place, on both sides of storage.
 *
 * A definition arrives from two directions: the admin editor (strings, one
 * row per field, options separated by newlines) and the database (a JSON
 * array). Both are normalised here and both are checked against the same
 * table, so an editor cannot write a schema the renderer would then refuse,
 * and a stored schema that no longer parses is reported rather than rendered
 * as a broken box.
 *
 * Invalid input produces a schema that `isValid()` rejects and `errors()`
 * explains. There is no exception: a bad definition is data an editor typed,
 * not a programming error, and the caller needs the messages to put beside
 * the rows.
 */
final readonly class FormSchema
{
    public const int MAX_FIELDS = 20;

    public const int MAX_OPTIONS = 10;

    public const int MAX_OPTION_LENGTH = 80;

    public const int MAX_LABEL_LENGTH = 120;

    public const int MAX_LENGTH_CEILING = 5000;

    public const int DEFAULT_MAX_LENGTH = 500;

    /**
     * Keys the public endpoint and the CSRF stage already own. A definition
     * that declared one would overwrite a control rather than add a field.
     *
     * @var list<string>
     */
    public const array RESERVED_KEYS = ['form_return', 'form_website', '_token'];

    /**
     * @param list<FormField>        $fields
     * @param array<string, string>  $errors keyed `fields.<index>.<part>`
     */
    private function __construct(
        private array $fields,
        private array $errors = [],
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Read a stored schema. A schema that cannot be parsed is invalid, and
     * every caller (block, renderer, admin list) treats it as nothing to
     * render.
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return new self([], ['schema' => 'The stored field list cannot be parsed.']);
        }

        $rows = [];

        foreach ($decoded as $row) {
            if (is_array($row)) {
                $rows[] = self::rowFromArray($row);
            }
        }

        return self::fromRows($rows);
    }

    /**
     * Normalise the admin editor's POST rows.
     *
     * A row with every control empty is dropped: the editor always renders one
     * blank row, and refusing a form because the editor offered a spare would
     * be a trap. Every other row is checked and the errors are keyed by the
     * row they belong to.
     *
     * @param array<mixed> $rows
     */
    public static function fromRows(array $rows): self
    {
        $errors = [];
        $fields = [];
        $seen = [];

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $rowErrors['fields.' . $index] = 'A field row must be an object.';

                continue;
            }

            if (self::isBlankRow($row)) {
                continue;
            }

            $field = self::normaliseRow($row, $index, $errors);

            if (null === $field) {
                continue;
            }

            if (isset($seen[$field->key])) {
                $errors['fields.' . $index . '.key'] = 'The key "' . $field->key . '" is used twice.';

                continue;
            }

            $seen[$field->key] = true;
            $fields[] = $field;
        }

        if ([] === $fields && [] === $errors) {
            $errors['fields'] = 'A form needs at least one field.';
        }

        if (\count($fields) > self::MAX_FIELDS) {
            $errors['fields'] = 'A form may declare at most ' . self::MAX_FIELDS . ' fields.';
        }

        return new self($fields, $errors);
    }

    /**
     * @return list<FormField>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The messages in the order the rows appear, for `AdminLayout::messages()`.
     *
     * @return list<string>
     */
    public function errorList(): array
    {
        return array_values($this->errors);
    }

    public function field(string $key): ?FormField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    public function toJson(): string
    {
        $rows = [];

        foreach ($this->fields as $field) {
            $rows[] = $field->toArray();
        }

        return (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        $rows = [];

        foreach ($this->fields as $field) {
            $rows[] = $field->toArray();
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function rowFromArray(array $row): array
    {
        return [
            'key'          => is_string($row['key'] ?? null) ? $row['key'] : '',
            'label'        => is_string($row['label'] ?? null) ? $row['label'] : '',
            'type'         => FormFieldType::fromToken($row['type'] ?? null)->value,
            'required'     => true === ($row['required'] ?? false) ? '1' : '',
            'max'          => is_int($row['max'] ?? null) ? (string) $row['max'] : '',
            'options'      => is_array($row['options'] ?? null) ? implode("\n", array_filter($row['options'], 'is_string')) : '',
            'show_results' => true === ($row['show_results'] ?? false) ? '1' : '',
        ];
    }

    /**
     * @param array<mixed>         $row
     * @param array<string, string> $errors
     */
    private static function normaliseRow(array $row, int $index, array &$errors): ?FormField
    {
        // Errors are tracked per row: a later row must not be dropped because
        // an earlier one was refused.
        $rowErrors = [];

        $string = static function (mixed $value): string {
            if (is_string($value)) {
                return trim($value);
            }

            return is_int($value) ? (string) $value : '';
        };

        $key = strtolower($string($row['key'] ?? ''));
        $label = $string($row['label'] ?? '');
        $typeToken = strtolower($string($row['type'] ?? ''));
        $type = FormFieldType::tryFrom($typeToken);

        if ('' === $key) {
            $rowErrors['fields.' . $index . '.key'] = 'Field ' . ($index + 1) . ' needs a key.';
        } elseif (1 !== preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $key)) {
            $rowErrors['fields.' . $index . '.key'] = 'Field "' . $key . '" must be lowercase letters, digits, `_` or `-` (max 40).';
        } elseif (\in_array($key, self::RESERVED_KEYS, true)) {
            $rowErrors['fields.' . $index . '.key'] = 'The key "' . $key . '" is reserved by the form itself.';
        }

        if ('' === $label) {
            $rowErrors['fields.' . $index . '.label'] = 'Field ' . ($index + 1) . ' needs a label.';
        } elseif (\strlen($label) > self::MAX_LABEL_LENGTH) {
            $rowErrors['fields.' . $index . '.label'] = 'The label for "' . $key . '" is longer than ' . self::MAX_LABEL_LENGTH . ' characters.';
        }

        if (null === $type) {
            $rowErrors['fields.' . $index . '.type'] = 'The type "' . $typeToken . '" is not one this module renders.';
            $type = FormFieldType::TEXT;
        }

        $options = [];

        foreach (preg_split('/\R/', $string($row['options'] ?? '')) ?: [] as $option) {
            $option = trim($option);

            if ('' !== $option) {
                $options[] = $option;
            }
        }

        $showResults = '1' === $string($row['show_results'] ?? '');

        if ($type->isChoice()) {
            if ([] === $options) {
                $rowErrors['fields.' . $index . '.options'] = 'The choice field "' . $key . '" needs at least one option.';
            } elseif (\count($options) > self::MAX_OPTIONS) {
                $rowErrors['fields.' . $index . '.options'] = 'The field "' . $key . '" may declare at most ' . self::MAX_OPTIONS . ' options.';
            }

            foreach ($options as $option) {
                if (\strlen($option) > self::MAX_OPTION_LENGTH) {
                    $rowErrors['fields.' . $index . '.options'] = 'Options may be at most ' . self::MAX_OPTION_LENGTH . ' characters.';

                    break;
                }
            }
        } elseif ([] !== $options) {
            $rowErrors['fields.' . $index . '.options'] = 'Only a select or radio field may declare options.';
        }

        if (FormFieldType::CHECKBOX === $type && $showResults) {
            $rowErrors['fields.' . $index . '.show_results'] = 'A consent box has no results to show.';
        }

        if ($showResults && !$type->isChoice()) {
            $rowErrors['fields.' . $index . '.show_results'] = 'Results are only tallied for select and radio fields.';
        }

        $max = self::DEFAULT_MAX_LENGTH;

        if ($type->isTextLike()) {
            $rawMax = $string($row['max'] ?? '');

            if ('' === $rawMax) {
                $max = FormFieldType::TEXTAREA === $type ? 2000 : self::DEFAULT_MAX_LENGTH;
            } else {
                $parsed = (int) $rawMax;

                if ($parsed < 1 || $parsed > self::MAX_LENGTH_CEILING) {
                    $rowErrors['fields.' . $index . '.max'] = 'The maximum length must be between 1 and ' . self::MAX_LENGTH_CEILING . '.';
                } else {
                    $max = $parsed;
                }
            }
        }

        foreach ($rowErrors as $part => $message) {
            $errors[$part] = $message;
        }

        if ([] !== $rowErrors) {
            return null;
        }

        return new FormField(
            key: $key,
            label: $label,
            type: $type,
            required: '1' === $string($row['required'] ?? ''),
            max: $max,
            options: $type->isChoice() ? array_values($options) : [],
            showResults: $showResults && $type->isChoice(),
        );
    }

    /**
     * @param array<mixed> $row
     */
    private static function isBlankRow(array $row): bool
    {
        foreach (['key', 'label', 'options', 'max'] as $part) {
            if (isset($row[$part]) && '' !== trim(self::scalar($row[$part]))) {
                return false;
            }
        }

        return true;
    }

    private static function scalar(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_int($value) ? (string) $value : '';
    }
}
