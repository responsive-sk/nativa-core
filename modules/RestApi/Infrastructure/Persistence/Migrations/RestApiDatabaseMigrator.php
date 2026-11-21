<?php

declare(strict_types = 1);

namespace Modules\RestApi\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;
use Modules\Database\Infrastructure\Persistence\SqlDialect;

/**
 * RestApi Database Migrator — the spec store's schema.
 *
 * Four tables: a service (`services`), a collection of it (`resources`), the
 * columns the panel renders (`fields`), and how one resource points at another
 * (`relations`). The JSON spec is decomposed rather than stored as one blob so
 * a validation query can ask "which resources point at a resource that is
 * gone" without parsing every document.
 *
 * Every statement is idempotent (`CREATE TABLE IF NOT EXISTS`), so
 * `bin/cms migrate` may run it on every boot, and `module:remove RestApi`
 * leaves the data behind as the catalogue promises.
 *
 * The file was `restadmin.db` until the vocabulary round, so this is also the
 * upgrade step: `adoptLegacyDatabase()` copies the rows once and retires the
 * old file. It belongs here because `bin/cms migrate` is what a deployment runs
 * before the panel exists, and because the migrator is the one place that
 * already holds a connection to the new file.
 *
 * Secrets are not here: `services.token_env` names an environment variable,
 * never a token (plan, D5 — the kernel has no crypto port yet).
 */
