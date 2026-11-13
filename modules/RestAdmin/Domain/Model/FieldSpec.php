<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Domain\Model;

/**
 * FieldSpec — one column of a remote resource, as the panel renders it.
 *
 * `name` is the API's key (dotted paths are allowed and resolved by the
 * mapper); `label` is what a human reads. `readOnly` is a UI hint, not a
 * security decision: whether an endpoint accepts a write is decided by the
 * resource's `actions` and the transport, never by a form control.
 */
final readonly class FieldSpec
{
    public function __construct(
        public string $name,
        public string $label,
        public FieldType $type = FieldType::Text,
        public bool $required = false,
        public bool $readOnly = false,
        public bool $inList = true,
    ) {
    }

    /**
     * @return array{name: string, label: string, type: string, required: bool, read_only: bool, in_list: bool}
     */
    public function toArray(): array
    {
        return [
            'name'      => $this->name,
            'label'     => $this->label,
            'type'      => $this->type->value,
            'required'  => $this->required,
            'read_only' => $this->readOnly,
            'in_list'   => $this->inList,
        ];
    }
}
