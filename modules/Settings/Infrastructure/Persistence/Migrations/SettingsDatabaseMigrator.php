<?php

declare(strict_types = 1);

namespace Modules\Settings\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;
use Modules\Settings\Infrastructure\Persistence\Statement;

/**
 * Settings Database Migrator — creates the complete config.db schema.
 *
 * Tables: settings (key/value), menu_items (navigation), design_tokens.
 * Every statement is idempotent (CREATE TABLE IF NOT EXISTS).
 */
#[AsMigrator(module: 'config', table: 'settings')]
final readonly class SettingsDatabaseMigrator implements MigratorInterface
{
    /**
     * Default menu items seeded idempotently — label, href, location, sort[, icon].
     *
     * Every href here must be a route the kernel itself serves: measured on a
     * fresh install, the seed used to ship `Privacy -> /privacy` and
     * `Cookies -> /cookies`, and neither path exists in this tree (both 404).
     * A navbar that offers a dead end is the same defect the shell provider
     * filters admin links for. The footer seeds no route: `/docs` belongs to
     * the Docs module, and a module's link is added in `/mark` (Navigation) by
     * the deployment that installs it — the seed is a starting point, not a
     * policy.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int, 4?: string}>
     */
    private const array DEFAULT_MENU_ITEMS = [
        ['Home', '/', 'header', 10],
        ['Articles', '/blog', 'header', 15],
        ['Admin', '/mark', 'header', 20],
        // Admin panel navigation (location = 'admin') — label, href, location, sort, icon
        ['Dashboard', '/mark', 'admin', 10, 'dashboard'],
        ['Notifications', '/mark/notifications', 'admin', 15, 'bell'],
        ['Users', '/mark/users', 'admin', 20, 'users'],
        ['Roles', '/mark/roles', 'admin', 30, 'shield'],
        ['Settings', '/mark/settings', 'admin', 40, 'settings'],
        ['Locales', '/mark/locales', 'admin', 50, 'globe'],
        ['Media', '/mark/media', 'admin', 60, 'image'],
        ['Blog', '/mark/articles', 'admin', 70, 'rss'],
        ['Articles', '/mark/articles', 'admin', 71, 'file-text'],
        ['Categories', '/mark/categories', 'admin', 72, 'folder'],
        ['Tags', '/mark/tags', 'admin', 73, 'tag'],
        ['Pages', '/mark/pages', 'admin', 80, 'file'],
        ['Comments', '/mark/comments', 'admin', 100, 'message'],
        ['Questions', '/mark/questions', 'admin', 102, 'help-circle'],
        ['Forms', '/mark/forms', 'admin', 105, 'inbox'],
        ['Outbox', '/mark/outbox', 'admin', 108, 'inbox'],
        ['Audit', '/mark/audit', 'admin', 110, 'clipboard'],
    ];

    public function __construct(private \PDO $pdo)
    {
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating config.db...</info>');

        $this->createSettingsTable();
        $this->createMenuItemsTable();
        $this->createDesignTokensTable();
        $this->createIndexes();
        $this->addColumnIfMissing('menu_items', 'icon', "VARCHAR(50) DEFAULT ''");

        // Seed default settings + menu items (both idempotent)
        $seeded = $this->seedDefaultSettings();
        $menuSeeded = $this->seedDefaultMenu();

        $output->writeln(sprintf(
            '<info>  ✓ Created tables: settings (%d defaults), menu_items (%d defaults), design_tokens</info>',
            $seeded,
            $menuSeeded,
        ));
    }

    /**
     * Settings table — key/value store with type metadata.
     */
    private function createSettingsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS settings (
                    id VARCHAR(32) PRIMARY KEY,
                    setting_key VARCHAR(100) UNIQUE NOT NULL,
                    setting_value TEXT,
                    value_type VARCHAR(20) DEFAULT 'string',
                    group_name VARCHAR(50) DEFAULT 'general',
                    is_public INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    /**
     * Navigation menu items (all menu locations).
     */
    private function createMenuItemsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS menu_items (
                    id VARCHAR(32) PRIMARY KEY,
                    label VARCHAR(100) NOT NULL,
                    href VARCHAR(255) NOT NULL,
                    parent_item_id VARCHAR(32) REFERENCES menu_items(id) ON DELETE CASCADE,
                    sort_order INTEGER DEFAULT 0,
                    location VARCHAR(50) NOT NULL,
                    external BOOLEAN DEFAULT 0,
                    target VARCHAR(20),
                    is_active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    /**
     * Design tokens — JSON key/value for theme customisation.
     */
    private function createDesignTokensTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS design_tokens (
                    id VARCHAR(32) PRIMARY KEY,
                    token_key VARCHAR(100) UNIQUE NOT NULL,
                    token_value TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    /**
     * Add a column to a table if it does not already exist (idempotent).
     */
    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $stmt = Statement::query($this->pdo, "PRAGMA table_info({$table})");
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            if (is_array($row) && ($row['name'] ?? '') === $column) {
                return;
            }
        }

        $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_settings_group ON settings(group_name)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_settings_key ON settings(setting_key)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_menu_items_location ON menu_items(location)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_menu_items_parent ON menu_items(parent_item_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_menu_items_sort ON menu_items(location, sort_order)');
    }

    /**
     * Seed default settings via INSERT OR IGNORE (idempotent).
     */
    private function seedDefaultSettings(): int
    {
        $defaults = [
            ['site_name', 'Nativa PHP Core', 'string', 'general', 1],
            ['site_description', 'Nativa PHP Core site', 'string', 'general', 1],
            ['admin_email', 'admin@example.test', 'string', 'general', 0],
            ['timezone', 'UTC', 'string', 'general', 0],
            ['locale', 'sk_SK', 'string', 'general', 1],
            ['registration_enabled', '1', 'boolean', 'security', 1],
            ['maintenance_mode', '0', 'boolean', 'security', 0],
            ['theme', 'kernel', 'string', 'design', 0],
        ];

        $inserted = 0;
        $stmt = Statement::prepare($this->pdo,
            'INSERT OR IGNORE INTO settings (id, setting_key, setting_value, value_type, group_name, is_public, created_at, updated_at)
             VALUES (:id, :key, :value, :type, :group, :public, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        );

        foreach ($defaults as [$key, $value, $type, $group, $isPublic]) {
            $stmt->execute([
                ':id'     => bin2hex(random_bytes(16)),
                ':key'    => $key,
                ':value'  => $value,
                ':type'   => $type,
                ':group'  => $group,
                ':public' => $isPublic,
            ]);

            if ($stmt->rowCount() > 0) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * Seed a minimal navigation menu (header + footer) idempotently.
     */
    private function seedDefaultMenu(): int
    {
        $inserted = 0;
        $existsStmt = Statement::prepare($this->pdo, 'SELECT 1 FROM menu_items WHERE label = :label AND location = :loc LIMIT 1');
        $stmt = Statement::prepare($this->pdo,
            'INSERT INTO menu_items (id, label, href, sort_order, location, icon, is_active, created_at, updated_at)
             VALUES (:id, :label, :href, :sort, :loc, :icon, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        );

        foreach (self::DEFAULT_MENU_ITEMS as $item) {
            $inserted += $this->seedMenuItem($existsStmt, $stmt, $item);
        }

        // Nest Tags and Categories under Articles (parent_item_id) — idempotent.
        $this->nestChildrenUnderArticles();

        return $inserted;
    }

    /**
     * Insert one default menu item unless its label+location already exists.
     *
     * @param \PDOStatement                                              $existsStmt prepared existence probe (label + location)
     * @param \PDOStatement                                              $stmt       prepared INSERT for one menu item
     * @param array{0: string, 1: string, 2: string, 3: int, 4?: string} $item       label, href, location, sort, icon
     */
    private function seedMenuItem(\PDOStatement $existsStmt, \PDOStatement $stmt, array $item): int
    {
        $existsStmt->execute([':label' => $item[0], ':loc' => $item[2]]);

        if (false !== $existsStmt->fetch()) {
            return 0;
        }

        $stmt->execute([
            ':id'    => bin2hex(random_bytes(16)),
            ':label' => $item[0],
            ':href'  => $item[1],
            ':sort'  => $item[3],
            ':loc'   => $item[2],
            ':icon'  => $item[4] ?? '',
        ]);

        return 1;
    }

    /**
     * Nest Articles, Tags, and Categories under Blog (expandable group).
     */
    private function nestChildrenUnderArticles(): void
    {
        $stmt = Statement::query($this->pdo,
            "SELECT id FROM menu_items WHERE label = 'Blog' AND location = 'admin' AND parent_item_id IS NULL LIMIT 1",
        );

        $blogId = $stmt->fetchColumn();

        if (false === $blogId || '' === $blogId) {
            return;
        }

        $nestStmt = Statement::prepare($this->pdo,
            'UPDATE menu_items SET parent_item_id = :parent, updated_at = CURRENT_TIMESTAMP
             WHERE label = :label AND location = :loc AND parent_item_id IS NULL',
        );

        foreach (['Articles', 'Tags', 'Categories'] as $childLabel) {
            $nestStmt->execute([':parent' => $blogId, ':label' => $childLabel, ':loc' => 'admin']);
        }
    }
}
