<?php

declare(strict_types = 1);

namespace Modules\RestApi\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\RestApi\Infrastructure\Persistence\Migrations\RestApiDatabaseMigrator;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

/**
 * LegacyDatabaseAdoptionTest — the rows survive the file's rename.
 *
 * The slice's database was `restpanel.db`, and `restadmin.db` before that. A
 * deployment that upgrades has its services in the old file and no reason to
 * rebuild them by hand, so `bin/cms migrate` copies them once and retires the
 * old file. The file is found beside the live database, by name; no
 * environment key points at it, so there is no alias to honour and none to
 * retire.
 *
 * The claims worth pinning:
 *
 *  1. Every row of all four tables arrives, children after parents, and the
 *     old file is gone from the live path (kept as `restapi-legacy.db`).
 *  2. A second migrate adopts nothing: the copy is guarded by the new file's
 *     `services` count, so re-running is safe.
 *  3. A database that IS the legacy path is not attached to itself —
 *     `PRAGMA database_list` decides, not a guess.
 *  4. The file two renames old is adopted when the nearer one is absent.
 */
final class LegacyDatabaseAdoptionTest extends TestCase
{
    use SqliteDatabase;

    private string $directory = '';

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/RestApi')) {
            $this->markTestSkipped('RestApi is not installed — its slice ships with the module.');
        }

        $this->directory = sys_get_temp_dir() . '/nativa-restapi-legacy-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o775, true);
    }

    protected function tearDown(): void
    {
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
        $this->seedDatabase($this->legacyPath());

        $panel = new PDO('sqlite:' . $this->panelPath());
        (new RestApiDatabaseMigrator($panel))->run($this->quietOutput());

        self::assertSame('Blog', $panel->query('SELECT name FROM services')->fetchColumn());
        self::assertSame('posts', $panel->query('SELECT name FROM resources')->fetchColumn());
        self::assertSame('title', $panel->query('SELECT name FROM fields')->fetchColumn());
        self::assertSame('author', $panel->query('SELECT name FROM relations')->fetchColumn());

        self::assertFileDoesNotExist($this->legacyPath());
        self::assertFileExists($this->directory . '/restapi-legacy.db');
    }

    #[Test]
    public function theFileTwoRenamesOldIsAdoptedWhenTheNearerOneIsGone(): void
    {
        $this->seedDatabase($this->olderPath());

        $panel = new PDO('sqlite:' . $this->panelPath());
        (new RestApiDatabaseMigrator($panel))->run($this->quietOutput());

        self::assertSame('Blog', $panel->query('SELECT name FROM services')->fetchColumn());
        self::assertFileDoesNotExist($this->olderPath());
        self::assertFileExists($this->directory . '/restapi-legacy.db');
    }

    #[Test]
    public function aSecondMigrateAdoptsNothing(): void
    {
        $this->seedDatabase($this->legacyPath());

        (new RestApiDatabaseMigrator(new PDO('sqlite:' . $this->panelPath())))->run($this->quietOutput());
        (new RestApiDatabaseMigrator(new PDO('sqlite:' . $this->panelPath())))->run($this->quietOutput());

        $panel = new PDO('sqlite:' . $this->panelPath());

        self::assertSame(1, (int) $panel->query('SELECT COUNT(*) FROM services')->fetchColumn());
        self::assertSame(1, (int) $panel->query('SELECT COUNT(*) FROM resources')->fetchColumn());
    }

    #[Test]
    public function aDatabaseThatIsTheLegacyPathIsNotAttachedToItself(): void
    {
        $legacy = new PDO('sqlite:' . $this->legacyPath());
        (new RestApiDatabaseMigrator($legacy))->run($this->quietOutput());

        self::assertFileExists($this->legacyPath());
        self::assertSame(0, (int) $legacy->query('SELECT COUNT(*) FROM services')->fetchColumn());
    }

    /**
     * A populated database at a legacy path, with one row per table so a
     * missing table or a wrong insert order is visible.
     */
    private function seedDatabase(string $path): void
    {
        $seed = new PDO('sqlite:' . $path);
        (new RestApiDatabaseMigrator($seed))->run($this->quietOutput());

        $seed->exec("INSERT INTO services (id, name, base_url) VALUES ('svc1', 'Blog', 'https://example.test')");
        $seed->exec("INSERT INTO resources (id, service_id, name, path, item_path) VALUES ('res1', 'svc1', 'posts', '/posts', '/posts/{id}')");
        $seed->exec("INSERT INTO fields (id, resource_id, name, label) VALUES ('fld1', 'res1', 'title', 'Title')");
        $seed->exec("INSERT INTO relations (id, resource_id, name, kind, target_resource) VALUES ('rel1', 'res1', 'author', 'belongs_to', 'users')");

        $seed = null;
    }

    private function legacyPath(): string
    {
        return $this->directory . '/restpanel.db';
    }

    private function olderPath(): string
    {
        return $this->directory . '/restadmin.db';
    }

    private function panelPath(): string
    {
        return $this->directory . '/restapi.db';
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
