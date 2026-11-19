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
 * db:export — write every module database as a portable NDJSON snapshot.
 *
 * The command only owns the argv and the summary; DatabaseTransfer owns the
 * format, so a test can exercise the same reader and writer without a
 * subprocess.
 */
final readonly class ExportCommand
{
    /**
     * @param list<string>|null $databases test seam; null discovers the installed migrators
     */
    public function __construct(
        private ?DatabaseTransfer $transfer = null,
        private ?array $databases = null,
    ) {
    }

    /**
     * @param list<string> $argv index 0 is `db:export`
     */
    public function run(array $argv, CliOutput $output): int
    {
        try {
            $parsed = CommandOptions::parse(array_slice($argv, 1), [], []);
        } catch (RuntimeException $exception) {
            $output->writeln('db:export: ' . $exception->getMessage());

            return 1;
        }

        if (1 !== count($parsed['positional'])) {
            $output->writeln('db:export: expected exactly one export directory.');

            return 1;
        }

        $databases = $this->databases ?? (new DatabaseMigrator())->databases();

        if ([] === $databases) {
            $output->writeln('db:export: no module databases were found; install a module first.');

            return 1;
        }

        try {
            $result = ($this->transfer ?? new DatabaseTransfer(DatabaseConnectionManager::instance()))
                ->export($databases, $parsed['positional'][0]);

            $output->writeln(sprintf(
                'db:export: %d database(s), %d table(s), %d row(s).',
                $result['databases'],
                $result['tables'],
                $result['rows'],
            ));
            $output->writeln('Manifest: ' . $result['directory'] . DIRECTORY_SEPARATOR . 'manifest.json');

            return 0;
        } catch (Throwable $exception) {
            $output->writeln('db:export: ' . $exception->getMessage());

            return 1;
        }
    }
}
