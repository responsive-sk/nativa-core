<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\CLI\Support;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;

/**
 * DatabaseMigrator — runs every module migrator against its own database.
 *
 * Discovery convention: migrator classes live under
 * src/Modules/<Module>/Infrastructure/Persistence/Migrations/, are annotated
 * #[AsMigrator(module: 'users', table: 'users')] and implement
 * MigratorInterface. Each migrator receives its module PDO connection and
 * builds idempotent CREATE TABLE IF NOT EXISTS statements, so re-running
 * is safe.
 */
final readonly class DatabaseMigrator
{
    private AppPaths $paths;

    private DatabaseConnectionManager $dbManager;

    public function __construct()
    {
        $this->paths = AppPaths::instance();
        $this->dbManager = DatabaseConnectionManager::instance();
    }

    /**
     * Run all discovered module migrators.
     *
     * @return list<string> module names migrated (empty when none found)
     */
    public function run(CliOutput $output): array
    {
        $migrated = [];

        foreach ($this->discoverMigrators() as $module => $class) {
            try {
                $this->writeInfo($output, sprintf('Migrating %s.db (%s)...', $module, $class));

                $pdo = $this->dbManager->connection($module);
                // $class is narrowed to class-string<MigratorInterface> in discoverMigrators().
                $instance = new $class($pdo);
                $instance->run($output);

                $migrated[] = $module;
            } catch (\Throwable $e) {
                $this->writeError($output, sprintf('✗ %s migration failed: %s', $module, $e->getMessage()));

                throw $e;
            }
        }

        if ([] === $migrated) {
            $this->writeInfo($output, 'No migrators found (add #[AsMigrator] classes under src/Modules/*/Infrastructure/Persistence/Migrations/).');
        } else {
            $this->writeSuccess($output, '✓ Migrated: ' . implode(', ', $migrated));
        }

        return $migrated;
    }

    /**
     * Discover #[AsMigrator] classes by scanning module migrations dirs.
     *
     * @return array<string, class-string<MigratorInterface>> module name → migrator class
     */
    private function discoverMigrators(): array
    {
        $found = [];

        $files = glob($this->paths->src('Modules/*/Infrastructure/Persistence/Migrations/*DatabaseMigrator.php')) ?: [];

        foreach ($files as $file) {
            $relative = substr($file, strlen($this->paths->src()) + 1);
            $class = str_replace(['/', '.php'], ['\\', ''], $relative);

            if (!class_exists($class)) {
                // Autoloader could not resolve the class (namespace mismatch) —
                // surface it instead of silently ignoring the file.
                throw new \RuntimeException(sprintf('Migrator file %s exists but class %s cannot be autoloaded.', $file, $class));
            }

            $reflection = new \ReflectionClass($class);
            $attribute = $reflection->getAttributes(AsMigrator::class)[0] ?? null;

            if (null === $attribute) {
                continue; // *DatabaseMigrator.php but not annotated — not a migrator.
            }

            if (!$reflection->implementsInterface(MigratorInterface::class)) {
                throw new \RuntimeException(sprintf('%s is annotated #[AsMigrator] but must implement %s.', $class, MigratorInterface::class));
            }

            assert(
                is_subclass_of($class, MigratorInterface::class),
                sprintf('%s is annotated #[AsMigrator] but does not implement %s.', $class, MigratorInterface::class),
            );

            $migrator = $attribute->newInstance();

            if (isset($found[$migrator->module])) {
                throw new \RuntimeException(sprintf(
                    'Duplicate #[AsMigrator] module "%s" (%s conflicts with %s).',
                    $migrator->module,
                    $class,
                    $found[$migrator->module],
                ));
            }

            $found[$migrator->module] = $class;
        }

        ksort($found);

        return $found;
    }

    private function writeInfo(CliOutput $output, string $message): void
    {
        $output->writeln('<info>' . $message . '</info>');
    }

    private function writeSuccess(CliOutput $output, string $message): void
    {
        $output->writeln('<info>' . $message . '</info>');
    }

    private function writeError(CliOutput $output, string $message): void
    {
        $output->writeln('<error>' . $message . '</error>');
    }
}
