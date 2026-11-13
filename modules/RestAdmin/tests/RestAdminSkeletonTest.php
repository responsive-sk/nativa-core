<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Tests;

use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Domain\Permissions;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\RestAdmin\Infrastructure\Persistence\Migrations\RestAdminDatabaseMigrator;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RestAdminSkeletonTest — F0 of the REST admin plan: the gate, the prefix and
 * the schema, before any service exists.
 *
 * The three failures worth pinning:
 *
 * 1. **The route falls under the `/mark` baseline.** `/mark/rest` must answer
 *    `settings.manage`, not `mark.access`; longest-prefix-wins is what makes
 *    the difference, and a row that never got added would still answer 200 for
 *    every author who may enter the panel.
 * 2. **A sibling prefix inherits the row.** `/mark/restful` is a different
 *    path; whole-segment matching has to keep it on the baseline, otherwise the
 *    new row silently widens the gate.
 * 3. **The migrator drifts from the store.** The four tables are the contract
 *    the spec layer writes through, and a missing one would surface as a
 *    "service list is empty" page rather than an error.
 *
 * Every module test carries the `MODULE` guard: PHPUnit runs the module suite
 * from the catalogue, so this file must skip cleanly on a kernel-only tree.
 */
final class RestAdminSkeletonTest extends TestCase
{
    private const string MODULE = 'RestAdmin';

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — its slice ships with the module.');
        }
    }

    /**
     * The panel's baseline is `mark.access`; this row must win because it is
     * longer, not because the order in the table happened to favour it.
     */
    #[Test]
    public function theRestPrefixIsAnAdminOnlySurface(): void
    {
        self::assertSame(
            Permissions::SETTINGS,
            AdminRoutePolicy::requiredPermission('/mark/rest'),
            'REST admin configures remote services and the token env they read, so it is admin-only.',
        );

        self::assertNotSame(
            Permissions::MARK_ACCESS,
            AdminRoutePolicy::requiredPermission('/mark/rest'),
            'Falling back to the panel baseline would let every author register a service.',
        );
    }

    /**
     * Longest prefix wins one level down, so a future child route does not need
     * its own row — and does not accidentally resolve to a content permission
     * because `/mark/rest/...` happens to start with `/mark`.
     */
    #[Test]
    public function aChildRouteKeepsTheSamePermission(): void
    {
        self::assertSame(
            Permissions::SETTINGS,
            AdminRoutePolicy::requiredPermission('/mark/rest/services/blog/items'),
        );
    }

    /**
     * The new row must not become a prefix for a word that merely starts with
     * it: `/mark/restful` is not `/mark/rest`.
     */
    #[Test]
    public function aSiblingWordDoesNotInheritTheRow(): void
    {
        self::assertSame(
            Permissions::MARK_ACCESS,
            AdminRoutePolicy::requiredPermission('/mark/restful'),
        );
    }

    /**
     * The gate is a permission check, not a role name: a role that holds
     * `settings.manage` passes, and an author with only `content.manage` does
     * not — even though both may enter the panel.
     */
    #[Test]
    public function onlySettingsHoldersMayOpenTheRestPanel(): void
    {
        $settingsHolder = $this->userWith([Permissions::SETTINGS]);
        $author = $this->userWith([Permissions::MARK_ACCESS, Permissions::CONTENT]);

        self::assertTrue(AdminRoutePolicy::permits('/mark/rest', $settingsHolder));
        self::assertFalse(AdminRoutePolicy::permits('/mark/rest', $author));
    }

    #[Test]
    public function theMigratorCreatesTheFourSpecTables(): void
    {
        $pdo = new PDO('sqlite::memory:');

        (new RestAdminDatabaseMigrator($pdo))->run($this->quietOutput());

        $tables = $pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);

        foreach (['fields', 'relations', 'resources', 'services'] as $table) {
            self::assertContains($table, $tables, 'the spec store writes through this table');
        }
    }

    /**
     * @param list<string> $permissions
     */
    private function userWith(array $permissions): object
    {
        return new class ($permissions) {
            /**
             * @param list<string> $permissions
             */
            public function __construct(private readonly array $permissions)
            {
            }

            public function hasPermission(string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }
        };
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
