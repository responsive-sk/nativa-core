<?php

declare(strict_types = 1);

namespace Shell;

use Attribute;

/**
 * Marks a class as a shell context provider — the attribute
 * `ShellContextProviderRegistry` discovers, the same way `#[AsMiddleware]` is
 * discovered for the pipeline.
 *
 * A module that owns part of the shell (its site name, its theme, its locales,
 * its menu) implements `ShellContextProvider` and marks the class with this.
 * Nothing else registers it: a provider that has to be listed somewhere is a
 * provider a removed module leaves behind in that list.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsShellContextProvider
{
    public function __construct(
        /** Lower sorts first; ties keep discovery order. */
        public int $priority = 0,
    ) {
    }
}
