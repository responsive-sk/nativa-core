<?php

declare(strict_types = 1);

namespace Modules\RestPanel\Domain\Model;

/**
 * FieldContext — the three places one stored field is rendered.
 *
 * Before this, a field answered two questions from two booleans and three
 * renderers asked them separately: the list asked `inList`, the form asked
 * `readOnly`, and the detail page asked nothing at all. That works until the
 * first field that should be visible in a table but not on a detail page, or
 * editable nowhere but shown everywhere, and then the answer is "add another
 * boolean".
 *
 * The vocabulary is deliberately small. A context is where a field appears, not
 * what it is: `form` is the only context that submits, and that is a property of
 * the context rather than of the field, which is why `editableIn()` refuses
 * `read_only` for every context that is not `form` — a list cell and a detail
 * row have no control to disable.
 */
enum FieldContext: string
{
    case List = 'list';
    case Form = 'form';
    case Detail = 'detail';

    /**
     * Tokens in declaration order, for a spec document that stores contexts as
     * strings and for a message that has to name them without spelling them a
     * second time.
     *
     * @param list<self> $contexts
     *
     * @return list<string>
     */
    public static function tokens(array $contexts): array
    {
        $own = [];

        foreach ($contexts as $context) {
            $own[$context->value] = true;
        }

        return array_keys($own);
    }

    /**
     * The tokens this vocabulary knows, for a validator message that would
     * otherwise hard-code the list beside the enum that owns it.
     */
    public static function known(): string
    {
        return implode(', ', array_map(
            static fn (self $context): string => $context->value,
            self::cases(),
        ));
    }

    /**
     * Strict: a document being validated may not name a context this build does
     * not have. The exception carries the offending token, because a validator
     * that answered "must be a list" for `["table"]` would send the operator to
     * the wrong key.
     *
     * @throws \InvalidArgumentException
     *
     * @return list<self>
     */
    public static function parseList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('must be a list of contexts: ' . self::known() . '.');
        }

        $contexts = [];

        foreach ($value as $token) {
            $context = is_string($token) ? self::tryFrom($token) : null;

            if (null === $context) {
                throw new \InvalidArgumentException(sprintf(
                    '"%s" is not a context; use %s.',
                    is_string($token) ? $token : get_debug_type($token),
                    self::known(),
                ));
            }

            if (in_array($context, $contexts, true)) {
                throw new \InvalidArgumentException(sprintf('"%s" is listed twice.', $context->value));
            }

            $contexts[] = $context;
        }

        return $contexts;
    }

    /**
     * Lenient: a value already stored answers rather than throws. A token this
     * build no longer knows is dropped, because a field that vanishes from one
     * page is a smaller failure than a resource that cannot be opened at all.
     *
     * @return list<self>
     */
    public static function fromStored(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (!is_array($decoded)) {
            return [];
        }

        $contexts = [];

        foreach ($decoded as $token) {
            $context = is_string($token) ? self::tryFrom($token) : null;

            if (null !== $context && !in_array($context, $contexts, true)) {
                $contexts[] = $context;
            }
        }

        return $contexts;
    }
}
