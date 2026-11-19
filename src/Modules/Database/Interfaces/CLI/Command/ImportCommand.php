<?php

declare(strict_types = 1);

namespace Modules\Database\Interfaces\CLI\Command;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\CommandOptions;
use Modules\Core\Interfaces\CLI\Support\DatabaseMigrator;
use Modules\Database\Infrastructure\Export\DatabaseTransfer;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use RuntimeException;
use Throwable;

/**
 * db:import — build the target schema, then restore a snapshot into it.
 *
 * The migrators run first because the snapshot carries data only. A fresh
 * target has the migrator's seed rows, so replacing them needs --force; without
 * it, the command refuses before deleting anything.
 */
final readonly class ImportCommand
{
    /**
     * @param list<string>|null $databases test seam; null runs the installed migrators
     */
    public function __construct(
        private ?DatabaseTransfer $transfer = null,
        private ?array $databases = null,
    ) {
    }

    /**
     * @param list<string> $argv index 0 is `db:import`
     */
    public function run(array $argv, CliOutput $output): int
    {
        try {
            $parsed = CommandOptions::parse(array_slice($argv, 1), [], ['force']);
        } catch (RuntimeException $exception) {
            $output->writeln('db:import: ' . $exception->getMessage());

            return 1;
        }

        if (1 !== count($parsed['positional'])) {
            $output->writeln('db:import: expected exactly one snapshot directory.');

            return 1;
        }

        $replace = array_key_exists('force', $parsed['options']);

        try {
            if (null === $this->databases) {
                $databases = (new DatabaseMigrator())->run($output);
            } else {
                $databases = $this->databases;
            }

            if ([] === $databases) {
                $output->writeln('db:import: no module databases were found; install a module first.');

                return 1;
            }

            $result = ($this->transfer ?? new DatabaseTransfer(DatabaseConnectionManager::instance()))
                ->import($databases, $parsed['positional'][0], $replace);

            $output->writeln(sprintf(
                'db:import: %d database(s), %d table(s), %d row(s).',
                $result['databases'],
                $result['tables'],
                $result['rows'],
            ));

            return 0;
        } catch (Throwable $exception) {
            $output->writeln('db:import: ' . $exception->getMessage());

            return 1;
        }
    }
}
