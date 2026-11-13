<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Domain\Model;

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
 */
final readonly class ActionSet
{
    /** @var list<string> */
    public array $actions;

    /**
     * @param list<string> $actions
     */
    public function __construct(array $actions = ['list', 'read'])
    {
        if ([] === $actions) {
            throw new \InvalidArgumentException('A resource needs at least one action; the default is list and read.');
        }

        $normalised = [];

        foreach ($actions as $action) {
            if (!is_string($action) || !self::known($action)) {
                throw new \InvalidArgumentException(sprintf(
                    '"%s" is not an action; use list, read, create, update or delete.',
                    is_string($action) ? $action : get_debug_type($action),
                ));
            }

            if (in_array($action, $normalised, true)) {
                throw new \InvalidArgumentException(sprintf('"%s" is listed twice in one action set.', $action));
            }

            $normalised[] = $action;
        }

        $this->actions = $normalised;
    }

    public function allows(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }

    public function writes(): bool
    {
        foreach ($this->actions as $action) {
            if (in_array($action, ['create', 'update', 'delete'], true)) {
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
        return $this->actions;
    }

    private static function known(string $action): bool
    {
        return match ($action) {
            'list', 'read', 'create', 'update', 'delete' => true,
            default => false,
        };
    }
}
