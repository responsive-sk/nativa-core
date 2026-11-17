<?php

declare(strict_types = 1);

namespace Modules\RestPanel\Domain\Model;

/**
 * FieldSpec — one column of a remote resource, as the panel renders it.
 *
 * `name` is the API's key (dotted paths are allowed and resolved by the
 * mapper); `label` is what a human reads. `readOnly` is a UI hint, not a
 * security decision: whether an endpoint accepts a write is decided by the
 * resource's `actions` and the transport, never by a form control.
 *
 * Three presentations read this one definition, and they ask it rather than
 * deciding for themselves:
 *
 * - `visibleIn(FieldContext::List)` — a table cell (`ItemsIndexAction`);
 * - `visibleIn(FieldContext::Form)` / `editableIn(FieldContext::Form)` — the
 *   form control (`FieldControl`, `ItemFormAction`);
 * - `visibleIn(FieldContext::Detail)` — a `<dt>`/`<dd>` pair (`ItemDetailAction`).
 *
 * `inList` and `readOnly` stay as the two original keys and as inputs:
 * `inList: false` means "hidden in list" and `readOnly: true` means "not
 * editable anywhere", so a spec written before contexts existed answers the
 * same questions it always did. `hiddenIn` and `readOnlyIn` are the per-context
 * overrides, and both are empty by default — an empty override list is what
 * keeps an old document byte-identical when it is saved again.
 */
final readonly class FieldSpec
{
    /**
     * @param list<FieldContext> $hiddenIn   contexts this field is not shown in
     * @param list<FieldContext> $readOnlyIn contexts this field cannot be edited in
     */
    public function __construct(
        public string $name,
        public string $label,
        public FieldType $type = FieldType::Text,
        public bool $required = false,
        public bool $readOnly = false,
        public bool $inList = true,
        public array $hiddenIn = [],
        public array $readOnlyIn = [],
    ) {
    }

    public function visibleIn(FieldContext $context): bool
    {
        if (FieldContext::List === $context && !$this->inList) {
            return false;
        }

        return !in_array($context, $this->hiddenIn, true);
    }

    /**
     * Editable means "renders a control a person may change". Only the form
     * submits, so a list cell and a detail row are not editable regardless of
     * what the spec says — answering otherwise would hand a renderer a decision
     * it cannot act on.
     */
    public function editableIn(FieldContext $context): bool
    {
        if (FieldContext::Form !== $context) {
            return false;
        }

        if ($this->readOnly) {
            return false;
        }

        return !in_array($context, $this->readOnlyIn, true);
    }

    /**
     * The two context keys are written only when they carry something the two
     * original keys cannot say. A document saved here and read back by a panel
     * that predates contexts has to stay identical, and it does: an empty
     * override list is absent, not `[]`.
     *
     * @return array{
     *     name: string,
     *     label: string,
     *     type: string,
     *     required: bool,
     *     read_only: bool,
     *     in_list: bool,
     *     hidden_in?: list<string>,
     *     read_only_in?: list<string>
     * }
     */
    public function toArray(): array
    {
        $document = [
            'name'      => $this->name,
            'label'     => $this->label,
            'type'      => $this->type->value,
            'required'  => $this->required,
            'read_only' => $this->readOnly,
            'in_list'   => $this->inList,
        ];

        if ([] !== $this->hiddenIn) {
            $document['hidden_in'] = FieldContext::tokens($this->hiddenIn);
        }

        if ([] !== $this->readOnlyIn) {
            $document['read_only_in'] = FieldContext::tokens($this->readOnlyIn);
        }

        return $document;
    }
}
