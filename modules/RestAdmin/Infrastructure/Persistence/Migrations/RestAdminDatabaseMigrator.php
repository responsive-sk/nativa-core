<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;

/**
 * RestAdmin Database Migrator — the spec store's schema.
 *
 * Four tables: a service (`services`), a collection of it (`resources`), the
 * columns the panel renders (`fields`), and how one resource points at another
 * (`relations`). The JSON spec is decomposed rather than stored as one blob so
 * a validation query can ask "which resources point at a resource that is
 * gone" without parsing every document.
 *
 * Every statement is idempotent (`CREATE TABLE IF NOT EXISTS`), so
 * `bin/cms migrate` may run it on every boot, and `module:remove RestAdmin`
 * leaves the data behind as the catalogue promises.
 *
 * Secrets are not here: `services.token_env` names an environment variable,
 * never a token (plan, D5 — the kernel has no crypto port yet).
 */
#[AsMigrator(module: 'restadmin', table: 'services')]
final readonly class RestAdminDatabaseMigrator implements MigratorInterface
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating restadmin.db...</info>');

        $this->createServicesTable();
        $this->createResourcesTable();
        $this->createFieldsTable();
        $this->createRelationsTable();
        $this->createIndexes();

        $output->writeln('<info>  ✓ Created tables: services, resources, fields, relations</info>');
    }

    private function createServicesTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS services (
                    id              VARCHAR(32) PRIMARY KEY,
                    name            VARCHAR(100) UNIQUE NOT NULL,
                    base_url        VARCHAR(2048) NOT NULL,
                    auth_header     VARCHAR(100) DEFAULT NULL,
                    token_env       VARCHAR(100) DEFAULT NULL,
                    timeout_seconds INTEGER NOT NULL DEFAULT 10,
                    headers         TEXT NOT NULL DEFAULT '{}',
                    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createResourcesTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS resources (
                    id              VARCHAR(32) PRIMARY KEY,
                    service_id      VARCHAR(32) NOT NULL,
                    name            VARCHAR(100) NOT NULL,
                    path            VARCHAR(2048) NOT NULL,
                    item_path       VARCHAR(2048) NOT NULL,
                    id_field        VARCHAR(100) NOT NULL DEFAULT 'id',
                    actions         TEXT NOT NULL DEFAULT '["list","read"]',
                    pagination      VARCHAR(30) NOT NULL DEFAULT 'page',
                    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (service_id, name),
                    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
                )
            SQL);
    }

    private function createFieldsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS fields (
                    id              VARCHAR(32) PRIMARY KEY,
                    resource_id     VARCHAR(32) NOT NULL,
                    name            VARCHAR(255) NOT NULL,
                    label           VARCHAR(255) NOT NULL,
                    type            VARCHAR(30) NOT NULL DEFAULT 'text',
                    is_required     INTEGER NOT NULL DEFAULT 0,
                    is_read_only    INTEGER NOT NULL DEFAULT 0,
                    in_list         INTEGER NOT NULL DEFAULT 1,
                    sort_order      INTEGER NOT NULL DEFAULT 0,
                    UNIQUE (resource_id, name),
                    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
                )
            SQL);
    }

    private function createRelationsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
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
