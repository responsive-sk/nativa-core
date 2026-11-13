<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Domain\Model;

/**
 * ResourceSpec — one collection of a service, and what the panel may do with it.
 *
 * `path` lists the collection and `itemPath` addresses one member, both
 * relative to the service's base URL (`/posts`, `/posts/{id}`). `idField` is
 * the API key the item path's `{id}` is filled from.
 *
 * `actions` is an allowlist, and the default is read-only: a resource does not
 * gain a write because the remote API happens to accept one (plan, D6).
 *
 * `pagination` names a strategy the mapper knows. Relations are the resource's
 * own outgoing edges; the validator refuses a target this service does not
 * define and a cycle, because v1 resolves nested relations recursively.
 */
final readonly class ResourceSpec
{
    /**
     * @param list<FieldSpec>    $fields
     * @param list<RelationSpec> $relations
     */
    public function __construct(
        public string $serviceId,
        public string $name,
        public string $path,
        public string $itemPath,
        public string $idField = 'id',
        public array $fields = [],
        public ActionSet $actions = new ActionSet(),
        public PaginationSpec $pagination = new PaginationSpec(),
        public array $relations = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'       => $this->name,
            'path'       => $this->path,
            'item_path'  => $this->itemPath,
            'id_field'   => $this->idField,
            'actions'    => $this->actions->toArray(),
            'pagination' => $this->pagination->strategy,
            'fields'     => array_map(
                static fn (FieldSpec $field): array => $field->toArray(),
                $this->fields,
            ),
            'relations'  => array_map(
                static fn (RelationSpec $relation): array => $relation->toArray(),
                $this->relations,
            ),
        ];
    }
}
