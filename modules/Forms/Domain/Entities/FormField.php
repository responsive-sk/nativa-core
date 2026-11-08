<?php

declare(strict_types = 1);

namespace Modules\Forms\Domain\Entities;

use Modules\Forms\Domain\Model\FormFieldType;

/**
 * FormField — one declared field inside a form definition.
 *
 * Immutable: the schema is read on every render and on every submission, so a
 * mutated field would be a definition that changed under a reader's POST. An
 * admin edit builds a new schema and writes it in one statement.
 *
 * The field owns its own rules (`required`, `max`, `options`) rather than
 * leaving them in the definition, because validation asks one field at a time
 * and a per-field answer is the only one that can name the offending field in
 * the error message.
 */
final readonly class FormField
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public FormFieldType $type,
        public bool $required = false,
        public int $max = 500,
        public array $options = [],
        public bool $showResults = false,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $type = FormFieldType::fromToken($row['type'] ?? null);

        $options = [];

        foreach (is_array($row['options'] ?? null) ? $row['options'] : [] as $option) {
            if (is_string($option)) {
                $options[] = $option;
            }
        }

        return new self(
            key: is_string($row['key'] ?? null) ? $row['key'] : '',
            label: is_string($row['label'] ?? null) ? $row['label'] : '',
            type: $type,
            required: true === ($row['required'] ?? false),
            max: is_int($row['max'] ?? null) ? $row['max'] : 500,
            options: $options,
            showResults: true === ($row['show_results'] ?? false),
        );
    }

    /**
     * @return array{key: string, label: string, type: string, required: bool, max: int, options?: list<string>, show_results?: bool}
     */
    public function toArray(): array
    {
        $data = [
            'key'      => $this->key,
            'label'    => $this->label,
            'type'     => $this->type->value,
            'required' => $this->required,
        ];

        if ($this->type->isTextLike()) {
            $data['max'] = $this->max;
        }

        if ($this->type->isChoice()) {
            $data['options'] = $this->options;
            $data['show_results'] = $this->showResults;
        }

        return $data;
    }

    public function isChoice(): bool
    {
        return $this->type->isChoice();
    }
}
