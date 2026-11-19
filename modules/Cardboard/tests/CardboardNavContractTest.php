<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Infrastructure\Registry\CardboardNavRegistry;
use Modules\Cardboard\Interfaces\HTTP\Actions\SettingsAction;
use Modules\Cardboard\Interfaces\HTTP\CardboardLayout;
use Modules\Cardboard\Interfaces\HTTP\CardboardServices;
use Modules\Core\Domain\MarkRoutePolicy;
use Modules\Core\Domain\Permissions;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

/**
 * CardboardNavContractTest — the sidebar's source, through the wrapper.
 *
 * `CardboardNavService` runs on every `/mark` render, in both the full and the
 * compact layout. It used to answer from three copies of the same list: its own
 * `FALLBACK`, the Settings seed, and (for permissions) a copy of
 * `MarkRoutePolicy`'s table. The nav is now declared by the modules that own
 * the pages, discovered from installed modules only, and this file pins the
 * three things that makes true:
 *
 *   - a fresh install renders the nav with no stored rows at all;
 *   - a stored row still overrides the declared item, so
 *     `/mark/settings/admin-nav` remains an editor rather than a stale copy;
 *   - every declared permission is the one the policy asks for, so the nav can
 *     never offer a link the middleware refuses.
 *
 * A raw `\PDO` is built here only for the migrator, which keeps `\PDO`: DDL
 * runs before any repository exists.
 */
final class CardboardNavContractTest extends TestCase
{
    use SqliteDatabase;

    private string $tmpDir = '';

    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Cardboard') || !is_dir(APP_BASE_PATH . '/src/Modules/Settings')) {
            $this->markTestSkipped('Cardboard and Settings are not installed.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-panel-nav-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_CONFIG=' . $this->tmpDir . '/config.db');
        DatabaseConnectionManager::resetInstance();
        CardboardServices::reset();
        CardboardNavRegistry::reset();

        (new SettingsDatabaseMigrator(new PDO('sqlite:' . $this->tmpDir . '/config.db')))
            ->run($this->quietOutput());

