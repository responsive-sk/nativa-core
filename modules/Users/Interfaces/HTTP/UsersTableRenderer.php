<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\HTTP;

use Modules\Cardboard\Application\Services\BulkActions;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Users\Domain\Entities\User;

/**
 * UsersTableRenderer — renders the Users admin table (thead + rows + bulk bar).
 *
 * Shared between UsersAction (list view) and BulkDeleteAction (post-delete re-render).
 */
final class UsersTableRenderer
{
    /**
     * Render the complete users table with bulk actions.
     *
     * @param list<User> $users
     */
    public static function render(array $users, RouterInterface $router): string
    {
        $rows = '';

        foreach ($users as $user) {
            $rows .= self::row($user, $router);
        }

        $bulkBar = BulkActions::actionBar('users', $router->url('mark.bulk-delete'));

        $skeleton = AdminLayout::skeletonTable([
            ['30%'], ['50%'], ['40%'], ['20%'], ['10%'],
        ]);

        return $bulkBar
            . '<div id="users-content">'
            . '<div id="users-skeleton" class="htmx-indicator">' . $skeleton . '</div>'
            . '<div class="m-table-scroll"><table class="m-table"><thead><tr>'
            . BulkActions::headerCell()
            . '<th>Name</th><th>Email</th><th>Roles</th><th>Status</th><th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '</div>';
    }

    private static function row(User $user, RouterInterface $router): string
    {
        $id = AdminLayout::e($user->id());
        $name = AdminLayout::e($user->name());
        $email = AdminLayout::e($user->email());
        $roles = implode(', ', array_map(AdminLayout::e(...), $user->roles()));
        $badge = $user->isActive()
            ? '<span class="m-badge m-badge-success">Active</span>'
            : '<span class="m-badge m-badge-error">Disabled</span>';
        $csrf = AdminLayout::csrfField();
        $checkbox = BulkActions::checkboxCell($user->id());
        $iconEdit = AdminLayout::iconEdit();
        $iconDelete = AdminLayout::iconDelete();

        return '<tr>'
            . $checkbox
            . '<td>' . $name . '</td>'
            . '<td>' . $email . '</td>'
            . '<td>' . $roles . '</td>'
            . '<td>' . $badge . '</td>'
            . '<td>'
            . '<div class="m-btn-group">'
            . '<a class="m-btn m-btn-sm m-btn-icon" href="' . AdminLayout::e($router->url('mark.users.edit', ['id' => $id])) . '"'
            . ' data-tooltip="Edit user" title="Edit">' . $iconEdit . '</a>'
            . '<form method="POST" action="' . AdminLayout::e($router->url('mark.users.delete', ['id' => $id])) . '"'
            . ' hx-delete="' . AdminLayout::e($router->url('mark.users.delete', ['id' => $id])) . '"'
            . ' data-confirm="Delete this user?"'
            . ' hx-target="closest tr"'
            . ' hx-swap="outerHTML swap:500ms"'
            . ' class="m-inline-form">'
            . $csrf
            . '<button class="m-btn m-btn-error m-btn-sm m-btn-icon"'
            . ' data-tooltip="Delete user" title="Delete">' . $iconDelete . '</button>'
            . '</form>'
            . '</div>'
            . '</td>'
            . '</tr>';
    }
}
