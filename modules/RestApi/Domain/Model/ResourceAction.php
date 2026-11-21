<?php

declare(strict_types = 1);

namespace Modules\RestApi\Domain\Model;

/**
 * ResourceAction — the closed vocabulary a resource's allowlist may use.
 *
 * An enum rather than five strings matched in two methods, for the reason
 * `FieldType` beside it gives: the cases own their tokens without claiming the
 * English words. Before this, `known()` listed the five and `writes()` listed
 * three of them again, so "may this resource write?" was answered by a second
 * copy of the vocabulary. A sixth token would have had to be added in two
 * places, and nothing would have reported the place that was missed.
 *
 * Order is not the enum's business. `ActionSet` keeps the operator's order,
 * because `toArray()` writes it back into stored specs byte for byte.
 *
 * Adding a case is a review question beyond this file: a new write verb changes
 * what the MCP layer must confirm before a tool runs.
 */
enum ResourceAction: string
{
    case List = 'list';
    case Read = 'read';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    /**
     * The verbs that change something remote, in the order a spec usually lists
     * them. `ActionSet::writes()` asks this and the MCP layer asks that, so the
     * answer has one owner.
     *
     * @return list<self>
     */
    public static function writes(): array
    {
        return [self::Create, self::Update, self::Delete];
    }

    public function isWrite(): bool
    {
        return in_array($this, self::writes(), true);
    }

    /**
     * The tokens in declaration order, for a message that has to name the whole
     * vocabulary without the message spelling it a second time.
     */
    public static function tokens(): string
    {
        return implode(', ', array_map(
            static fn (self $action): string => $action->value,
            self::cases(),
        ));
    }
}
