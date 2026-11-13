<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Cardboard\Interfaces\HTTP\CardboardServices;
use Modules\PageCache\Application\Services\CacheInvalidator;
use View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;

/**
 * Roles + permissions management — task 9.3.
 *
 * Routes:
 * - GET  /mark/roles                     role list
 * - GET  /mark/roles/{id}/permissions    permission matrix for a role
 * - POST /mark/roles/{id}/permissions    save permission assignment
 * - GET  /mark/permissions               permission list
 * - POST /mark/roles/create              create role
 * - POST /mark/roles/{id}/delete         delete role
 *
 * Every security event the role and permission mutations emit rides the
 * Outbox when it is installed, behind a `class_exists` guard and never a `use`
 * statement: an import here puts Outbox back in this module's closure, and
 * Outbox imports Cardboard in turn (its metric provider does), so with
 * Security also on the path the graph is
 * `Cardboard → Security → Outbox → Cardboard` — a cycle no install order can
 * satisfy, measured on a fresh tree in part 07. Without the Outbox the
 * mutation still happens — only the event is missing, the trade the Users
 * module's own login and logout actions make.
 */
#[Route(path: '/mark/roles', method: 'GET', name: 'mark.roles')]
#[Route(path: '/mark/roles/create', method: 'POST', name: 'mark.roles.create', handlerMethod: 'createRole')]
#[Route(path: '/mark/roles/{id}/permissions', method: 'GET', name: 'mark.roles.permissions', handlerMethod: 'permissionsForm')]
#[Route(path: '/mark/roles/{id}/permissions', method: 'POST', name: 'mark.roles.permissions.save', handlerMethod: 'savePermissions')]
#[Route(path: '/mark/roles/{id}/delete', method: 'POST|DELETE', name: 'mark.roles.delete', handlerMethod: 'deleteRole')]
#[Route(path: '/mark/permissions', method: 'GET', name: 'mark.permissions', handlerMethod: 'permissionsList')]
final class RolesAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $search = $request->queryString('q');
        $rolesService = CardboardServices::roles();
        $allRoles = $rolesService->allRoles();

        // Filter by search query.
        if ('' !== $search) {
            $allRoles = $this->filterRoles($allRoles, $search);
        }

        $escapedSearch = AdminLayout::e($search);
        $searchInput = '<div class="m-filter-bar">'
            . '<input type="search" name="q" value="' . $escapedSearch . '" class="m-input--filter"'
            . ' hx-get="' . $this->url('mark.roles') . '"'
            . ' hx-trigger="keyup changed delay:300ms, search"'
            . ' hx-target="#roles-content"'
            . ' hx-swap="outerHTML"'
            . ' hx-indicator="#roles-skeleton"'
            . ' placeholder="Search roles...">'
            . '</div>';

        $grid = \Modules\Users\Interfaces\HTTP\RolesTableRenderer::render($allRoles, $this->router());

        // HTMX: return partial HTML.
        if (AdminLayout::isHtmx($request)) {
            return Response::html($grid);
        }

        $toast = '';
        $toastMessage = $request->queryString('toast');
        if ('' !== $toastMessage) {
            $toast = AdminLayout::toast(urldecode($toastMessage));
        }

        $csrf = AdminLayout::csrfField();
        $spinner = '<span class="htmx-indicator" id="role-spinner">⏳</span> ';

        $content = ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/roles-list.phtml', ['url' => $this->urlBuilder(), 'csrf' => $csrf, 'grid' => $grid, 'searchInput' => $searchInput, 'spinner' => $spinner, 'toast' => $toast]);

        return Response::html(AdminLayout::render(
            'Roles',
            $content,
            'roles',
            [['label' => 'Permissions', 'href' => $this->url('mark.permissions')]],
            router: $this->router(),
        ));
    }

    /**
     * @param list<array{id: string, name: string, description: string, created_at: string}> $roles
     *
     * @return list<array{id: string, name: string, description: string, created_at: string}>
     */
    private function filterRoles(array $roles, string $search): array
    {
        $query = strtolower($search);

        return array_values(array_filter(
            $roles,
            static fn (array $role): bool => str_contains(strtolower((string) $role['name']), $query)
                || str_contains(strtolower((string) $role['description']), $query),
        ));
    }

    public function createRole(Request $request): Response
    {
        $name = trim($request->bodyString('name'));
        $description = trim($request->bodyString('description'));

        if ('' !== $name && null === CardboardServices::roles()->findByName($name)) {
            $id = bin2hex(random_bytes(16));
            CardboardServices::roles()->createRole($id, $name, $description);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('roles.create', $name, 'Created role ' . $name, $request->getClientIp());
            // A role is an access-control object, so its creation is a
            // security event and not just an admin write. The inline audit row
            // above is the non-atomic fallback; this one rides the outbox so
            // the record survives a crash between the two databases.
            if (class_exists('Modules\\Outbox\\Interfaces\\HTTP\\SecurityEventServices')) {
                $events = \Modules\Outbox\Interfaces\HTTP\SecurityEventServices::class;
                $events::roleChanged($id, $events::OP_CREATE, $request->getClientIp(), $this->eventContext($name));
            }
        }

        if (AdminLayout::isHtmx($request)) {
            $response = Response::html('');
            $response->setHeader('HX-Redirect', $this->url('mark.roles') . '?toast=Role+created+successfully');

            return $response;
        }

        return Response::redirect($this->url('mark.roles'));
    }

    public function deleteRole(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null !== $id) {
            $role = $this->findRole($id);

            // Never allow deleting the built-in admin role.
            if (null !== $role && 'admin' !== $role['name']) {
                CardboardServices::roles()->deleteRole($id);
                CacheInvalidator::flush();
                \Modules\Audit\Interfaces\HTTP\AuditServices::log('roles.delete', $role['name'], 'Deleted role ' . $role['name'], $request->getClientIp());
                if (class_exists('Modules\\Outbox\\Interfaces\\HTTP\\SecurityEventServices')) {
                    $events = \Modules\Outbox\Interfaces\HTTP\SecurityEventServices::class;
                    $events::roleChanged($id, $events::OP_DELETE, $request->getClientIp(), $this->eventContext((string) $role['name']));
                }
            }
        }

        if (AdminLayout::isHtmx($request)) {
            return Response::html('');
        }

        return Response::redirect($this->url('mark.roles'));
    }

    public function permissionsForm(Request $request): Response
    {
        $id = $request->routeParam('id');
        $roles = CardboardServices::roles();

        if (null === $id) {
            return Response::redirect($this->url('mark.roles'));
        }

        $role = $this->findRole($id);
        if (null === $role) {
            return Response::redirect($this->url('mark.roles'));
        }

        $assigned = $roles->permissionIdsForRole($id);
        $csrf = AdminLayout::csrfField();
        $groupId = (string) $role['id'];

        $grouped = [];
        foreach ($roles->allPermissions() as $permission) {
            $group = $permission['group_name'];
            $grouped[$group][] = $permission;
        }

        $sections = '';
        foreach ($grouped as $group => $permissions) {
            $checks = '';
            foreach ($permissions as $permission) {
                $pid = AdminLayout::e((string) $permission['id']);
                $pname = AdminLayout::e((string) $permission['name']);
                $pdesc = AdminLayout::e($permission['description']);
                $checked = in_array((string) $permission['id'], $assigned, true) ? ' checked' : '';

                $checks .= "<label><input type=\"checkbox\" name=\"permissions[]\" value=\"{$pid}\"{$checked}> {$pname} <span class=\"m-muted\">— {$pdesc}</span></label>";
            }

            $sections .= '<h3 class="m-heading m-heading--sm">' . AdminLayout::e($group) . "</h3><div class=\"cba-checkgrid\">{$checks}</div>";
        }

        $content = ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/roles-permissions.phtml', ['url' => $this->urlBuilder(), 'csrf' => $csrf, 'groupId' => $groupId, 'sections' => $sections]);

        return Response::html(AdminLayout::render(
            'Permissions — ' . (string) $role['name'],
            $content,
            'roles',
            router: $this->router(),
        ));
    }

    public function savePermissions(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null === $id) {
            return Response::redirect($this->url('mark.roles'));
        }

        $submitted = $request->body('permissions');
        /** @var list<string> $wanted */
        $wanted = is_array($submitted)
            ? array_values(array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $submitted))
            : [];

        $roles = CardboardServices::roles();
        $roles->syncPermissions($id, $wanted);

        $role = $this->findRole($id);
        CacheInvalidator::flush();
        \Modules\Audit\Interfaces\HTTP\AuditServices::log(
            'roles.permissions',
            null === $role ? $id : $role['name'],
            'Saved ' . count($wanted) . ' permissions' . (null === $role ? '' : ' for ' . $role['name']),
            $request->getClientIp(),
        );

        // Granting permissions to a role is the most consequential write in
        // the panel — it is how someone acquires access to everything the role
        // names. It is the reason `security.role.changed` exists, and the
        // reason it must not rely on the inline audit row alone.
        if (class_exists('Modules\\Outbox\\Interfaces\\HTTP\\SecurityEventServices')) {
            $events = \Modules\Outbox\Interfaces\HTTP\SecurityEventServices::class;
            $events::roleChanged(
                $id,
                $events::OP_PERMISSIONS,
                $request->getClientIp(),
                $this->eventContext(null === $role ? $id : (string) $role['name']),
            );
        }

        return Response::redirect($this->url('mark.roles'));
    }

    public function permissionsList(Request $request): Response
    {
        $rows = '';

        foreach (CardboardServices::roles()->allPermissions() as $permission) {
            $name = AdminLayout::e((string) $permission['name']);
            $description = AdminLayout::e($permission['description']);
            $group = AdminLayout::e($permission['group_name']);

            $rows .= "<tr><td><span class=\"cba-badge cba-badge--ok\">{$group}</span></td><td><code>{$name}</code></td><td>{$description}</td></tr>";
        }

        $content = ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/roles-form.phtml', ['rows' => $rows]);

        return Response::html(AdminLayout::render(
            'Permissions',
            $content,
            'roles',
            router: $this->router(),
        ));
    }

    /**
     * Attribution for the Outbox copy of a role security event.
     *
     * The payload's `roleId` is the affected role, not the administrator, so
     * the actor travels in metadata. Without it the Audit handler fell back to
     * 'system' for role events and to the target id for user events.
     *
     * @return array{actor_id: string, actor_name: string, target: string}
     */
    private function eventContext(string $target): array
    {
        $actor = CardboardServices::auth()->currentUser();

        return [
            'actor_id'   => null === $actor ? '' : $actor->id(),
            'actor_name' => null === $actor ? '' : $actor->name(),
            'target'     => $target,
        ];
    }

    /**
     * @return array{id: string, name: string, description: string, created_at: string}|null
     */
    private function findRole(string $id): ?array
    {
        foreach (CardboardServices::roles()->allRoles() as $role) {
            if ((string) $role['id'] === $id) {
                return $role;
            }
        }

        return null;
    }
}
