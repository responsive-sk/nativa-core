<?php

declare(strict_types = 1);

namespace Modules\Media\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;

/**
 * Media Database Migrator — creates the media.db schema.
 *
 * Tables: assets (one row per tracked uploaded file) and media_folders (the
 * folder registry — which folders exist, whether or not they hold files). New
 * uploads only — existing files in storage/uploads/ are not migrated, and the
 * registry is backfilled from the folders those uploads already carry.
 */
#[AsMigrator(module: 'media', table: 'assets')]
final readonly class MediaDatabaseMigrator implements MigratorInterface
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating media.db...</info>');

        $this->createAssetsTable();
        $this->createIndexes();
        $this->addAltTextAndCaption();
        $this->addProviderColumn();
        $this->addFolderColumn();
        $this->addCategoryAndViewCount();
        $this->createMediaTagsTable();
        $this->createFolderRegistryTable();

        $output->writeln('<info>  ✓ Created table: assets</info>');
        $output->writeln('<info>  ✓ Created table: media_folders (backfilled from local asset folders)</info>');
    }

    private function createAssetsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS assets (
                    id            VARCHAR(32) PRIMARY KEY,
                    filename      VARCHAR(255) NOT NULL,
                    original_name VARCHAR(255) NOT NULL,
                    path          VARCHAR(512) NOT NULL,
                    size          INTEGER NOT NULL DEFAULT 0,
                    mime_type     VARCHAR(128) NOT NULL,
                    uploaded_by   VARCHAR(64) NOT NULL DEFAULT '',
                    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_assets_created ON assets(created_at DESC)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_assets_mime ON assets(mime_type)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_assets_path ON assets(path)');
    }

    private function addAltTextAndCaption(): void
    {
        $columns = $this->fetchColumnNames();

        if (!in_array('alt_text', $columns, true)) {
            $this->pdo->exec('ALTER TABLE assets ADD COLUMN alt_text VARCHAR(512) NOT NULL DEFAULT \'\'');
        }

        if (!in_array('caption', $columns, true)) {
            $this->pdo->exec('ALTER TABLE assets ADD COLUMN caption VARCHAR(1024) NOT NULL DEFAULT \'\'');
        }
    }

    private function addProviderColumn(): void
    {
        $columns = $this->fetchColumnNames();

        if (!in_array('provider', $columns, true)) {
            $this->pdo->exec("ALTER TABLE assets ADD COLUMN provider VARCHAR(32) NOT NULL DEFAULT ''");
        }
    }

    /**
     * The folder path an asset lives in ('' = root). Filled by upload
     * (root) and by Cloudinary import (the remote folder it came from).
     * Idempotent — the same conditional ALTER as `provider`.
     */
    private function addFolderColumn(): void
    {
        $columns = $this->fetchColumnNames();

        if (!in_array('folder', $columns, true)) {
            $this->pdo->exec("ALTER TABLE assets ADD COLUMN folder VARCHAR(255) NOT NULL DEFAULT ''");
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_assets_folder ON assets(folder)');
        }
    }

    private function addCategoryAndViewCount(): void
    {
        $columns = $this->fetchColumnNames();

        if (!in_array('category', $columns, true)) {
            $this->pdo->exec("ALTER TABLE assets ADD COLUMN category VARCHAR(128) NOT NULL DEFAULT ''");
        }

        if (!in_array('view_count', $columns, true)) {
            $this->pdo->exec('ALTER TABLE assets ADD COLUMN view_count INTEGER NOT NULL DEFAULT 0');
        }

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_assets_category ON assets(category)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_assets_view_count ON assets(view_count DESC)');
    }

    private function createMediaTagsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS media_tags (
                    asset_id VARCHAR(32) NOT NULL,
                    tag      VARCHAR(128) NOT NULL,
                    PRIMARY KEY (asset_id, tag)
                )
            SQL);

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_media_tags_tag ON media_tags(tag)');
    }

    /**
     * The folder registry. A folder exists because it has a row here, not
     * because some asset happens to name it — which is what makes an empty
     * folder representable and a folder survive its last file leaving.
     *
     * `path` is the primary key: a folder's identity IS its path, the same
     * string `assets.folder` stores, so no join key has to be invented and a
     * rename is a rewrite of the key plus the assets that carry it.
     *
     * The backfill registers every distinct folder of a local asset, so a
     * library that already had folders keeps them. Cloudinary rows are
     * excluded on purpose: a remote file's `folder` is where the provider put
     * it, and that path is shown from the asset row itself. Registering it
     * here would make it a folder this installation can create, rename and
     * delete, while the files in it stay under the provider's control.
     *
     * Both statements are idempotent — INSERT OR IGNORE plus a SELECT that
     * cannot produce a duplicate — so the migrator stays safe to re-run on a
     * live database, which is when it is actually called.
     */
    private function createFolderRegistryTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS media_folders (
                    path       VARCHAR(255) PRIMARY KEY,
                    source     VARCHAR(32) NOT NULL DEFAULT 'local',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);

        if (!$this->hasUnregisteredFolders()) {
            return;
        }

        $this->pdo->exec(<<<'SQL'
                INSERT OR IGNORE INTO media_folders (path)
                SELECT DISTINCT folder FROM assets
                WHERE folder != '' AND provider != 'cloudinary'
            SQL);
    }

    /**
     * Whether the backfill would insert anything.
     *
     * The migrator runs on the first request of every install, on every fresh
     * database, and in every test that boots the app, so it must not take a
     * lock it does not need. An `INSERT` opens a write transaction even when
     * it inserts no rows, and SQLite allows one writer: without this check the
     * statement serialised against any other connection reading `media.db`,
     * which surfaced as `SQLSTATE[HY000]: General error: 5 database is
     * locked` in the suite — and only sometimes, because it depended on when
     * the other read landed. Asking first makes the steady-state run a read
     * again; the write happens exactly when there is something to write.
     */
    private function hasUnregisteredFolders(): bool
    {
        $stmt = $this->pdo->query(<<<'SQL'
                SELECT COUNT(*) FROM (
                    SELECT DISTINCT folder FROM assets
                    WHERE folder != '' AND provider != 'cloudinary'
                    AND folder NOT IN (SELECT path FROM media_folders)
                )
            SQL);

        return false !== $stmt && (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return list<string>
     */
    private function fetchColumnNames(): array
    {
        $stmt = $this->pdo->query('PRAGMA table_info(assets)');
        if (false === $stmt) {
            return [];
        }

        /** @var list<array{name: string, type: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_column($rows, 'name');
    }
}
