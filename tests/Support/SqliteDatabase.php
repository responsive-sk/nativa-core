<?php

declare(strict_types = 1);

namespace Tests\Support;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;

/**
 * SqliteDatabase — a test that seeds its own file states its engine.
 *
 * Most module tests migrate a temporary SQLite database and then reach it
 * through `DB_<NAME>`. That only works when the process engine is SQLite, and
 * inheriting whatever `DB_DRIVER` the run was started with made the suite pass
 * or fail by test order: under PostgreSQL the manager read the temp path as a
 * DSN, and a test that cleared one variable left the next test with a DSN in
 * SQLite mode.
 *
 * `pinSqliteDatabase()` fixes both directions. It snapshots every `DB_*`
 * variable, removes the ambient targets that are DSNs (`host=...;dbname=...`)
 * because SQLite cannot read one as a path, and sets `DB_DRIVER=sqlite`.
 * `restoreDatabaseEnvironment()` puts the ambient values back, removing
 * variables the test invented. A test that never pinned is never restored, so
 * a skipped setUp cannot unset the run's configuration.
 */
trait SqliteDatabase
{
    /** @var array<string, string> */
    private array $ambientDatabaseEnv = [];

    private bool $sqlitePinned = false;

    protected function pinSqliteDatabase(): void
    {
        if ($this->sqlitePinned) {
            return;
        }

        foreach (getenv() as $key => $value) {
            if (!str_starts_with($key, 'DB_')) {
                continue;
            }

            // `DB_DRIVER` is snapshotted too: restore has to return the run's
            // engine, not only its module targets, or the first pinned test
            // leaves the whole process on SQLite.
            $this->ambientDatabaseEnv[$key] = $value;

            if ('DB_DRIVER' === $key) {
                continue;
            }

            // A PostgreSQL DSN is not a SQLite path. Leaving one in place made
            // the manager open a file named `host=/run/...;dbname=...`.
            if (str_contains($value, '=')) {
                putenv($key);
            }
        }

        putenv('DB_DRIVER=sqlite');
        $this->sqlitePinned = true;

        DatabaseConnectionManager::resetInstance();
    }

    protected function restoreDatabaseEnvironment(): void
    {
        if ([] === $this->ambientDatabaseEnv) {
            return;
        }

        foreach (getenv() as $key => $value) {
            if (str_starts_with($key, 'DB_') && !array_key_exists($key, $this->ambientDatabaseEnv)) {
                putenv($key);
            }
        }

        foreach ($this->ambientDatabaseEnv as $key => $value) {
            putenv($key . '=' . $value);
        }

        DatabaseConnectionManager::resetInstance();
    }
}
