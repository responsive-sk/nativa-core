<?php

declare(strict_types = 1);

namespace Modules\Settings\Interfaces\CLI\Command;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\UnitOfWork;
use Modules\Settings\Infrastructure\Persistence\Statement;

/**
 * bin/cms settings:profile — apply a committed deployment profile.
 *
 * `build-dist.sh` migrates the artifact and copies no developer state, so a
 * dist leaves the build with the KERNEL defaults (`theme=kernel`,
 * `site_name=Nativa PHP Core`). Measured 2026-10-17: that is why a rebuilt
 * responsive.sk artifact served the kernel theme and its copy looked deleted.
 * This command applies `config/deploy.php` right after `migrate`, so the
 * artifact boots as the site it was built for.
 *
 * Three decisions worth reading before changing anything:
 *
 * - **Every key must already exist.** The migrator's seed is the vocabulary;
 *   a profile naming anything else is REFUSED, by name, before a single row is
 *   written. The alternative — inserting the missing setting — would turn a
 *   typo (`sitename`) into a build that looks healthy and ships the defaults,
 *   which is the exact failure this command exists to end.
 * - **Only the value is written.** `group_name`, `is_public` and `value_type`
 *   belong to the seed and to `/mark` → Settings. Writing them from a profile
 *   is how `theme` (a non-public `design` row) could become public and change
 *   what the frontend reads — which is also why this does NOT go through
 *   `SettingsRepository::set()`, a method that takes those flags as arguments
 *   and would rewrite them from the command line.
 * - **Boolean rows are normalised.** `maintenance_mode` is typed `boolean` and
 *   read as such; `true`/`false` (or `on`/`off`) is stored as '1'/'0' rather
 *   than `'1'`/`''` depending on how the profile spelled it.
 *
 * Not a settings editor and not a server-side tool: it runs at build time or by
 * hand in an artifact, and the operator remains free to change every value in
 * `/mark` afterwards.
 */
final readonly class ApplyDeploymentProfileCommand
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param list<string> $argv the command's own arguments, index 0 being the
     *                           command name as `$_SERVER['argv']` has it
     *
     * @return int 0 when the profile was applied, 1 on a refusal
     */
    public function run(array $argv, CliOutput $output): int
    {
        $path = $this->profilePath(array_slice($argv, 1), $output);

        if (null === $path) {
            return 1;
        }

        $profile = $this->readProfile($path, $output);

        if (null === $profile) {
            return 1;
        }

        if ([] === $profile) {
            $output->writeln('settings:profile: ' . $path . ' returns an empty array — that is a typo, not a request.');
            $output->writeln('  A profile that sets nothing would leave the artifact on the kernel defaults.');

            return 1;
        }

        /** @var array<string, array{value: string|int|float|bool, type: string}> $rows */
        $rows = [];
        $unknown = [];
        $statement = Statement::prepare($this->pdo, 'SELECT value_type FROM settings WHERE setting_key = :key');

        foreach ($profile as $key => $value) {
            $statement->execute([':key' => $key]);
            $type = $statement->fetchColumn();

            if (false === $type) {
                $unknown[] = $key;

                continue;
            }

            $rows[$key] = ['value' => $value, 'type' => is_string($type) ? $type : 'string'];
        }

        if ([] !== $unknown) {
            $output->writeln('settings:profile: ' . $path . ' names settings this schema does not have: ' . implode(', ', $unknown));
            $output->writeln('  The vocabulary is the migrator\'s seed (SettingsDatabaseMigrator::seedDefaultSettings()); fix the profile rather than adding the row here.');

            return 1;
        }

        (new UnitOfWork($this->pdo))->transaction(function (\PDO $pdo) use ($rows): void {
            $update = Statement::prepare($pdo,
                'UPDATE settings SET setting_value = :value, updated_at = CURRENT_TIMESTAMP WHERE setting_key = :key',
            );

            foreach ($rows as $key => $row) {
                $update->execute([
                    ':value' => self::normalise($row['value'], $row['type']),
                    ':key'   => $key,
                ]);
            }
        });

        $output->writeln(sprintf('settings:profile: %d key(s) applied from %s', \count($rows), $path));

        foreach ($rows as $key => $row) {
            $output->writeln('  ' . $key . '=' . self::normalise($row['value'], $row['type']));
        }

        return 0;
    }

    /**
     * The profile's path: the first positional argument, `--file=PATH`, or the
     * tree's own `config/deploy.php`.
     *
     * @param list<string> $arguments argv without the command name
     */
    private function profilePath(array $arguments, CliOutput $output): ?string
    {
        $path = AppPaths::instance()->config('deploy.php');

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--file=')) {
                $path = substr($argument, strlen('--file='));

                continue;
            }

            if (str_starts_with($argument, '-')) {
                $output->writeln('settings:profile: unknown option "' . $argument . '" (known: --file=PATH)');

                return null;
            }

            if ('' !== $argument) {
                $path = $argument;
            }
        }

        return $path;
    }

    /**
     * Read and validate the profile.
     *
     * `require` rather than `parse_ini_file`/JSON: the file has to be able to
     * carry a reason for what it claims, and this tree keeps its configuration
     * in PHP for exactly that reason.
     *
     * @return array<string, string|int|float|bool>|null null on a refusal
     */
    private function readProfile(string $path, CliOutput $output): ?array
    {
        if (!is_file($path)) {
            $output->writeln('settings:profile: no profile at ' . $path);
            $output->writeln('  Nothing was applied; the settings keep the values they already have.');

            return null;
        }

        try {
            $profile = require $path;
        } catch (\Throwable $e) {
            $output->writeln('settings:profile: ' . $path . ' could not be read: ' . $e->getMessage());

            return null;
        }

        if (!is_array($profile)) {
            $output->writeln('settings:profile: ' . $path . ' must return an array of setting_key => value.');

            return null;
        }

        $values = [];

        foreach ($profile as $key => $value) {
            if (!is_string($key) || '' === trim($key)) {
                $output->writeln('settings:profile: ' . $path . ' has a key that is not a non-empty string.');

                return null;
            }

            if (!is_scalar($value)) {
                $output->writeln('settings:profile: the value for "' . $key . '" must be a string, number or boolean.');

                return null;
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * Spell a profile value the way the column's own type reads it.
     */
    private static function normalise(string|int|float|bool $value, string $type): string
    {
        if ('boolean' === $type) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
