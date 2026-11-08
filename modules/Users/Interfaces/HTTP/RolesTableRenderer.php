<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\HTTP;

use Modules\Cardboard\Application\Services\BulkActions;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Kernel\Contracts\RouterInterface;

/**
 * RolesTableRenderer — renders the Roles admin table (thead + rows + bulk bar).
 *
 * Shared between RolesAction (list view) and BulkDeleteAction (post-delete re-render).
 */
final class RolesTableRenderer
{
    /**
     * Render the complete roles table with bulk actions.
     *
     * @param list<array{id: int|string, name: string, description: string}> $roles
     */
    public static function render(array $roles, RouterInterface $router): string
    {
        $rows = '';

        foreach ($roles as $role) {
            $rows .= self::row($role, $router);
        }

        $bulkBar = BulkActions::actionBar('roles', $router->url('mark.bulk-delete'));

        $skeleton = AdminLayout::skeletonTable([
            ['60%'], ['10%'],
        ]);

        return $bulkBar
            . '<div id="roles-content">'
            . '<div id="roles-skeleton" class="htmx-indicator">' . $skeleton . '</div>'
            . '<div class="m-table-scroll"><table class="m-table"><thead><tr>'
            . BulkActions::headerCell()
            . '<th>Role</th><th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '</div>';
    }

    /**
     * @param array{id: int|string, name: string, description: string} $role
     */
    private static function row(array $role, RouterInterface $router): string
    {
        $id = AdminLayout::e((string) $role['id']);
        $name = AdminLayout::e((string) $role['name']);
        $description = AdminLayout::e($role['description']);
        $checkbox = BulkActions::checkboxCell((string) $role['id']);
        $iconPerms = AdminLayout::iconPermissions();
        $iconDelete = AdminLayout::iconDelete();
        $csrf = AdminLayout::csrfField();

        return '<tr>'
            . $checkbox
            . '<td><strong>' . $name . '</strong><br><span class="m-muted">' . $description . '</span></td>'
            . '<td>'
            . '<div class="m-btn-group">'
            . '<a class="m-btn m-btn-sm m-btn-icon"'
            . ' href="' . AdminLayout::e($router->url('mark.roles.permissions', ['id' => $id])) . '"'
            . ' data-tooltip="Permissions" title="Permissions">' . $iconPerms . '</a>'
            . '<form method="POST" action="' . AdminLayout::e($router->url('mark.roles.delete', ['id' => $id])) . '"'
            . ' hx-delete="' . AdminLayout::e($router->url('mark.roles.delete', ['id' => $id])) . '"'
            . ' data-confirm="Delete this role?"'
            . ' hx-target="closest tr"'
            . ' hx-swap="outerHTML swap:500ms"'
            . ' class="m-inline-form">'
            . $csrf
            . '<button class="m-btn m-btn-error m-btn-sm m-btn-icon"'
            . ' data-tooltip="Delete role" title="Delete">' . $iconDelete . '</button>'
            . '</form>'
            . '</div>'
            . '</td>'
            . '</tr>';
    }
}
