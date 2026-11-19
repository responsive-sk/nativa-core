<?php

declare(strict_types = 1);

namespace Shell;

use Attribute;

/**
 * Marks a class as the site's landing page — the attribute
 * `HomepageSurfaceRegistry` discovers, the same way `#[AsShellContextProvider]`
 * is discovered for the shell.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsHomepageSurface
{
    public function __construct(
        /** Lower sorts first; ties keep discovery order. */
        public int $priority = 0,
    ) {
    }
}
