<?php

declare(strict_types = 1);

namespace Modules\Validation\Contracts;

/**
 * A single validation rule — passes/fails one value, names its own default
 * message. Cross-field rules (Confirmed, Same, Different) read siblings
 * from the full $data, resolved through dot-notation paths.
 */
interface RuleInterface
{
    /**
     * @param array<string, mixed> $data all data being validated
     */
    public function passes(string $field, mixed $value, array $data): bool;

    public function message(string $field, mixed $value): string;
}
