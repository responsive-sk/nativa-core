<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Application\Services;

use Modules\Clickhouse\Application\ClickHouseClient;
use Modules\Clickhouse\Domain\SqliteAttachment;
use Modules\Core\Infrastructure\Paths\AppPaths;
use RuntimeException;

/**
 * SqliteEngineBridge — which kernel files become which ClickHouse databases,
 * and what the server currently says about them.
 *
 * The name rule — `storage/data/<name>.db` plus every `DB_<NAME>` override
 * whose value ends in `.db` and whose file exists — is **re-derived** here
 * rather than borrowed from `HealthServices::databaseNames()`. A module may
 * not import another module (KernelCouplingTest, `module:show` closure), and a
 * kernel file may not name a module, so the only shared owner available is
 * `AppPaths`, which already resolves the override. The copy is deliberate; a
 * divergence between the two would be a bug in one of them, and this one is
 * the bridge's own answer to "which files are a database".
 *
 * Refusals, each one sentence and each naming the value it rejected:
 *
 * - a prefix or a database name that does not match `^[A-Za-z][A-Za-z0-9_]*$`;
 * - a path that is not absolute;
 * - a path containing `'`, `\` or a NUL byte — the three things that would
 *   change what the `SQLite('…')` literal means;
 * - a file that does not exist when the operator named it explicitly.
 *
 * A missing file that nobody named is skipped with a WARN: `storage/data/`
 * legitimately holds `.db` files for installed modules, and a prune between
 * the glob and the stat is not a reason to fail an attach for every other
 * database.
 *
 * Symlinks are resolved locally with `realpath()` before the path is written
 * into the DDL, so a symlinked `storage/` attaches as the real file. Whether
 * the *server* resolves a symlink the same way is open question 2 in the
 * proposal; the boot part records it as not measured rather than as assumed.
 */
final class SqliteEngineBridge
{
    private const string NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*$/';

    /**
     * One attachment per local database, sorted by database name.
     *
     * @param string      $prefix the `CLICKHOUSE_DATABASE_PREFIX` value
     * @param string|null $only   a database name (`nativa_blog`) or a bare file name (`blog`);
     *                            null plans every database
     *
     * @return list<SqliteAttachment>
     *
     * @throws RuntimeException on a refused prefix, name or path
     */
    public function plan(string $prefix, ?string $only = null): array
    {
        self::assertName('prefix', $prefix);

        $attachments = [];
        $matched = false;

        foreach ($this->databaseNames() as $name) {
            $databaseName = $prefix . $name;
            self::assertName('database name', $databaseName);

            if (null !== $only && $only !== $name && $only !== $databaseName) {
                continue;
            }

            $matched = true;
            $path = AppPaths::instance()->database($name);

            if (!is_file($path)) {
                if (null !== $only) {
                    throw new RuntimeException(sprintf(
                        'SQLite file for %s is missing: %s',
                        $databaseName,
                        $path,
                    ));
                }

                error_log(sprintf('[CLICKHOUSE] skipped %s: the file does not exist', basename($path)));
                continue;
            }

            // The file has to be reachable on the ClickHouse host under this
            // exact spelling; a symlinked storage/ is resolved here so the
            // path printed is the one a bind mount most likely carries.
            $resolved = realpath($path);
            $absolute = false === $resolved ? $path : $resolved;

            self::assertAttachablePath($databaseName, $absolute);

            $attachments[] = new SqliteAttachment($databaseName, $absolute, basename($absolute));
        }

        if (null !== $only && !$matched) {
            throw new RuntimeException(sprintf('No SQLite database matches --db=%s.', $only));
        }

        usort($attachments, static fn (SqliteAttachment $a, SqliteAttachment $b): int => $a->databaseName <=> $b->databaseName);

        return $attachments;
    }

    /**
     * Send the DDL the plan describes.
     *
     * Without `$force` the statements are `CREATE DATABASE IF NOT EXISTS`, so
     * running attach twice changes nothing. With `$force` each database is
     * dropped first — the only way ClickHouse can re-point an engine — and the
     * command says out loud that this detaches the view and leaves the SQLite
     * file untouched.
     *
     * @param list<SqliteAttachment> $attachments
     *
     * @throws \Modules\Clickhouse\Domain\ClickHouseError when the server refuses a statement
     */
    public function apply(ClickHouseClient $client, array $attachments, bool $force): void
    {
        foreach ($attachments as $attachment) {
            if ($force) {
                $detach = $attachment->detachStatement();
                error_log('[CLICKHOUSE] DDL ' . $detach);
                $client->emit($detach);
            }

            foreach ($attachment->statements() as $statement) {
                error_log('[CLICKHOUSE] DDL ' . $statement);
                $client->emit($statement);
            }

            error_log(sprintf(
                '[CLICKHOUSE] attach %s <- %s (%d tables)',
                $attachment->databaseName,
                $attachment->path,
                count($client->tables($attachment->databaseName)),
            ));
        }
    }

