<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Application\Services\BulkEntityGate;
use Modules\Core\Domain\MarkRoutePolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * CardboardPermissionCopyTest — the one copy left, and it stays true to the
 * owner.
 *
 * Cardboard used to hold two local copies of the permission vocabulary:
 * `CardboardNavService::permissionTable()` (a copy of `MarkRoutePolicy`'s prefix
 * table) and `BulkEntityGate::permissionForEntity()` (4 entities, because the
 * POST body names what a bulk route acts on). Both existed to keep zero
 * `use Modules\<Feature>` lines, which is what lets the install-closure scanner
 * treat Cardboard as a leaf.
 *
 * The nav copy is gone: `MarkRoutePolicy` moved to Core on 2026-10-17, and
 * Cardboard imports it the way it already imported `Permissions`, so the nav
 * asks the owner instead of copying it. The bulk gate cannot follow — a bulk
 * *entity* name is not an admin path, so the policy's prefix table has no row
 * to consult — and this test guards what is left:
 *
 *   - every entity `MarkRoutePolicy::bulkEntities()` knows gets the owner's
 *     permission, and an unknown entity still fails closed.
 *
 * The table is private and stays private: reflection is how the guard reads
 * it, the same way `UsersValidationTest` reaches a private action method. No
 * constant is declared here, so the name-ownership ratchet does not move.
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

    #[Test]
    public function theBulkCopyClassifiesEveryEntityThePolicyKnows(): void
    {
        $method = new ReflectionMethod(BulkEntityGate::class, 'permissionForEntity');
        $drift = [];

        foreach (MarkRoutePolicy::bulkEntities() as $entity) {
            $copied = $method->invoke(null, $entity);
            $owned = MarkRoutePolicy::permissionForEntity($entity);

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
            "Cardboard's bulk entity gate differs from MarkRoutePolicy::permissionForEntity():\n"
            . implode("\n", $drift),
        );

        self::assertNull(
            $method->invoke(null, 'no-such-entity'),
            'An unknown bulk entity must fail closed; a null permission is the gate that does it.',
        );
    }
}