#[AsMigrator(module: 'restapi', table: 'services')]
final readonly class RestApiDatabaseMigrator implements MigratorInterface
{
    private SqlDialect $dialect;

    private string $timestamp;

    public function __construct(private \PDO $pdo)
    {
        $this->dialect = SqlDialect::forPdo($pdo);
        $this->timestamp = $this->dialect->timestampType();
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating restapi.db...</info>');

        $this->createServicesTable();
        $this->createResourcesTable();
        $this->createFieldsTable();
        $this->ensureFieldContextColumns();
        $this->createRelationsTable();
        $this->createIndexes();
        $this->adoptLegacyDatabase($output);

        $output->writeln('<info>  ✓ Created tables: services, resources, fields, relations</info>');
    }

    /**
     * Adopt the rows of the pre-rename database, once.
     *
     * The rows are copied rather than the file renamed: the PDO already holds
     * an open handle on the new database, and `ATTACH` + the dialect's conflict
     * clause in foreign-key order moves them without a second connection. The copy runs
     * only while the new `services` table is empty, and the old file is retired
     * as `restapi-legacy.db` afterwards, so a second migrate is a no-op.
     *
     * The legacy file is the sibling the previous releases wrote: `restpanel.db`
     * first, then `restadmin.db`. No environment key names it.
     */
    private function adoptLegacyDatabase(CliOutput $output): void
    {
        if (!$this->dialect->isSqlite()) {
            // The pre-rename file is a SQLite database and the copy is ATTACH;
            // a PostgreSQL install has no such file to adopt.
            return;
        }

        $directory = \dirname($this->mainDatabasePath());
        $legacy = '';

        foreach (['restpanel.db', 'restadmin.db'] as $name) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $name;

            if (is_file($candidate)) {
                $legacy = $candidate;
                break;
            }
        }

        if ('' === $legacy || $this->hasRows() || $this->sameFile($legacy, $this->mainDatabasePath())) {
            return;
        }

        try {
            $this->pdo->exec('ATTACH DATABASE ' . $this->pdo->quote($legacy) . ' AS legacy');

            try {
                foreach (['services', 'resources', 'fields', 'relations'] as $table) {
                    if (!$this->tableExists('main', $table) || !$this->tableExists('legacy', $table)) {
                        continue;
                    }

                    $this->pdo->exec($this->dialect->insertOrIgnore(
                        sprintf('INSERT INTO main.%1$s SELECT * FROM legacy.%1$s', $table),
                    ));
                }
            } finally {
                $this->pdo->exec('DETACH DATABASE legacy');
            }
        } catch (\PDOException $refusal) {
            $output->writeln('<error>  ! could not adopt ' . $legacy . ': ' . $refusal->getMessage() . '</error>');

            return;
        }

        $retired = \dirname($legacy) . DIRECTORY_SEPARATOR . 'restapi-legacy.db';

        if (!@rename($legacy, $retired)) {
            $output->writeln('<error>  ! adopted the rows but could not retire ' . $legacy . '</error>');

            return;
        }

        $output->writeln('<info>  ✓ adopted the pre-rename rows; the old file is ' . $retired . '</info>');
    }

    /**
     * Does the new database already carry rows? A migrated or adopted install
     * answers yes, which is what keeps the adoption to one run.
     */
    private function hasRows(): bool
    {
        if (!$this->tableExists('main', 'services')) {
            return false;
        }

        return (int) $this->pdo->query('SELECT COUNT(*) FROM services')->fetchColumn() > 0;
    }

    /**
     * A table exists in a SQLite schema (`main` or an attached one).
     *
     * The schema name is compared against those two before it reaches SQL; it
     * is never caller-controlled.
     */
    private function tableExists(string $schema, string $table): bool
    {
        if (!in_array($schema, ['main', 'legacy'], true)) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'SELECT 1 FROM ' . $schema . ".sqlite_master WHERE type = 'table' AND name = ?",
        );

        $statement->execute([$table]);

        return false !== $statement->fetchColumn();
    }

    /**
     * The file the connection is open on, or '' for an in-memory database.
     */
    private function mainDatabasePath(): string
    {
        $statement = $this->pdo->query('PRAGMA database_list');

        if (false === $statement) {
            return '';
        }

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            /** @var array<string, mixed> $row */
            if ('main' === ($row['name'] ?? null)) {
                return (string) ($row['file'] ?? '');
            }
        }

        return '';
    }

    private function sameFile(string $left, string $right): bool
    {
        if ('' === $right) {
            return false;
        }

        $leftReal = realpath($left);
        $rightReal = realpath($right);

        return false !== $leftReal && false !== $rightReal && $leftReal === $rightReal;
    }

    private function createServicesTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS services (
                    id              VARCHAR(32) PRIMARY KEY,
                    name            VARCHAR(100) UNIQUE NOT NULL,
                    base_url        VARCHAR(2048) NOT NULL,
                    auth_header     VARCHAR(100) DEFAULT NULL,
                    token_env       VARCHAR(100) DEFAULT NULL,
                    timeout_seconds INTEGER NOT NULL DEFAULT 10,
                    headers         TEXT NOT NULL DEFAULT '{}',
                    created_at      {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    updated_at      {$this->timestamp} DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createResourcesTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS resources (
                    id              VARCHAR(32) PRIMARY KEY,
                    service_id      VARCHAR(32) NOT NULL,
                    name            VARCHAR(100) NOT NULL,
                    path            VARCHAR(2048) NOT NULL,
                    item_path       VARCHAR(2048) NOT NULL,
                    id_field        VARCHAR(100) NOT NULL DEFAULT 'id',
                    actions         TEXT NOT NULL DEFAULT '["list","read"]',
                    pagination      VARCHAR(30) NOT NULL DEFAULT 'page',
                    created_at      {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    updated_at      {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (service_id, name),
                    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
                )
            SQL);
    }

    private function createFieldsTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS fields (
                    id              VARCHAR(32) PRIMARY KEY,
                    resource_id     VARCHAR(32) NOT NULL,
                    name            VARCHAR(255) NOT NULL,
                    label           VARCHAR(255) NOT NULL,
                    type            VARCHAR(30) NOT NULL DEFAULT 'text',
                    is_required     INTEGER NOT NULL DEFAULT 0,
                    is_read_only    INTEGER NOT NULL DEFAULT 0,
                    in_list         INTEGER NOT NULL DEFAULT 1,
                    hidden_in       TEXT NOT NULL DEFAULT '[]',
                    read_only_in    TEXT NOT NULL DEFAULT '[]',
                    sort_order      INTEGER NOT NULL DEFAULT 0,
                    UNIQUE (resource_id, name),
                    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
                )
            SQL);
    }

    /**
     * The two context columns arrived after the first specs were stored, and
     * `CREATE TABLE IF NOT EXISTS` does not add a column to a table that
     * already exists — `storage/data/restadmin.db` is exactly that case. So the
     * addition is measured rather than assumed: a column that is absent is
     * added, a boot on a current database changes nothing, and a stored spec
     * keeps its `[]` default instead of failing the query that reads it.
     */
    private function ensureFieldContextColumns(): void
    {
        $present = $this->dialect->columnNames($this->pdo, 'fields');

        foreach ([
            'hidden_in'    => "TEXT NOT NULL DEFAULT '[]'",
            'read_only_in' => "TEXT NOT NULL DEFAULT '[]'",
        ] as $column => $definition) {
            if (!in_array($column, $present, true)) {
                $this->pdo->exec('ALTER TABLE fields ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
    }

    private function createRelationsTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS relations (
                    id              VARCHAR(32) PRIMARY KEY,
                    resource_id     VARCHAR(32) NOT NULL,
                    name            VARCHAR(100) NOT NULL,
                    kind            VARCHAR(30) NOT NULL,
                    target_resource VARCHAR(100) NOT NULL,
                    foreign_key     VARCHAR(255) DEFAULT NULL,
                    sort_order      INTEGER NOT NULL DEFAULT 0,
                    UNIQUE (resource_id, name),
                    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
                )
            SQL);
    }

    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_resources_service ON resources(service_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_fields_resource ON fields(resource_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_relations_resource ON relations(resource_id)');
    }
}
