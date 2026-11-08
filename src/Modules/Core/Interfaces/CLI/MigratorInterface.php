<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\CLI;

/**
 * MigratorInterface — explicit contract for module database migrators.
 *
 * Migrator classes are annotated #[AsMigrator(module:, table:)] AND
 * implement this interface, so the runner can enforce the contract and
 * phpstan can type the constructor + run() dispatch without ignores.
 *
 * `run()` takes `CliOutput` and NOTHING ELSE, and that is the whole point of
 * the interface existing. It used to be `CliOutput | OutputInterface` — a
 * union naming `symfony/console`, which is `require-dev` only. It worked,
 * because PHP silently skips a class it cannot resolve when matching a union
 * type, and nobody noticed for as long as nobody wrote it down. That is a
 * terrible foundation for the one claim this tree makes about itself: it has no
 * Composer dependencies at runtime, and the `admin-smoke` and `boot` jobs run
 * with no `vendor/` to keep that honest. A kernel contract naming a dev
 * package is a dependency on a language behaviour rather than on nothing.
 *
 * Nothing is lost: `OutputInterface` exposes `writeln(string)`, which is
 * `CliOutput`'s only method, so any Console output already satisfied the
 * contract structurally.
 */
interface MigratorInterface
{
    /**
     * Every migrator receives the PDO connection of its module database.
     */
    public function __construct(\PDO $pdo);

    /**
     * Create/update the module schema using idempotent
     * CREATE TABLE IF NOT EXISTS statements.
     */
    public function run(CliOutput $output): void;
}
