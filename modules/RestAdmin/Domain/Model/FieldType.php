<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Domain\Model;

/**
 * FieldType — the closed vocabulary a resource field may use.
 *
 * An enum rather than eight constants: these are ordinary English words
 * (`text`, `date`), and owning them as class constants would charge every
 * unrelated place in the tree that spells the same word. The cases own their
 * tokens without claiming the words.
 *
 * Adding a case is a review question for the form renderer — a type with no
 * control renders as a text input, which is honest but not silently correct.
 */
enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Checkbox = 'checkbox';
    case Select = 'select';
    case Date = 'date';
    case Json = 'json';
    case Relation = 'relation';
}
