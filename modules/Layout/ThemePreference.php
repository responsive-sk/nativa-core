<?php

declare(strict_types = 1);

namespace Modules\Layout;

/**
 * ThemePreference — the stored-theme vote as a pure decision.
 *
 * The rule lived in two places saying the same thing: `ThemeResolver`
 * (whitelist-or-registry, else the kernel theme) and the Settings shell
 * provider (answer the stored name only when this tree can render it).
 * Both now read it from here, so the vote cannot drift between the writer
 * (the admin `<select>`) and the reader (the resolver).
 *
 * Pure on purpose: no settings service, no registry, no database — the
 * caller supplies the renderable set, which is what makes this unit-testable
 * without a migrated tree.
 */
final class ThemePreference
{
    /**
     * Resolve a stored vote against the renderable set, else the fallback.
     *
     * @param list<string> $available themes this tree can render right now
     */
    public static function resolve(?string $stored, array $available, string $fallback): string
    {
        return is_string($stored) && self::isResolvable($stored, $available) ? $stored : $fallback;
    }

    /**
     * Whether the stored vote names something renderable — the provider half
     * of the rule (answer it) versus the resolver half (render it or fall back).
     *
     * @param list<string> $available themes this tree can render right now
     */
    public static function isResolvable(?string $stored, array $available): bool
    {
        return is_string($stored) && '' !== $stored && in_array($stored, $available, true);
    }
}
