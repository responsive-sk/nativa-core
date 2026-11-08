<?php

declare(strict_types = 1);

namespace Modules\Forms\Domain\Validation;

use Modules\Forms\Domain\Entities\FormField;
use Modules\Forms\Domain\Model\FormFieldType;
use Modules\Forms\Domain\Model\FormSchema;

/**
 * FormValidator — a submission against the schema it was rendered from.
 *
 * Validation is per field and returns BOTH halves of the answer: the messages
 * a reader sees, and the keys the renderer marks `aria-invalid`. They are
 * produced together because they describe the same fact, and computing them
 * apart is how a field ends up marked invalid with no message or the reverse.
 *
 * Only declared keys are considered. The caller drops undeclared input before
 * this point, and this class would ignore it anyway: a payload is stored with
 * exactly the keys the definition names.
 */
final readonly class FormValidator
{
    /**
     * @param array<string, string> $payload
     *
     * @return array{errors: list<string>, invalid: list<string>}
     */
    public function validate(FormSchema $schema, array $payload): array
    {
        $errors = [];
        $invalid = [];

        foreach ($schema->fields() as $field) {
            $value = $payload[$field->key] ?? '';
            $message = $this->message($field, $value);

            if (null === $message) {
                continue;
            }

            $errors[] = $message;
            $invalid[] = $field->key;
        }

        return ['errors' => $errors, 'invalid' => $invalid];
    }

    /**
     * The one message this field has to say about this value, or null when the
     * value is acceptable.
     */
    private function message(FormField $field, string $value): ?string
    {
        if (FormFieldType::CHECKBOX === $field->type) {
            if ($field->required && '1' !== $value) {
                return 'Please confirm "' . $field->label . '".';
            }

            return null;
        }

        if ('' === $value) {
            return $field->required ? 'Please fill in "' . $field->label . '".' : null;
        }

        if (FormFieldType::EMAIL === $field->type && false === filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return '"' . $field->label . '" must be a valid e-mail address.';
        }

        if ($field->type->isTextLike() && \strlen($value) > $field->max) {
            return '"' . $field->label . '" is longer than ' . $field->max . ' characters.';
        }

        if ($field->type->isChoice() && !\in_array($value, $field->options, true)) {
            return 'Choose one of the listed options for "' . $field->label . '".';
        }

        return null;
    }
}
