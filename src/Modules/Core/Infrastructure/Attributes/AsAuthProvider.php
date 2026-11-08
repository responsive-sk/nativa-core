<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure\Attributes;

use Attribute;

/**
 * Marks a class as an auth provider — the label a reader looks for, the same
 * way `#[AsMiddleware]` is read by the pipeline and `#[AsShellContextProvider]`
 * by the shell.
 *
 * A module that owns authentication implements `AuthProvider` and marks the
 * class with this. Nothing else registers it: a provider that has to be
 * listed somewhere is a provider a removed module leaves behind in that list.
 * The kernel holds the label and the port only — reading the attribute is the
 * assembling side's job, so no scanner here has to track which modules are
 * installed. `AuthMiddleware` takes its `AuthProvider` from the constructor,
 * which is where the answer to "who is signed in" has to come from anyway.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsAuthProvider
{
    public function __construct(
        /** Lower sorts first; ties keep discovery order. */
        public int $priority = 0,
    ) {
    }
}
