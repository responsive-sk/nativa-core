<?php

declare(strict_types = 1);

namespace Modules\Users\Application\Services;

use Modules\Cardboard\Domain\Model\Card;
use Modules\Cardboard\Domain\Model\CardLink;
use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Domain\Contracts\MetricContract;
use Modules\Cardboard\Infrastructure\Attributes\AsMetricProvider;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Users\Infrastructure\Persistence\Repositories\RoleRepository;

/**
 * RolesMetric — aggregated roles/permissions metric cards for the dashboard.
 *
 * Registers 2 cards:
 * 1. Roles — total count + action links
 * 2. Permissions — total count + action link
 *
 * Data sources: users.db → roles, permissions.
 */
#[AsMetricProvider(priority: 95)]
final readonly class RolesMetric implements MetricContract
{
    public function registerCards(Period $period): void
    {
        try {
            $repo = new RoleRepository(
                DatabaseConnectionManager::instance()->connect('users'),
            );

            $this->registerRolesCard($repo);
            $this->registerPermissionsCard($repo);
        } catch (\Throwable) {
            // Database not ready — skip silently.
            // @mago-expect lint:no-empty-catch-clause
        }
    }

    private function registerRolesCard(RoleRepository $repo): void
    {
        $roles = $repo->allRoles();

        CardboardRegistry::register(
            Card::make('roles', 'Roles', count($roles))
                ->withActions(
                    CardLink::make('List', '/mark/roles'),
                    CardLink::make('Login', '/login'),
                )
                ->withPriority(95),
        );
    }

    private function registerPermissionsCard(RoleRepository $repo): void
    {
        $permissions = $repo->allPermissions();

        CardboardRegistry::register(
            Card::make('permissions', 'Permissions', count($permissions))
                ->withActions(
                    CardLink::make('Manage', '/mark/roles'),
                )
                ->withPriority(85),
        );
    }
}
