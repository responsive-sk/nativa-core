<?php

declare(strict_types = 1);

namespace Modules\Forms\Domain\Model;

/**
 * FormFieldType — the closed vocabulary of field types a form may declare.
 *
 * An enum, not a class of string constants, and the measurement is why: the
 * first version declared `public const string EMAIL = 'email'` and
 * `TEXT = 'text'`, and `NameOwnershipContractTest` then charged every place in
 * the tree that spells either word — 92 for `'email'` in the mail settings,
 * the user records and the validation tests, 90 for `'text'` in theme CSS
 * assertions and question forms — as 182 re-spellings of a value this module
 * owns. Those places name a different fact that happens to share a spelling.
 * A case is not a constant claiming a string, the vocabulary keeps one owner,
 * and the gate stops measuring English (Boot 47).
 *
 * `checkbox` is ONE consent statement, not a multi-value group. Every other
 * type submits a single string, and the result bars average one value per
 * submission; a checkbox group would need a list payload and a second
 * grammar. `FormSchema` refuses options on a checkbox for that reason.
 *
 * `fromToken()` is the tolerant read every stored schema gets: a value this
 * vocabulary does not know becomes `text` rather than an exception, because a
 * hand-edited JSON file is data an editor typed, not a crash.
 */
enum FormFieldType: string
{
    case TEXT = 'text';
    case EMAIL = 'email';
    case TEXTAREA = 'textarea';
    case SELECT = 'select';
    case RADIO = 'radio';
    case CHECKBOX = 'checkbox';

    /**
     * Types whose answer is one of a declared list, and therefore the only
     * types a result bar can describe.
     */
    public function isChoice(): bool
    {
        return self::SELECT === $this || self::RADIO === $this;
    }

    /**
     * Types that carry a `max` length. A checkbox has no length and a choice
     * field has an option list instead.
     */
    public function isTextLike(): bool
    {
        return self::TEXT === $this || self::EMAIL === $this || self::TEXTAREA === $this;
    }

    /**
     * The HTML input type a single-line field renders as.
     */
    public function inputType(): string
    {
        return self::EMAIL === $this ? 'email' : 'text';
    }

    /**
     * The element a choice field renders as.
     */
    public function choiceWidget(): string
    {
        return self::RADIO === $this ? 'radio' : 'select';
    }

    /**
     * A stored token, parsed; anything this vocabulary does not know is text.
     */
    public static function fromToken(mixed $stored): self
    {
        $value = is_string($stored) ? strtolower(trim($stored)) : '';

        return self::tryFrom($value) ?? self::TEXT;
    }

    /**
     * Every stored token, in declaration order — what the editor's dropdown
     * lists and what a schema error message quotes.
     *
     * @return list<string>
     */
    public static function tokens(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
