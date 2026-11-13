<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Domain\Model;

/**
 * RelationSpec — how one resource points at another.
 *
 * v1 understands two kinds:
 *
 * - `belongs_to`: this resource carries the foreign key (`author_id`);
 * - `has_many`:   the target carries it, read-only in v1.
 *
 * `target` is a resource NAME in the same service, not a resource id: a spec
 * is written and read by humans, and the validator refuses a target the
 * service does not define before anything is stored.
 *
 * `foreignKey` is null when the API's envelope already nests the target under
 * a key — the mapper then reads the nested object and never issues a second
 * request.
 */
final readonly class RelationSpec
{
    public function __construct(
        public string $name,
        public string $kind,
        public string $target,
        public ?string $foreignKey = null,
    ) {
        if (!in_array($kind, ['belongs_to', 'has_many'], true)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a relation kind; use belongs_to or has_many.',
                $kind,
            ));
        }
    }

    /**
     * @return array{name: string, kind: string, target: string, foreign_key: ?string}
     */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'kind'        => $this->kind,
            'target'      => $this->target,
            'foreign_key' => $this->foreignKey,
        ];
    }
}
