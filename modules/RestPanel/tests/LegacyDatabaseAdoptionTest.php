<?php

declare(strict_types = 1);

namespace Modules\RestPanel\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\RestPanel\Infrastructure\Persistence\Migrations\RestPanelDatabaseMigrator;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

/**
 * LegacyDatabaseAdoptionTest — the panel's rows survive the file's rename.
 *
 * The panel's database was `restadmin.db` until the vocabulary round. A
 * deployment that upgrades has its services in the old file and no reason to
 * rebuild them by hand, so `bin/cms migrate` copies them once and retires the
 * old file. The three claims worth pinning:
 *
 *  1. Every row of all four tables arrives, children after parents, and the
 *     old file is gone from the live path (kept as `restpanel-legacy.db`).
 *  2. A second migrate adopts nothing: the copy is guarded by the new file's
 *     `services` count, so re-running is safe.
 *  3. A database that IS the legacy path (the first run in this test) is not
 *     attached to itself — `PRAGMA database_list` decides, not a guess.
 */
final class LegacyDatabaseAdoptionTest extends TestCase
{
    use SqliteDatabase;

    private string $directory = '';

    private string|false $legacyEnv = false;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/RestPanel')) {
            $this->markTestSkipped('RestPanel is not installed — its slice ships with the module.');
        }

        $this->directory = sys_get_temp_dir() . '/nativa-restpanel-legacy-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o775, true);
        $this->legacyEnv = getenv('DB_RESTADMIN');
        putenv('DB_RESTADMIN=' . $this->legacyPath());
    }

    protected function tearDown(): void
    {
        $this->restoreDatabaseEnvironment();

        if (false === $this->legacyEnv) {
            putenv('DB_RESTADMIN');
        } else {
            putenv('DB_RESTADMIN=' . $this->legacyEnv);
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);

        parent::tearDown();

        $this->restoreDatabaseEnvironment();
    }

    #[Test]
    public function everyRowOfThePreRenameFileIsAdoptedAndTheOldFileRetired(): void
    {
        $this->seedLegacyDatabase();

        $panel = new PDO('sqlite:' . $this->panelPath());
        (new RestPanelDatabaseMigrator($panel))->run($this->quietOutput());

        self::assertSame('Blog', $panel->query('SELECT name FROM services')->fetchColumn());
        self::assertSame('posts', $panel->query('SELECT name FROM resources')->fetchColumn());
        self::assertSame('title', $panel->query('SELECT name FROM fields')->fetchColumn());
        self::assertSame('author', $panel->query('SELECT name FROM relations')->fetchColumn());

        self::assertFileDoesNotExist($this->legacyPath());
        self::assertFileExists($this->directory . '/restpanel-legacy.db');
    }

    #[Test]
    public function aSecondMigrateAdoptsNothing(): void
    {
        $this->seedLegacyDatabase();

        (new RestPanelDatabaseMigrator(new PDO('sqlite:' . $this->panelPath())))->run($this->quietOutput());
        (new RestPanelDatabaseMigrator(new PDO('sqlite:' . $this->panelPath())))->run($this->quietOutput());

        $panel = new PDO('sqlite:' . $this->panelPath());

        self::assertSame(1, (int) $panel->query('SELECT COUNT(*) FROM services')->fetchColumn());
        self::assertSame(1, (int) $panel->query('SELECT COUNT(*) FROM resources')->fetchColumn());
    }

    /**
     * A populated database at the pre-rename path, with one row per table so a
     * missing table or a wrong insert order is visible.
     */
    private function seedLegacyDatabase(): void
    {
        $legacy = new PDO('sqlite:' . $this->legacyPath());
        (new RestPanelDatabaseMigrator($legacy))->run($this->quietOutput());

        $legacy->exec("INSERT INTO services (id, name, base_url) VALUES ('svc1', 'Blog', 'https://example.test')");
        $legacy->exec("INSERT INTO resources (id, service_id, name, path, item_path) VALUES ('res1', 'svc1', 'posts', '/posts', '/posts/{id}')");
        $legacy->exec("INSERT INTO fields (id, resource_id, name, label) VALUES ('fld1', 'res1', 'title', 'Title')");
        $legacy->exec("INSERT INTO relations (id, resource_id, name, kind, target_resource) VALUES ('rel1', 'res1', 'author', 'belongs_to', 'users')");

        $legacy = null;
    }

    private function legacyPath(): string
    {
        return $this->directory . '/restadmin.db';
    }

    private function panelPath(): string
    {
        return $this->directory . '/restpanel.db';
    }

    private function quietOutput(): CliOutput
    {
        return new class () implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