    /**
     * The differences between the plan and what the server reports.
     *
     * Read back from the server, never from the plan (proposal, Decision 4):
     * `SHOW CREATE DATABASE` names the path the engine actually opened, so a
     * `storage/` that moved on one side is visible instead of being assumed
     * healthy. One line per difference, and no line at all when the two agree.
     *
     * @param list<SqliteAttachment> $attachments
     *
     * @return list<string>
     */
    public function drift(ClickHouseClient $client, array $attachments): array
    {
        $attached = $client->databases();
        $lines = [];

        foreach ($attachments as $attachment) {
            if (!in_array($attachment->databaseName, $attached, true)) {
                $lines[] = sprintf(
                    '%s: not attached (local file %s)',
                    $attachment->databaseName,
                    $attachment->path,
                );
                continue;
            }

            $statement = $client->createDatabase($attachment->databaseName);
            $serverPath = self::sqlitePathFrom($statement);

            if (null === $serverPath) {
                $lines[] = sprintf(
                    '%s: the server reports no SQLite path in SHOW CREATE DATABASE',
                    $attachment->databaseName,
                );
                continue;
            }

            if ($serverPath !== $attachment->path) {
                $lines[] = sprintf(
                    '%s: the server reads %s, the local file is %s',
                    $attachment->databaseName,
                    $serverPath,
                    $attachment->path,
                );
            }
        }

        return $lines;
    }

    /**
     * The path out of a `SHOW CREATE DATABASE` statement.
     */
    public static function sqlitePathFrom(string $statement): ?string
    {
        if (1 !== preg_match('/ENGINE\s*=\s*SQLite\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*\)/i', $statement, $matches)) {
            return null;
        }

        return str_replace(["\\'", '\\\\'], ["'", '\\'], $matches[1]);
    }

    /**
     * The database names the kernel actually has, sorted.
     *
     * @return list<string>
     */
    private function databaseNames(): array
    {
        /** @var array<string, true> $names */
        $names = [];

        $files = glob(AppPaths::instance()->data('*.db'));

        if (false !== $files) {
            foreach ($files as $file) {
                $names[basename($file, '.db')] = true;
            }
        }

        $environment = getenv();

        if (is_array($environment)) {
            foreach ($environment as $key => $value) {
                if (!str_starts_with($key, 'DB_') || 'DB_' === $key) {
                    continue;
                }

                // The suffix test is the HealthServices rule, re-derived: a
                // `DB_HOST=db.internal` from an unrelated tool's environment
                // would otherwise become a database named `host`.
                if (is_string($value) && str_ends_with($value, '.db')) {
                    $names[strtolower(substr($key, 3))] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * @throws RuntimeException when the prefix or the composed name fails the rule
     */
    private static function assertName(string $what, string $name): void
    {
        if (1 === preg_match(self::NAME_PATTERN, $name)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'ClickHouse %s %s must match %s.',
            $what,
            var_export($name, true),
            self::NAME_PATTERN,
        ));
    }

    /**
     * Public so the three path refusals are testable directly: a relative path
     * cannot reach plan(), because AppPaths always resolves against the
     * project root, but the guard still has to be stated and held.
     *
     * @throws RuntimeException when the path would mean something else inside the literal
     */
    public static function assertAttachablePath(string $databaseName, string $path): void
    {
        if (!str_starts_with($path, '/')) {
            throw new RuntimeException(sprintf(
                'The SQLite path for %s is not absolute: %s',
                $databaseName,
                $path,
            ));
        }

        if (str_contains($path, "'") || str_contains($path, '\\') || str_contains($path, "\0")) {
            throw new RuntimeException(sprintf(
                'The SQLite path for %s contains a quote, a backslash or a NUL byte: %s',
                $databaseName,
                var_export($path, true),
            ));
        }
    }
}
