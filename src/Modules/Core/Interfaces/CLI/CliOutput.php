<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\CLI;

/**
 * CliOutput — minimal output contract for migrator run() methods.
 *
 * Symfony Console's `OutputInterface` is structurally compatible (both expose
 * `writeln(string)`), which is why this interface exists: the auto-migration
 * path — the HTTP bootstrap and `bin/cms migrate` — runs in a tree with no
 * `vendor/`, so it cannot hand a migrator a Console output. The contract is
 * `CliOutput` and ONLY `CliOutput`, so a kernel interface never names a
 * `require-dev` class in a type position; `MigratorInterface::run()` carried
 * such a union until Boot 32 and it survived only because PHP silently skips a
 * class it cannot resolve when matching one.
 */
interface CliOutput
{
    /**
     * Write a single line of output.
     */
    public function writeln(string $message): void;
}
