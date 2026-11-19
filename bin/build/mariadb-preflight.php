<?php

declare(strict_types = 1);

/*
 * mariadb-preflight.php — refuse a MariaDB test run over another application's
 * schema.
 *
 * `make test-mariadb` migrates the module databases under DB_DATABASE_PREFIX.
 * The migration is `CREATE TABLE IF NOT EXISTS`, so pointing the prefix at a
 * namespace that already holds unrelated tables would add ours beside them
 * instead of failing, and the suite would then assert against a polluted
 * target. This check runs BEFORE the migration: for every `<prefix><module>`
 * database that already exists, it asks whether each of its tables is one the
 * tree's migrators create, and exits 1 when one is not.
 *
 * The expected schema is not a second hand-kept inventory. The migrators are
 * run against throwaway SQLite files — the default driver, no server, no
 * network — and the tables they create are the answer, so a new module or a new
 * table is covered the day it is added.
 *
 * A fully migrated target passes: re-running the gate is normal, and every
 * table it finds is one the migrators create.
 *
 * Exit codes: 0 when the target is empty or holds only this tree's tables,
 * 1 when it holds a foreign table, 2 when the check itself cannot run.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/src/init.php';

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\Support\DatabaseMigrator;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Database\Infrastructure\Persistence\Driver;
use Modules\Database\Infrastructure\Persistence\DsnBuilder;
use Modules\Database\Infrastructure\Persistence\SqlDialect;

function preflightFail(string $message, int $code = 1): never
{
    fwrite(STDERR, 'mariadb-preflight: ' . $message . PHP_EOL);
    exit($code);
}

$dsn = getenv('DB_DSN');
$prefix = getenv('DB_DATABASE_PREFIX');
$prefix = false === $prefix ? 'nativa_' : $prefix;

if (false === $dsn || '' === $dsn) {
    preflightFail('DB_DSN is required (the MariaDB server DSN)', 2);
}

$modules = (new DatabaseMigrator())->databases();

$silent = new class () implements CliOutput {
    public function writeln(string $message): void
    {
    }
};

/*
 * Run every migrator against SQLite in a temp directory. The per-module
 * DB_<NAME> override points each connection at its own throwaway file, so the
 * tree's real storage/data/ files are never touched.
 */
$work = sys_get_temp_dir() . '/nativa-mariadb-preflight-' . getmypid();

if (!@mkdir($work, 0o775, true) && !is_dir($work)) {
    preflightFail('cannot create ' . $work, 2);
}

foreach ($modules as $module) {
    putenv('DB_' . strtoupper($module) . '=' . $work . '/' . $module . '.db');
}

putenv('DB_DRIVER=' . Driver::Sqlite->value);
DatabaseConnectionManager::resetInstance();

$expected = [];

try {
    $manager = DatabaseConnectionManager::instance();
    (new DatabaseMigrator())->run($silent);
    $dialect = SqlDialect::forDriver(Driver::Sqlite);

    foreach ($modules as $module) {
        $expected[$module] = $dialect->tableNames($manager->connection($module));
    }
} catch (\Throwable $e) {
    preflightFail('the migrators could not build a reference schema: ' . $e->getMessage(), 2);
} finally {
    DatabaseConnectionManager::resetInstance();

    foreach (glob($work . '/*.db') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($work);
}

/*
 * Now the target: a server connection with no database selected. `dbname` in
 * the operator's DSN is dropped because MariaDB module databases are derived
 * from the prefix, exactly as the connection manager does it.
 */
try {
    $server = new \PDO(
        DsnBuilder::build(Driver::MariaDb, DsnBuilder::withCharset(DsnBuilder::withoutDatabase($dsn))),
        null,
        null,
        [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ],
    );
} catch (\Throwable $e) {
    preflightFail('cannot reach the MariaDB server: ' . $e->getMessage(), 2);
}

$databases = $server
    ->query('SELECT schema_name AS name FROM information_schema.schemata ORDER BY schema_name')
    ->fetchAll(\PDO::FETCH_COLUMN);

$present = [];

foreach ($databases as $database) {
    if (is_string($database) && '' !== $prefix && str_starts_with($database, $prefix)) {
        $present[substr($database, strlen($prefix))] = $database;
    }
}

$foreign = [];

foreach ($present as $module => $database) {
    if (!isset($expected[$module])) {
        continue; // Not a database this tree migrates; the run never touches it.
    }

    $statement = $server->prepare(
        "SELECT table_name AS name FROM information_schema.tables
         WHERE table_schema = ? AND table_type = 'BASE TABLE'
         ORDER BY table_name",
    );
    $statement->execute([$database]);
    $table = $statement->fetchAll(\PDO::FETCH_COLUMN);

    foreach ($table as $name) {
        if (!in_array($name, $expected[$module], true)) {
            $foreign[] = $database . '.' . $name;
        }
    }
}

if ([] !== $foreign) {
    fwrite(STDERR, sprintf(
        "mariadb-preflight: the target already holds %d table(s) this tree's migrators do not create:\n"
        . "  - %s\n"
        . "The prefix %s is not a dedicated test namespace. Point MARIADB_PREFIX at a fresh one,\n"
        . "or drop those tables (and their rows) before running the suite.\n",
        count($foreign),
        implode(PHP_EOL . '  - ', $foreign),
        $prefix,
    ));
    exit(1);
}

printf(
    "preflight: %d migrated database(s) under %s hold only this tree's tables\n",
    count($present),
    $prefix,
);
