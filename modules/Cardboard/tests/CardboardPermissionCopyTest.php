<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Application\Services\AdminNavService;
use Modules\Cardboard\Application\Services\BulkEntityGate;
use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Domain\Permissions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * CardboardPermissionCopyTest — the leaf keeps its copy, and the copy stays
 * true to the owner.
 *
 * Cardboard deliberately holds two local copies of Users' permission
 * vocabulary: `AdminNavService::permissionTable()` (17 prefixes, so the admin
 * nav never offers a link the policy will refuse) and
 * `BulkEntityGate::permissionForEntity()` (4 entities, because the POST body
 * names what a bulk route acts on). Both exist to keep zero
 * `use Modules\<Feature>` lines, which is what lets the install-closure
 * scanner treat Cardboard as a leaf. Consolidating them into one more
 * constant would be a third declaration, and the audit forbids exactly that.
 *
 * So the copy stays and this test guards it instead:
 *
 *   - every prefix `AdminRoutePolicy` classifies has a row in the nav copy,
 *     no row points at a path the policy does not classify, and each row's
 *     permission is the owner's answer;
 *   - every value is a name `Permissions::all()` owns — a typo here is a nav
 *     item nobody can ever see, not a compile error;
 *   - every entity `AdminRoutePolicy::bulkEntities()` knows gets the owner's
 *     permission, and an unknown entity still fails closed.
 *
 * The tables are private and stay private: reflection is how the guard reads
 * them, the same way `UsersValidationTest` reaches a private action method.
 * No constant is declared here, so the name-ownership ratchet does not move.
 */
final class CardboardPermissionCopyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Cardboard')) {
            $this->markTestSkipped('Cardboard is not installed — its permission copies ship with the module.');
        }
    }

    /**
     * A policy prefix the copy has not learned would fall through to a shorter
     * row (or to null, a 403 on a configured nav item); a row the policy does
     * not classify would hide a link for no reason. Both directions matter.
     */
    #[Test]
    public function theNavCopyClassifiesExactlyThePrefixesThePolicyDoes(): void
    {
        $local = $this->localNavTable();
        $owned = AdminRoutePolicy::prefixes();

        $missing = array_values(array_diff($owned, array_keys($local)));
        $extra = array_values(array_diff(array_keys($local), $owned));

        self::assertSame(
            [],
            $missing,
            "these prefixes are classified by AdminRoutePolicy but missing from Cardboard's nav copy:\n  "
            . implode("\n  ", $missing),
        );

        self::assertSame(
            [],
            $extra,
            "Cardboard's nav copy gates paths no AdminRoutePolicy row classifies:\n  "
            . implode("\n  ", $extra),
        );
    }

    #[Test]
    public function everyNavRowAnswersWithThePolicysPermission(): void
    {
        $drift = [];

        foreach ($this->localNavTable() as $prefix => $permission) {
            $owned = AdminRoutePolicy::requiredPermission($prefix);

            if ($owned !== $permission) {
                $drift[] = sprintf(
                    '  %-24s copy=%s owner=%s',
                    $prefix,
                    var_export($permission, true),
                    var_export($owned, true),
                );
            }
        }

        self::assertSame(
            [],
            $drift,
            "Cardboard's nav copy answers differently from AdminRoutePolicy:\n"
            . implode("\n", $drift)
            . "\n\nThe copy is deliberate — Cardboard stays a leaf — but it must move"
            . ' when the owner moves. Fix the row, do not add a third permission name.',
        );
    }

    #[Test]
    public function theNavCopyUsesOnlyNamesPermissionsOwns(): void
    {
        $unknown = array_values(array_diff(
            array_unique(array_values($this->localNavTable())),
            Permissions::all(),
        ));

        self::assertSame(
            [],
            $unknown,
            "Cardboard's nav copy uses permission names Permissions does not own:\n  "
            . implode("\n  ", $unknown)
            . "\nA role can never be granted such a name, so the matching nav item is dead.",
        );
    }

    #[Test]
    public function theBulkCopyClassifiesEveryEntityThePolicyKnows(): void
    {
        $method = new ReflectionMethod(BulkEntityGate::class, 'permissionForEntity');
        $drift = [];

        foreach (AdminRoutePolicy::bulkEntities() as $entity) {
            $copied = $method->invoke(null, $entity);
            $owned = AdminRoutePolicy::permissionForEntity($entity);

            if ($copied !== $owned) {
                $drift[] = sprintf(
                    '  %-12s copy=%s owner=%s',
                    $entity,
                    var_export($copied, true),
                    var_export($owned, true),
                );
            }
        }

        self::assertSame(
            [],
            $drift,
            "Cardboard's bulk entity gate differs from AdminRoutePolicy::permissionForEntity():\n"
            . implode("\n", $drift),
        );

        self::assertNull(
            $method->invoke(null, 'no-such-entity'),
            'An unknown bulk entity must fail closed; a null permission is the gate that does it.',
        );
    }

    /**
     * The nav copy is private on purpose — it is Cardboard's implementation
     * detail, not an API other modules may import.
     *
     * @return array<string, string>
     */
    private function localNavTable(): array
    {
        $method = new ReflectionMethod(AdminNavService::class, 'permissionTable');
        $table = $method->invoke(null);

        self::assertIsArray($table);

        /** @var array<string, string> $table */
        return $table;
    }
}
