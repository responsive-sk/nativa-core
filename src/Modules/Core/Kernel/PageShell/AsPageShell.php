<?php

declare(strict_types = 1);

namespace Modules\Core\Kernel\PageShell;

use Attribute;

/**
 * Marks a class as the page shell implementation — the attribute
 * `PageShellRegistry` discovers, the same way `#[AsShellContextProvider]` is
 * discovered for the shell port.
 *
 * One implementation is expected; the first discovered wins. Naming it in a
 * kernel file would put a module name back into the kernel, which is the
 * dependency this port exists to remove.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsPageShell
{
}