        $this->connection = DatabaseConnectionManager::instance()->connect('config');
    }

    protected function tearDown(): void
    {
        $this->restoreDatabaseEnvironment();

        if (is_dir(APP_BASE_PATH . '/src/Modules/Cardboard')) {
            putenv('DB_CONFIG');
            DatabaseConnectionManager::resetInstance();
            CardboardServices::reset();
            CardboardNavRegistry::reset();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        parent::tearDown();

        $this->restoreDatabaseEnvironment();
    }

    /**
     * A fresh install has header rows only, and the admin nav still renders.
     *
     * Before discovery this was `FALLBACK`: 18 hardcoded entries naming four
     * modules with no catalogue slice in this tree.
     */
    #[Test]
    public function aFreshInstallRendersTheNavFromDeclaredAttributes(): void
    {
        $this->clearPanelMenu();

        $items = CardboardServices::panelNav()->allItems();

        self::assertNotSame([], $items, 'the nav follows the installed modules, not a stored list');
        self::assertContains('Dashboard', array_column($items, 'label'));
        self::assertContains('/mark/articles', array_column($items, 'href'));

        // Pages carries a catalogue slice again, so its entry is the module's
        // and follows it: the export prunes Pages, and that tree must not offer
        // the link either. The reverse direction, a served page nothing links
        // to, is asserted against a booted server in `bin/build/mark-smoke.sh`,
        // where the route table and the rendered sidebar are the same tree;
        // `allItems()` here cannot see whether a page renders at all.
        $hrefs = array_column($items, 'href');
        self::assertSame(
            is_dir(APP_BASE_PATH . '/src/Modules/Pages'),
            in_array('/mark/pages', $hrefs, true),
            'the Pages entry follows the installed module, not a stored list',
        );
    }

    /**
     * A module that is not installed has no route, so its entry must not
     * survive. The group test uses Blog's Categories child: the parent's own
     * href is absent too, and the group stays because a child is served.
     */
    #[Test]
    public function anItemWithoutAServedRouteIsDroppedWithItsGroupIntact(): void
    {
        $this->clearPanelMenu();

        $router = Router::fromEntries([], [
            ['path' => '/mark', 'method' => 'GET', 'name' => 'mark.dashboard', 'handlerMethod' => null, 'action' => self::class],
            ['path' => '/mark/categories', 'method' => 'GET', 'name' => 'categories.manage', 'handlerMethod' => null, 'action' => self::class],
        ]);

        $tree = CardboardServices::panelNav()->hierarchicalItems(
            '',
            null,
            static fn (string $href): bool => $router->hasPath($href),
        );

        $ids = array_column($tree, 'id');
        self::assertContains('_dashboard', $ids);
        self::assertContains('_blog', $ids, 'a group survives when one child is served');
        self::assertNotContains('_notifications', $ids);
        self::assertNotContains('_media', $ids);

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
        $this->clearPanelMenu();
        $this->insertItem('group-blog', 'Writing', '/mark/articles', null, 10);
        $this->insertItem('item-articles', 'Articles', '/mark/articles', 'group-blog', 10);

        $tree = CardboardServices::panelNav()->hierarchicalItems('articles');

        $group = null;
        foreach ($tree as $item) {
            if ('group-blog' === $item['id']) {
                $group = $item;
            }
        }

        self::assertNotNull($group, 'the stored row wins for the href it shares with the declaration');
        self::assertSame('Writing', $group['label'], 'a stored label overrides the declared one');

        // The stored row replaces the declared Articles child; Categories and
        // Tags are still declared and have no stored partner here, so the group
        // keeps three children in order.
        self::assertSame(['item-articles', '_categories', '_tags'], array_column($group['children'], 'id'));
        self::assertTrue($group['is_expanded'], 'a group is expanded from the merged set, not a second query');
    }

    /**
     * The acceptance that keeps the admin-nav screen meaningful: a row stored
     * for an item's href wins outright, so an operator can relabel or move a
     * module's entry without a code edit.
     */
    #[Test]
    public function aStoredRowOverridesTheDeclaredItem(): void
    {
        $this->clearPanelMenu();
        $this->insertItem('row-media', 'Images', '/mark/media', null, 5);

        $media = array_values(array_filter(
            CardboardServices::panelNav()->allItems(),
            static fn (array $item): bool => '/mark/media' === $item['href'],
        ));

        self::assertCount(1, $media, 'the row replaces the declaration instead of adding a second link');
        self::assertSame('row-media', $media[0]['id']);
        self::assertSame('Images', $media[0]['label']);
        self::assertSame(5, $media[0]['sort_order']);
    }

    /**
     * A declaration is a second statement of a permission the policy already
     * owns, so it must agree with it. Two failures matter: a permission no role
     * can hold (a dead item), and a permission the middleware does not ask for
     * (an item that 403s or, worse, promises a gate that is not there).
     */
    #[Test]
    public function everyDeclaredPermissionIsTheOneThePolicyAsksFor(): void
    {
        $declared = CardboardNavRegistry::all();

        self::assertNotSame([], $declared, 'no #[AsCardboardNavItem] was found on disk, so this test proves nothing.');

        $drift = [];

        foreach ($declared as $item) {
            $permission = $item['permission'];

            if (!is_string($permission) || !in_array($permission, Permissions::all(), true)) {
                $drift[] = sprintf('  %-16s %s is not a Permissions constant', $item['label'], var_export($permission, true));

                continue;
            }

            $owned = MarkRoutePolicy::requiredPermission($item['href']);

            if (null !== $owned && $owned !== $permission) {
                $drift[] = sprintf('  %-16s declares %s, policy asks %s', $item['label'], $permission, $owned);
            }
        }

        self::assertSame([], $drift, "A nav declaration disagrees with the policy that gates it:\n" . implode("\n", $drift));
    }

    /**
     * The MetricRegistry lesson, one directory over: a registry that drops a
     * declaration fails silently, and the missing item reports nothing. Every
     * attribute on disk must be in `all()`.
     */
    #[Test]
    public function everyDeclarationOnDiskIsRegistered(): void
    {
        $files = glob(APP_BASE_PATH . '/src/Modules/*/Application/Nav/*.php') ?: [];

        self::assertNotSame([], $files, 'the scan path found no nav declarations, so this test proves nothing.');

        $onDisk = 0;
        foreach ($files as $file) {
            $onDisk += substr_count((string) file_get_contents($file), 'AsCardboardNavItem(');
        }

        self::assertSame(
            $onDisk,
            count(CardboardNavRegistry::all()),
            'a declaration exists on disk and is not in CardboardNavRegistry::all() — its menu item is missing and nothing says so.',
        );
    }

    /**
     * The settings screen must show a declared item that has no row.
     *
     * Without it the sidebar carries a link the screen cannot account for, and
     * the operator's only move — adding a row for that href — is invisible.
     */
    #[Test]
    public function theSettingsScreenListsDeclaredItemsWithNoRow(): void
    {
        $this->clearPanelMenu();

        $nav = CardboardServices::panelNav();
        $declared = $nav->declaredItems();

        self::assertNotSame([], $declared, 'no declaration was found, so this test proves nothing.');

        $html = SettingsAction::declaredNavTable($declared, $nav->storedItems());

        self::assertStringContainsString('Declared in code', $html);
        self::assertStringContainsString(CardboardLayout::e($declared[0]['href']), $html);
        self::assertSame(
            count($declared),
            substr_count($html, 'Code only'),
            'with no stored rows, every declaration is listed as code-only',
        );

        $this->insertItem('row-declared', 'Renamed', $declared[0]['href'], null, 1);

        $html = SettingsAction::declaredNavTable($declared, $nav->storedItems());

        self::assertSame(1, substr_count($html, 'Overridden by a row'));
        self::assertSame(count($declared) - 1, substr_count($html, 'Code only'));
    }

    #[Test]
    public function thePreRenamePanelLocationIsMovedToMarkOnMigrate(): void
    {
        $pdo = new PDO('sqlite:' . $this->tmpDir . '/config.db');
        $pdo->exec(
            "INSERT INTO menu_items (id, label, href, sort_order, location, is_active)
             VALUES ('legacy-panel-item', 'Legacy', '/mark/legacy', 5, 'admin', 1)",
        );

        (new SettingsDatabaseMigrator(new PDO('sqlite:' . $this->tmpDir . '/config.db')))
            ->run($this->quietOutput());

        self::assertSame(
            ['mark'],
            $pdo->query("SELECT location FROM menu_items WHERE id = 'legacy-panel-item'")->fetchAll(PDO::FETCH_COLUMN),
            'The row moved in place and kept its id.',
        );
        self::assertSame(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM menu_items WHERE location = 'admin'")->fetchColumn(),
            'No row keeps the pre-rename location.',
        );
    }

    /**
     * Every file in an `Application/Nav` directory is one the registry can read.
     *
     * The scan is a text match on `AsCardboardNavItem` plus an FQCN resolve, and
     * a file that misses either answers "no items" — the same silence a module
     * with no declarations produces. `RestPanelNavItems` shipped with the
     * pre-rename namespace and attribute and removed its own sidebar link that
     * way, and every test still passed. This asserts what the scan assumes
     * instead of trusting it.
     */
    #[Test]
    public function everyNavDeclarationFileIsOneTheRegistryCanRead(): void
    {
        $files = glob(APP_BASE_PATH . '/src/Modules/*/Application/Nav/*.php');

        self::assertIsArray($files);
        self::assertNotSame([], $files, 'The installed tree declares at least one nav entry.');

        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            $module = basename(dirname($file, 3));

            self::assertStringContainsString(
                'AsCardboardNavItem',
                $content,
                sprintf('%s spells the attribute the registry scans for.', $file),
            );
            self::assertStringContainsString(
                sprintf('namespace Modules\\%s\\Application\\Nav;', $module),
                $content,
                sprintf('%s sits in the namespace of its own module, so the scan can resolve its class.', $file),
            );
        }
    }

    /**
     * Hiding a parent hides its children.
     *
     * The panel-nav screen's Vis column is one control per row, and a row that
     * is off has to take its subtree with it. Before this the child stayed
     * active, `buildTree()` found no parent and rendered it at the top level —
     * the operator hid "Blog" and the sidebar grew three loose links instead.
     */
    #[Test]
    public function aHiddenParentTakesItsChildrenWithIt(): void
    {
        $this->clearPanelMenu();
        $this->insertItem('group-writing', 'Writing', '/mark/articles', null, 10);
        $this->insertItem('item-articles', 'Articles', '/mark/articles', 'group-writing', 11);
        $this->insertItem('item-tags', 'Tags', '/mark/tags', 'group-writing', 12);

        $nav = CardboardServices::panelNav();

        self::assertContains('item-articles', array_column($nav->activeItems(), 'id'));

        $this->connection
            ->prepare("UPDATE menu_items SET is_active = 0 WHERE id = 'group-writing'")
            ->execute();

        $ids = array_column($nav->activeItems(), 'id');

        self::assertNotContains('group-writing', $ids);
        self::assertNotContains('item-articles', $ids, 'a child of a hidden parent is hidden with it, not promoted to the top level');
        self::assertNotContains('item-tags', $ids);
    }

    /**
     * A third level survives the tree assembly.
     *
     * `buildTree()` used to write each child's snapshot into its parent, and a
     * snapshot taken before the child's own children were attached is a child
     * with nothing under it — so a nested group rendered as a plain link and
     * its contents vanished. Two levels looked right, which is why the smoke
     * passed; the depth the bug actually broke is the one below.
     */
    #[Test]
    public function aThirdLevelSurvivesTheTreeAssembly(): void
    {
        $this->clearPanelMenu();
        $this->insertItem('row-outer', 'Outer', 'https://example.test/outer', null, 10);
        $this->insertItem('row-middle', 'Middle', 'https://example.test/middle', 'row-outer', 11);
        $this->insertItem('row-inner', 'Inner', 'https://example.test/inner', 'row-middle', 12);

        $outer = $this->findItem(CardboardServices::panelNav()->hierarchicalItems(), 'row-outer');

        self::assertNotNull($outer, 'the outermost row is a root');
        self::assertSame(['row-middle'], array_column($outer['children'], 'id'));
        self::assertSame(['row-inner'], array_column($outer['children'][0]['children'], 'id'));
    }

    /**
     * Every ancestor of the active item is expanded, not just its parent.
     *
     * The flag was applied to top-level items only, so a nested group whose
     * child was the page being viewed rendered closed — the sidebar offered a
     * branch and hid the way in.
     */
    #[Test]
    public function everyAncestorOfTheActiveItemExpands(): void
    {
        $this->clearPanelMenu();
        $this->insertItem('deep-outer', 'Outer', 'https://example.test/outer', null, 10);
        $this->insertItem('deep-middle', 'Middle', 'https://example.test/middle', 'deep-outer', 11);
        $this->insertItem('deep-inner', 'Inner', 'https://example.test/inner', 'deep-middle', 12);

        $outer = $this->findItem(CardboardServices::panelNav()->hierarchicalItems('inner'), 'deep-outer');

        self::assertNotNull($outer);
        self::assertTrue($outer['is_expanded'], 'the grandparent of the active item is open');
        self::assertTrue($outer['children'][0]['is_expanded'], 'the parent of the active item is open too');
    }

    /**
     * A parent chain that loops renders nothing rather than recursing.
     *
     * The move control refuses a cycle, and this is the second line: rows can
     * be written by the add form and by anything that touches `menu_items`, and
     * neither may turn a request into an infinite walk.
     */
    #[Test]
    public function aCycleRendersNothingRatherThanRecursing(): void
    {
        $this->clearPanelMenu();
        $this->insertItem('cycle-a', 'A', 'https://example.test/a', null, 10);
        $this->insertItem('cycle-b', 'B', 'https://example.test/b', 'cycle-a', 11);

        // The add form cannot build this: `parent_item_id` is a foreign key, so
        // the second half of the loop is written the way a stale row or a
        // hand-made UPDATE would leave it.
        $this->connection
            ->prepare("UPDATE menu_items SET parent_item_id = 'cycle-b' WHERE id = 'cycle-a'")
            ->execute();

        $ids = array_column(CardboardServices::panelNav()->hierarchicalItems(), 'id');

        self::assertNotContains('cycle-a', $ids);
        self::assertNotContains('cycle-b', $ids, 'a row whose parent chain never reaches a root is not a root');
    }

    /**
     * A label hint is matched whatever its casing.
     *
     * `byLabel` is keyed on the lowercase form and the type says `string`, so
     * a caller spelling the label as the sidebar shows it ('Articles') used to
     * miss: the page rendered and the branch stayed shut, with nothing to see
     * in a log. Surrounding whitespace came from the same place — an untrimmed
     * query value is not a different page.
     */
    #[Test]
    public function aLabelHintMatchesWhateverItsCasing(): void
    {
        $this->clearPanelMenu();
        $this->insertItem('case-group', 'Writing', '/mark/articles', null, 10);
        $this->insertItem('case-child', 'Articles', '/mark/articles', 'case-group', 11);

        foreach (['articles', 'Articles', 'ARTICLES', ' Articles '] as $hint) {
            $group = $this->findItem(CardboardServices::panelNav()->hierarchicalItems($hint), 'case-group');

            self::assertNotNull($group, 'the group is a root');
            self::assertTrue($group['is_expanded'], sprintf('"%s" names the Articles page', $hint));
        }
    }

    /**
     * A label two rows share resolves to neither of them.
     *
     * The hint carries a name and the editor does not forbid two rows with the
     * same one; a name that fits both is not an answer. Left as "last row
     * wins", `sort_order` — a column an operator drags — decided which branch
     * opened, and a wrong branch that opens looks exactly like a right one.
     * The id still resolves, which is how a caller that knows the row keeps its
     * active state.
     */
    #[Test]
    public function aSharedLabelExpandsNeitherItem(): void
    {
        $this->clearPanelMenu();
        $this->insertItem('first-report', 'First', 'https://example.test/first', null, 10);
        $this->insertItem('second-report', 'Second', 'https://example.test/second', null, 20);
        $this->insertItem('first-child', 'Reports', 'https://example.test/first/reports', 'first-report', 10);
        $this->insertItem('second-child', 'Reports', 'https://example.test/second/reports', 'second-report', 10);

        $tree = CardboardServices::panelNav()->hierarchicalItems('Reports');
        $first = $this->findItem($tree, 'first-report');
        $second = $this->findItem($tree, 'second-report');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertFalse($first['is_expanded'], 'a shared label names no branch');
        self::assertFalse($second['is_expanded']);
        self::assertFalse($first['is_current'], 'and highlights neither row');
        self::assertFalse($second['is_current']);

        $byId = CardboardServices::panelNav()->hierarchicalItems('second-child');
        $first = $this->findItem($byId, 'first-report');
        $second = $this->findItem($byId, 'second-report');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertTrue($second['is_expanded'], 'the id names exactly one row');
        self::assertFalse($first['is_expanded']);

        // A group named by id is current itself, not merely open: the flag
        // marks the row the hint resolved to, and the two are different rows
        // when the hint names a child.
        $byGroupId = CardboardServices::panelNav()->hierarchicalItems('first-report');
        $first = $this->findItem($byGroupId, 'first-report');
        $second = $this->findItem($byGroupId, 'second-report');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertTrue($first['is_current']);
        self::assertFalse($second['is_current']);
    }

    /**
     * The editor can name the label a hint cannot tell apart.
     *
     * `indexItems()` resolves a shared label to nothing and, on its own, says
     * nothing: the editor accepted both rows, the sidebar stopped marking
     * either active, and no screen connected the two facts. The settings page
     * asks this question, and it is answered with the same key the resolution
     * uses — casing and surrounding space are not a different name.
     */
    #[Test]
    public function theEditorCanNameTheLabelsTwoRowsShare(): void
    {
        $this->clearPanelMenu();
        $this->insertItem('shared-a', 'Reports', 'https://example.test/a', null, 10);
        $this->insertItem('shared-b', ' reports ', 'https://example.test/b', null, 20);
        $this->insertItem('shared-unique', 'Unique', 'https://example.test/unique', null, 30);

        self::assertSame(['Reports'], CardboardServices::panelNav()->conflictingLabels());
    }

    /**
     * The editor can name a row the sidebar will hide.
     *
     * A row whose `/mark` href no GET route serves is dropped at render — the
     * right outcome, delivered silently: an operator saves a link and the
     * panel shows no trace of it. The editor asks the same question the
     * renderer asks, through the same closure, so the answer it prints is the
     * answer the sidebar applies. An external href is never reported: the
     * router is not the authority for somebody else's URL.
     */
    #[Test]
    public function theEditorCanNameALinkNoRouteServes(): void
    {
        $this->clearPanelMenu();
        $this->insertItem('ghost-row', 'Ghost', '/mark/ghost', null, 10);
        $this->insertItem('served-row', 'Served', '/mark/settings', null, 20);
        $this->insertItem('external-row', 'External', 'https://example.test/somewhere', null, 30);

        $unserved = CardboardServices::panelNav()->unservedStoredItems(
            static fn (string $path): bool => '/mark/settings' === $path,
        );

        self::assertSame(['ghost-row'], array_column($unserved, 'id'));
        self::assertSame([], CardboardServices::panelNav()->unservedStoredItems(null), 'no router is no claim');
    }

    /**
     * The tree names the current row, so a renderer does not have to.
     *
     * `CardboardLayout` receives a hint and used to decide for itself by
     * comparing that hint against every label it rendered — the half of the
     * duplicate-name bug the service could not reach, because the service does
     * not render. The flag is the resolution's single answer, and it travels
     * with the row it belongs to.
     */
    #[Test]
    public function theTreeMarksOneCurrentItem(): void
    {
        $this->clearPanelMenu();
        $this->insertItem('live-leaf', 'Live leaf', 'https://example.test/leaf', null, 10);
        $this->insertItem('other-leaf', 'Other leaf', 'https://example.test/other', null, 20);

        $tree = CardboardServices::panelNav()->hierarchicalItems('Live leaf');
        $live = $this->findItem($tree, 'live-leaf');
        $other = $this->findItem($tree, 'other-leaf');

        self::assertNotNull($live);
        self::assertNotNull($other);
        self::assertTrue($live['is_current'], 'the resolved row carries the answer');
        self::assertFalse($other['is_current'], 'and no other row claims it');
    }

    /**
     * @param list<array<string, mixed>> $tree
     *
     * @return array<string, mixed>|null
     */
    private function findItem(array $tree, string $id): ?array
    {
        foreach ($tree as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        return null;
    }

    private function clearPanelMenu(): void
    {
        $this->connection->prepare("DELETE FROM menu_items WHERE location = 'mark'")->execute();
    }

    private function insertItem(string $id, string $label, string $href, ?string $parent, int $order): void
    {
        $this->connection
            ->prepare(
                'INSERT INTO menu_items (id, label, href, parent_item_id, sort_order, location, is_active)'
                . " VALUES (?, ?, ?, ?, ?, 'mark', 1)",
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
