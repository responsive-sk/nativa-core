<?php

declare(strict_types = 1);

namespace Modules\Validation\Contracts;

use Modules\Validation\Validation\ValidationErrors;
use Modules\Validation\Validation\ValidationException;

/**
 * Input validator — string DSL (`'required|email|max:255'`), rule objects,
 * wildcards (`'photos.*' => 'image'`), custom per-field messages.
 */
interface ValidatorInterface
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string|RuleInterface|list<string|RuleInterface>> $rules
     * @param array<string, string> $messages custom messages keyed `field.rule`
     */
    public function validate(array $data, array $rules, array $messages = []): ValidationErrors;

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|RuleInterface|list<string|RuleInterface>> $rules
     * @param array<string, string> $messages
     *
     * @throws ValidationException
     */
    public function validateOrFail(array $data, array $rules, array $messages = []): void;

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|RuleInterface|list<string|RuleInterface>> $rules
     * @param array<string, string> $messages
     */
    public function passes(array $data, array $rules, array $messages = []): bool;

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|RuleInterface|list<string|RuleInterface>> $rules
     * @param array<string, string> $messages
     */
    public function fails(array $data, array $rules, array $messages = []): bool;
}
