<?php

declare(strict_types = 1);

namespace Modules\Validation\Validation;

use Modules\Validation\Contracts\RuleInterface;
use Modules\Validation\Contracts\ValidatorInterface;
use Modules\Validation\Rules\Nullable;
use Modules\Validation\Rules\Required;

/**
 * String DSL + rule objects + wildcards (`items.*.email` errors key under
 * the concrete path, e.g. `items.0.email`).
 *
 * Skip semantics, in order: nullable-and-empty skips everything (even
 * Required); non-required-and-empty skips everything; a FAILED Required
 * stops the field's remaining rules.
 *
 * The last rule is a deliberate deviation from Marko (which runs every rule
 * and double-reports an empty `required|min:8` password): a failed Required
 * means the other rules have nothing to say. Custom messages resolve
 * `concrete-field.rule`, then `declared-key.rule` (for wildcards), then the
 * rule's own default.
 */
final class Validator implements ValidatorInterface
{
    public function __construct(private RuleParser $parser = new RuleParser())
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|RuleInterface|list<string|RuleInterface>> $rules
     * @param array<string, string> $messages
     */
    public function validate(array $data, array $rules, array $messages = []): ValidationErrors
    {
        $errors = new ValidationErrors();

        foreach ($rules as $key => $fieldRules) {
            $key = (string) $key;
            $parsed = $this->parser->parse($fieldRules);

            if (!str_contains($key, '*')) {
                $this->validateField($key, $key, $data, $parsed, $messages, $errors);

                continue;
            }

            $fields = $this->expand(explode('.', $key), $data, '');

            if ([] === $fields && $this->hasRequired($parsed)) {
                $errors->add($key, $this->message($key, $key, new Required(), null, $messages));
            }

            foreach ($fields as $field) {
                $this->validateField($field, $key, $data, $parsed, $messages, $errors);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|RuleInterface|list<string|RuleInterface>> $rules
     * @param array<string, string> $messages
     *
     * @throws ValidationException
     */
    public function validateOrFail(array $data, array $rules, array $messages = []): void
    {
        $errors = $this->validate($data, $rules, $messages);

        if ($errors->isNotEmpty()) {
            throw ValidationException::withErrors($errors);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|RuleInterface|list<string|RuleInterface>> $rules
     * @param array<string, string> $messages
     */
    public function passes(array $data, array $rules, array $messages = []): bool
    {
        return $this->validate($data, $rules, $messages)->isEmpty();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|RuleInterface|list<string|RuleInterface>> $rules
     * @param array<string, string> $messages
     */
    public function fails(array $data, array $rules, array $messages = []): bool
    {
        return !$this->passes($data, $rules, $messages);
    }

    /**
     * @param list<RuleInterface> $parsed
     * @param array<string, string> $messages
     */
    private function validateField(
        string $field,
        string $ruleKey,
        array $data,
        array $parsed,
        array $messages,
        ValidationErrors $errors,
    ): void {
        $value = DataPath::get($data, $field);

        if ($this->hasNullable($parsed) && self::isEmpty($value)) {
            return;
        }

        if (!$this->hasRequired($parsed) && self::isEmpty($value)) {
            return;
        }

        foreach ($parsed as $rule) {
            if ($rule instanceof Nullable) {
                continue;
            }

            if ($rule->passes($field, $value, $data)) {
                continue;
            }

            $errors->add($field, $this->message($field, $ruleKey, $rule, $value, $messages));

            if ($rule instanceof Required) {
                break;
            }
        }
    }

    /**
     * @param array<string, string> $messages
     */
    private function message(
        string $field,
        string $ruleKey,
        RuleInterface $rule,
        mixed $value,
        array $messages,
    ): string {
        $token = RuleParser::tokenFor($rule);

        return $messages[$field . '.' . $token]
            ?? $messages[$ruleKey . '.' . $token]
            ?? $rule->message($field, $value);
    }

    /**
     * Expand `items.*.email` into the concrete fields present in the data.
     * A `*` over an absent or empty value expands to nothing (a required
     * base the data cannot reach is reported by the caller, never passed
     * silently).
     *
     * @param list<string> $segments
     *
     * @return list<string>
     */
    private function expand(array $segments, mixed $data, string $prefix): array
    {
        if ([] === $segments) {
            return [$prefix];
        }

        $head = $segments[0];
        $rest = array_slice($segments, 1);

        if ('*' === $head) {
            if (!is_array($data) || [] === $data) {
                return [];
            }

            $fields = [];

            foreach ($data as $key => $value) {
                $fields = [...$fields, ...$this->expand($rest, $value, '' === $prefix ? (string) $key : $prefix . '.' . $key)];
            }

            return $fields;
        }

        if (!is_array($data) || !array_key_exists($head, $data)) {
            return [];
        }

        return $this->expand($rest, $data[$head], '' === $prefix ? $head : $prefix . '.' . $head);
    }

    /**
     * @param list<RuleInterface> $parsed
     */
    private function hasRequired(array $parsed): bool
    {
        foreach ($parsed as $rule) {
            if ($rule instanceof Required) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<RuleInterface> $parsed
     */
    private function hasNullable(array $parsed): bool
    {
        foreach ($parsed as $rule) {
            if ($rule instanceof Nullable) {
                return true;
            }
        }

        return false;
    }

    private static function isEmpty(mixed $value): bool
    {
        return null === $value || '' === $value || [] === $value;
    }
}
