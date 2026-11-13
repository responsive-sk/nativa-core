<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Interfaces\HTTP\CardboardServices;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AdminNavContractTest — the sidebar's two queries, through the wrapper.
 *
 * `AdminNavService` runs on every `/mark` render, in both the full and the
 * compact layout, so it is the busiest reader in the admin panel and the
 * reason the nav is on the connection contract at all. Its two queries have
 * distinct failure modes worth pinning: `allItems()` has to turn an empty
 * `menu_items` into the hardcoded fallback (a fresh install renders a nav
 * before the seed runs), and `hasChildren()` decides whether a group starts
 * expanded, which is a `SELECT 1` whose answer must survive the wrapper.
 *
 * A raw `\PDO` is built here only for the migrator, which keeps `\PDO`: DDL
 * runs before any repository exists.
 */
final class AdminNavContractTest extends TestCase
{
    private string $tmpDir = '';

    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Cardboard') || !is_dir(APP_BASE_PATH . '/src/Modules/Settings')) {
            $this->markTestSkipped('Cardboard and Settings are not installed.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-admin-nav-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_CONFIG=' . $this->tmpDir . '/config.db');
        DatabaseConnectionManager::resetInstance();
        CardboardServices::reset();

        (new SettingsDatabaseMigrator(new PDO('sqlite:' . $this->tmpDir . '/config.db')))
            ->run($this->quietOutput());

        $this->connection = DatabaseConnectionManager::instance()->connect('config');
    }

    protected function tearDown(): void
    {
        if (is_dir(APP_BASE_PATH . '/src/Modules/Cardboard')) {
            putenv('DB_CONFIG');
            DatabaseConnectionManager::resetInstance();
            CardboardServices::reset();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function anEmptyTableFallsBackToTheHardcodedNav(): void
    {
        $this->clearAdminMenu();

        $items = CardboardServices::adminNav()->allItems();

        self::assertNotEmpty($items, 'a fresh install renders the nav before the seed runs');
        self::assertContains('_dashboard', array_column($items, 'id'));
    }

    /**
     * A module that is not installed has no route, so its seeded menu item
     * must not survive. The group test uses `Blog`'s Categories child: the
     * parent's own href is absent too, and the group stays because a child is
     * served.
     */
    #[Test]
    public function anItemWithoutAServedRouteIsDroppedWithItsGroupIntact(): void
    {
        $this->clearAdminMenu();

        $router = Router::fromEntries([], [
            ['path' => '/mark', 'method' => 'GET', 'name' => 'mark.dashboard', 'handlerMethod' => null, 'action' => self::class],
            ['path' => '/mark/categories', 'method' => 'GET', 'name' => 'categories.manage', 'handlerMethod' => null, 'action' => self::class],
        ]);

        $tree = CardboardServices::adminNav()->hierarchicalItems(
            '',
            null,
            static fn (string $href): bool => $router->hasPath($href),
        );

        $ids = array_column($tree, 'id');
        self::assertContains('_dashboard', $ids);
        self::assertContains('_blog', $ids, 'a group survives when one child is served');
        self::assertNotContains('_questions', $ids);
        self::assertNotContains('_pages', $ids);

        $blog = null;
        foreach ($tree as $item) {
            if ('_blog' === $item['id']) {
                $blog = $item;
            }
        }

        self::assertNotNull($blog);
        self::assertSame(['_categories'], array_column($blog['children'], 'id'));
    }

    #[Test]
    public function aGroupExpandsWhenOneOfItsChildrenIsActive(): void
    {
        $this->clearAdminMenu();
        $this->insertItem('group-blog', 'Writing', '/mark/articles', null, 10);
        $this->insertItem('item-articles', 'Articles', '/mark/articles', 'group-blog', 10);

        $tree = CardboardServices::adminNav()->hierarchicalItems('articles');

        self::assertCount(1, $tree, 'one top-level group, built from the row set');
        self::assertSame('group-blog', $tree[0]['id']);
        self::assertCount(1, $tree[0]['children']);
        self::assertSame('item-articles', $tree[0]['children'][0]['id']);
        self::assertTrue($tree[0]['is_expanded'], 'hasChildren() decides this, and it runs through the wrapper');
    }

    private function clearAdminMenu(): void
    {
        $this->connection->prepare("DELETE FROM menu_items WHERE location = 'admin'")->execute();
    }

    private function insertItem(string $id, string $label, string $href, ?string $parent, int $order): void
    {
        $this->connection
            ->prepare(
                'INSERT INTO menu_items (id, label, href, parent_item_id, sort_order, location, is_active)'
                . " VALUES (?, ?, ?, ?, ?, 'admin', 1)",
            )
            ->execute([$id, $label, $href, $parent, $order]);
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
