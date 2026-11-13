<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Domain\Model;

/**
 * ServiceSpec — one validated service and everything the panel may do with it.
 *
 * The aggregate exists so the three write paths agree on one object:
 * `SpecValidator` builds it from an operator document, `SpecRepository` stores
 * its rows, and `SpecStore` writes it as a portable snapshot. Nothing else
 * holds a half-valid specification, which is why "invalid is refused before
 * anything is written" is a property of the types rather than a rule to
 * remember.
 *
 * Resources are a list, not a map: order is the order the operator wrote, and
 * the panel renders it. Names are unique — the validator enforces it.
 */
final readonly class ServiceSpec
{
    /**
     * @param list<ResourceSpec> $resources
     */
    public function __construct(
        public ServiceConfig $service,
        public array $resources = [],
    ) {
    }

    /**
     * The document shape `SpecStore` writes and `SpecValidator` reads. Nested,
     * because a resource belongs to exactly one service and a flat list would
     * have to repeat the id on every row.
     *
     * @return array{service: array<string, mixed>, resources: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'service'   => $this->service->toArray(),
            'resources' => array_map(
                static fn (ResourceSpec $resource): array => $resource->toArray(),
                $this->resources,
            ),
        ];
    }
}
