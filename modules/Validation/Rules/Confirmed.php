<?php

declare(strict_types = 1);

namespace Modules\Validation\Rules;

use Modules\Validation\Contracts\RuleInterface;
use Modules\Validation\Validation\DataPath;

/**
 * Value must equal `<field>_confirmation` — resolved against the CONCRETE
 * field path, so `items.0.password` confirms against
 * `items.0.password_confirmation`. (Marko routes this through a
 * WildcardAware interface; the relative resolution says the same thing with
 * one less contract.) Lenient on empty — see Email.
 */
final class Confirmed implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }

        return $value === DataPath::get($data, self::confirmationPath($field));
    }

    public function message(string $field, mixed $value): string
    {
        return "The {$field} confirmation does not match.";
    }

    public static function confirmationPath(string $field): string
    {
        $position = strrpos($field, '.');

        if (false === $position) {
            return $field . '_confirmation';
        }

        return substr($field, 0, $position + 1) . substr($field, $position + 1) . '_confirmation';
    }
}
