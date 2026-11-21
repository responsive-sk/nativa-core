<?php

declare(strict_types = 1);

namespace Modules\RestApi\Domain\Model;

/**
 * ActionSet — what the panel may do with a resource, as an allowlist.
 *
 * The default is read-only on purpose (plan D6): a remote API that happens to
 * accept a POST does not grant the panel a create. An operator opts in by
 * listing `create`, `update` or `delete`.
 *
 * Order is preserved, duplicates are refused, and an empty set is refused: a
 * resource nobody may touch is not a resource, it is a mistake in the form.
 * `writes()` is the one question the MCP layer asks to decide whether a tool
 * needs `confirm: true`.
 *
 * The set stores `ResourceAction` cases, not strings, but it still ACCEPTS
 * strings: `actions` is persisted as tokens in a spec document, so a stored
 * spec — and a tampered POST — arrives here as text. Normalisation through
 * `ResourceAction::tryFrom()` is the only door, which is why an unknown token
 * is refused here and nowhere else. `toArray()` gives the tokens back in the
 * operator's order, so a spec written before the vocabulary was typed reloads
 * and re-saves identically.
 */
final readonly class ActionSet
{
    /** @var list<ResourceAction> */
    public array $actions;

    /**
     * @param list<ResourceAction|string> $actions
     */
    public function __construct(array $actions = [ResourceAction::List, ResourceAction::Read])
    {
        if ([] === $actions) {
            throw new \InvalidArgumentException('A resource needs at least one action; the default is list and read.');
        }

        $normalised = [];

        foreach ($actions as $action) {
            $known = $action instanceof ResourceAction
                ? $action
                : (is_string($action) ? ResourceAction::tryFrom($action) : null);

            if (!$known instanceof ResourceAction) {
                throw new \InvalidArgumentException(sprintf(
                    '"%s" is not an action; use %s.',
                    is_string($action) ? $action : get_debug_type($action),
                    ResourceAction::tokens(),
                ));
            }

            if (in_array($known, $normalised, true)) {
                throw new \InvalidArgumentException(sprintf('"%s" is listed twice in one action set.', $known->value));
            }

            $normalised[] = $known;
        }

        $this->actions = $normalised;
    }

    /**
     * Asked with a token, because every caller is a route or a template holding
     * one. A token nobody knows is not an action the panel may take, so it is
     * refused by answering false rather than by throwing at a read.
     */
    public function allows(string $action): bool
    {
        $known = ResourceAction::tryFrom($action);

        return null !== $known && in_array($known, $this->actions, true);
    }

    public function writes(): bool
    {
        foreach ($this->actions as $action) {
            if ($action->isWrite()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (ResourceAction $action): string => $action->value,
            $this->actions,
        );
    }
}
