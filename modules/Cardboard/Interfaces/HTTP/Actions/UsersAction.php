<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Cardboard\Interfaces\HTTP\CardboardServices;
use Modules\PageCache\Application\Services\CacheInvalidator;
use View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Uploads\Infrastructure\Storage\LocalStorageProvider;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Security\Domain\PasswordPolicy;
use Modules\Validation\Validation\Validator;

/**
 * Users CRUD — task 9.2.
 *
 * Routes:
 * - GET  /mark/users             list
 * - GET  /mark/users/create      create form
 * - POST /mark/users/create      create submit
 * - GET  /mark/users/{id}/edit   edit form
 * - POST /mark/users/{id}/edit   edit submit
 * - POST /mark/users/{id}/delete delete
 *
 * Avatar selection: both forms pick an existing image from the Media
 * library (the same picker the article editor uses); the chosen URL persists
 * into users.avatar and the edit form can also remove it. The media asset is
 * shared, so changing or clearing an avatar never deletes the file. The
 * helper is named fully-qualified inline, not imported: a `use` line would
 * put Cardboard back in Media's closure while Media already imports
 * Cardboard, and `module:add` would stall on that cycle.
 *
 * Every security event the user mutations emit rides the Outbox when it is
 * installed, behind a `class_exists` guard and never a `use` statement: an
 * import here puts Outbox back in this module's closure, and Outbox imports
 * Cardboard in turn (its metric provider does), so with Security also on the
 * path the graph is `Cardboard → Security → Outbox → Cardboard` — a cycle no
 * install order can satisfy, measured on a fresh tree in part 07. Without the
 * Outbox the mutation still happens — only the event is missing, the trade the
 * Users module's own login and logout actions make.
 */
#[Route(path: '/mark/users', method: 'GET', name: 'mark.users')]
#[Route(path: '/mark/users/create', method: 'GET', name: 'mark.users.create', handlerMethod: 'createForm')]
#[Route(path: '/mark/users/create', method: 'POST', name: 'mark.users.create.submit', handlerMethod: 'createSubmit')]
#[Route(path: '/mark/users/{id}/edit', method: 'GET', name: 'mark.users.edit', handlerMethod: 'editForm')]
#[Route(path: '/mark/users/{id}/edit', method: 'POST', name: 'mark.users.edit.submit', handlerMethod: 'editSubmit')]
#[Route(path: '/mark/users/{id}/delete', method: 'POST|DELETE', name: 'mark.users.delete', handlerMethod: 'delete')]
final class UsersAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $search = $request->queryString('q');
        $users = CardboardServices::users()->all();

        // Filter by search query.
        if ('' !== $search) {
            $users = $this->filterUsers($users, $search);
        }

        $escapedSearch = AdminLayout::e($search);
        $searchInput = '<div class="m-filter-bar">'
            . '<input type="search" name="q" value="' . $escapedSearch . '" class="m-input--filter"'
            . ' hx-get="' . $this->url('mark.users') . '"'
            . ' hx-trigger="keyup changed delay:300ms, search"'
            . ' hx-target="#users-content"'
            . ' hx-swap="outerHTML"'
            . ' hx-indicator="#users-skeleton"'
            . ' placeholder="Search users...">'
            . '</div>';

        $grid = \Modules\Users\Interfaces\HTTP\UsersTableRenderer::render($users, $this->router());

        // HTMX: return partial HTML.
        if (AdminLayout::isHtmx($request)) {
            return Response::html($grid);
        }

        $toast = '';
        $toastMessage = $request->queryString('toast');
        if ('' !== $toastMessage) {
            $toast = AdminLayout::toast(urldecode($toastMessage));
        }

        $content = $searchInput . $grid . $toast;

        return Response::html(AdminLayout::render(
            'Users',
            $content,
            'users',
            [['label' => 'New user', 'href' => $this->url('mark.users.create')]],
            router: $this->router(),
        ));
    }

    /**
     * Filter users by name or email.
     *
     * @param list<\Modules\Users\Domain\Entities\User> $users
     *
     * @return list<\Modules\Users\Domain\Entities\User>
     */
    private function filterUsers(array $users, string $search): array
    {
        $query = strtolower($search);

        return array_values(array_filter(
            $users,
            static fn (\Modules\Users\Domain\Entities\User $user): bool => str_contains(strtolower($user->name()), $query)
                || str_contains(strtolower($user->email()), $query),
        ));
    }

    public function createForm(Request $request): Response
    {
        return Response::html($this->form(
            'New user',
            $this->url('mark.users.create'),
            'Create user',
            [],
            [],
            [],
            null,
            $request,
        ));
    }

    public function createSubmit(Request $request): Response
    {
        $name = $request->bodyString('name');
        $email = $request->bodyString('email');
        $password = $request->bodyString('password');
        $roleId = $request->bodyString('role');

        $errors = $this->createErrors($name, $email, $password, $roleId);
        if ([] !== $errors) {
            return $this->createFormResponse($errors, $name, $email, $request);
        }

        $users = CardboardServices::users();

        if ($users->emailExists($email)) {
            return $this->createFormResponse(['A user with this email already exists.'], $name, $email, $request);
        }

        // Avatar selection — attempted only after field validation and email
        // uniqueness passed; on error nothing is stored (path is null).
        $avatar = $this->avatarSelection($request);
        if ('' !== $avatar['error']) {
            return $this->createFormResponse([$avatar['error']], $name, $email, $request);
        }

        $hasher = new PasswordHasher();
        $user = $users->insert([
            'id'       => bin2hex(random_bytes(16)),
            'name'     => $name,
            'email'    => $email,
            'password' => $hasher->hash($password),
            'avatar'   => $avatar['path'],
        ]);

        if ('' !== $roleId) {
            $users->assignRole($user->id(), $roleId);
        }

        CacheInvalidator::flush();
        \Modules\Audit\Interfaces\HTTP\AuditServices::log('users.create', $user->email(), 'Created user ' . $email, $request->getClientIp());
        if (class_exists('Modules\\Outbox\\Interfaces\\HTTP\\SecurityEventServices')) {
            $events = \Modules\Outbox\Interfaces\HTTP\SecurityEventServices::class;
            $events::userChanged($user->id(), $events::OP_CREATE, $request->getClientIp(), $this->eventContext($user->email()));
        }

        if (AdminLayout::isHtmx($request)) {
            $response = Response::html('');
            $response->setHeader('HX-Redirect', $this->url('mark.users') . '?toast=User+created+successfully');

            return $response;
        }

        return Response::redirect($this->url('mark.users'));
    }

    public function editForm(Request $request): Response
    {
        $id = $request->routeParam('id');
        $user = null === $id ? null : CardboardServices::users()->findById($id);

        if (null === $user) {
            return Response::redirect($this->url('mark.users'));
        }

        return Response::html($this->form(
            'Edit user — ' . $user->name(),
            $this->url('mark.users.edit', ['id' => AdminLayout::e($user->id())]),
            'Save changes',
            [],
            ['name' => $user->name(), 'email' => $user->email()],
            CardboardServices::roles()->roleIdsForUser($user->id()),
            $user,
            $request,
        ));
    }

    public function editSubmit(Request $request): Response
    {
        $id = $request->routeParam('id');
        $users = CardboardServices::users();
        $user = null === $id ? null : $users->findById($id);

        if (null === $user) {
            return Response::redirect($this->url('mark.users'));
        }

        $name = $request->bodyString('name');
        $email = $request->bodyString('email');
        $password = $request->bodyString('password');
        $roleId = $request->bodyString('role');
        $isActive = in_array($request->body('is_active'), ['on', '1', 'true', true], true);

        $errors = $this->editErrors($user, $name, $email, $roleId, $users);
        if ([] !== $errors) {
            return $this->editFormResponse($user, $errors, $name, $email, $request);
        }

        // Avatar change (selection, removal, or none) — an invalid selection
        // keeps the DB untouched. The file belongs to the media library, so
        // nothing is deleted when the selection fails or the avatar changes.
        $state = $this->editAvatarState($errors, $request, $user->avatar());
        if ([] !== $state['errors']) {
            return $this->editFormResponse($user, $state['errors'], $name, $email, $request);
        }

        $users->update($user->id(), $name, $email, $isActive);
        $this->applyAvatarChange($user, $state['newAvatar'], $state['removeAvatar']);
        $this->syncRole($user->id(), $roleId);

        // Optional password change
        if ('' !== $password) {
            $users->updatePassword($user->id(), (new PasswordHasher())->hash($password));
        }

        CacheInvalidator::flush();
        \Modules\Audit\Interfaces\HTTP\AuditServices::log('users.update', $user->email(), 'Updated user ' . $email, $request->getClientIp());
        if (class_exists('Modules\\Outbox\\Interfaces\\HTTP\\SecurityEventServices')) {
            $events = \Modules\Outbox\Interfaces\HTTP\SecurityEventServices::class;
            $events::userChanged($user->id(), $events::OP_UPDATE, $request->getClientIp(), $this->eventContext($user->email()));
        }

        if (AdminLayout::isHtmx($request)) {
            $response = Response::html('');
            $response->setHeader('HX-Redirect', $this->url('mark.users') . '?toast=User+saved+successfully');

            return $response;
        }

        return Response::redirect($this->url('mark.users'));
    }

    public function delete(Request $request): Response
    {
        $id = $request->routeParam('id');
        $users = CardboardServices::users();

        if (null !== $id) {
            $user = $users->findById($id);

            // Never allow deleting yourself.
            if (null !== $user && $user->id() !== CardboardServices::auth()->currentUserId()) {
                $users->delete($id);
                CacheInvalidator::flush();
                \Modules\Audit\Interfaces\HTTP\AuditServices::log('users.delete', $user->email(), 'Deleted user ' . $user->email(), $request->getClientIp());
                if (class_exists('Modules\\Outbox\\Interfaces\\HTTP\\SecurityEventServices')) {
                    $events = \Modules\Outbox\Interfaces\HTTP\SecurityEventServices::class;
                    $events::userChanged($user->id(), $events::OP_DELETE, $request->getClientIp(), $this->eventContext($user->email()));
                }
            }
        }

        if (AdminLayout::isHtmx($request)) {
            return Response::html('');
        }

        return Response::redirect($this->url('mark.users'));
    }

    /**
     * Render the user form.
     *
     * @param list<string>          $errors
     * @param array<string, string> $values
     * @param list<string>          $selectedRoleIds
     */
    private function form(
        string $title,
        string $action,
        string $submitLabel,
        array $errors,
        array $values = [],
        array $selectedRoleIds = [],
        ?\Modules\Users\Domain\Entities\User $user = null,
        ?Request $request = null,
    ): string {
        $errorHtml = AdminLayout::messages($errors, 'err');
        $roles = CardboardServices::roles()->allRoles();
        $roleOptions = '';

        foreach ($roles as $role) {
            $roleId = AdminLayout::e((string) $role['id']);
            $roleName = AdminLayout::e((string) $role['name']);
            $selected = in_array((string) $role['id'], $selectedRoleIds, true) ? ' selected' : '';
            $roleOptions .= "<option value=\"{$roleId}\"{$selected}>{$roleName}</option>";
        }

        $name = AdminLayout::e($values['name'] ?? '');
        $email = AdminLayout::e($values['email'] ?? '');
        $activeChecked = (null === $user || $user->isActive()) ? ' checked' : '';
        $csrf = AdminLayout::csrfField();
        $passwordHint = null !== $user
            ? '<p class="m-muted m-hint">Leave blank to keep the current password.</p>'
            : '';

        // Avatar preview + removal checkbox. The picker fills the hidden
        // `avatar` field with a media URL; the preview uses the same IDs the
        // shared picker updates after a selection.
        $avatar = null !== $user ? $user->avatar() : null;
        $avatarPreview = $this->avatarPreview($avatar);
        $avatarValue = null !== $avatar ? AdminLayout::e($avatar) : '';
        $removeAvatar = null !== $avatar
            ? '<div class="m-check"><input type="checkbox" id="remove_avatar" name="remove_avatar" value="1"><label for="remove_avatar">Remove avatar</label></div>'
            : '';

        $htmx = null !== $request && AdminLayout::isHtmx($request);
        $hxPost = $htmx ? ' hx-post="' . AdminLayout::e($action) . '" hx-indicator="#user-spinner"' : '';
        $spinner = '<span class="htmx-indicator" id="user-spinner">⏳</span> ';

        $content = ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/users-form.phtml', ['avatarPreview' => $avatarPreview, 'avatarValue' => $avatarValue, 'url' => $this->urlBuilder(), 'action' => $action, 'activeChecked' => $activeChecked, 'csrf' => $csrf, 'email' => $email, 'errorHtml' => $errorHtml, 'hxPost' => $hxPost, 'name' => $name, 'passwordHint' => $passwordHint, 'removeAvatar' => $removeAvatar, 'roleOptions' => $roleOptions, 'spinner' => $spinner, 'submitLabel' => $submitLabel])
            . \Modules\Media\Interfaces\HTTP\MediaEditorHelper::editorScript($this->router(), null);

        return AdminLayout::render($title, $content, 'users', router: $this->router());
    }

    /**
     * @return list<string>
     */
    private function createErrors(string $name, string $email, #[\SensitiveParameter] string $password, string $roleId): array
    {
        $errors = $this->validate($name, $email, $password, true);

        // F-7: reject unknown role ids at submit time — assignRole() would
        // otherwise violate the user_roles foreign key after the insert.
        if ('' !== $roleId && !CardboardServices::roles()->roleExists($roleId)) {
            $errors[] = 'The selected role no longer exists.';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function editErrors(\Modules\Users\Domain\Entities\User $user, string $name, string $email, string $roleId, \Modules\Users\Infrastructure\Persistence\Repositories\UserRepository $users): array
    {
        $errors = $this->validate($name, $email, '', false);

        // Email uniqueness (ignoring self)
        if ([] === $errors && strtolower($email) !== strtolower($user->email())) {
            if ($users->emailExists($email)) {
                $errors[] = 'A user with this email already exists.';
            }
        }

        // F-7: reject unknown role ids at submit time — the role sync below
        // would otherwise violate the user_roles foreign key mid-way.
        if ('' !== $roleId && !CardboardServices::roles()->roleExists($roleId)) {
            $errors[] = 'The selected role no longer exists.';
        }

        return $errors;
    }

    /**
     * Re-render the create form with errors.
     *
     * @param list<string> $errors
     */
    private function createFormResponse(array $errors, string $name, string $email, ?Request $request = null): Response
    {
        return Response::html($this->form(
            'New user',
            $this->url('mark.users.create'),
            'Create user',
            $errors,
            ['name' => $name, 'email' => $email],
            [],
            null,
            $request,
        ));
    }

    /**
     * Re-render the edit form with errors.
     *
     * @param list<string> $errors
     */
    private function editFormResponse(\Modules\Users\Domain\Entities\User $user, array $errors, string $name, string $email, ?Request $request = null): Response
    {
        return Response::html($this->form(
            'Edit user — ' . $user->name(),
            $this->url('mark.users.edit', ['id' => AdminLayout::e($user->id())]),
            'Save changes',
            $errors,
            ['name' => $name, 'email' => $email],
            CardboardServices::roles()->roleIdsForUser($user->id()),
            $user,
            $request,
        ));
    }

    /**
     * Admin avatar preview, using the same IDs the shared media picker
     * updates after a selection. A missing file hides the preview instead of
     * rendering a broken image; the picker can fill it again.
     */
    private function avatarPreview(?string $avatar): string
    {
        $src = null !== $avatar
            ? (new \Modules\Blog\Application\Services\AuthorAvatar(new LocalStorageProvider()))->url($avatar)
            : null;

        return '<div id="image-preview" style="margin-top:8px;' . (null !== $src ? '' : 'display:none;') . '">'
            . '<img id="image-preview-img" src="' . AdminLayout::e($src ?? '') . '" alt="Preview"'
            . ' style="max-width:120px;max-height:120px;border-radius:8px;border:1px solid var(--cba-border);">'
            . '</div>';
    }

    /**
     * The avatar chosen from the media library.
     *
     * The picker writes a public URL (`/storage/uploads/...` or an https CDN
     * URL) into the hidden field. A local URL is checked against the upload
     * allowlist so a forged POST cannot point at an arbitrary file; a Cloudinary
     * URL is accepted because that is what the media picker lists when the
     * provider is configured. The media asset stays owned by Media: changing
     * or clearing an avatar never deletes it.
     *
     * @return array{path: ?string, error: string}
     */
    private function avatarSelection(Request $request): array
    {
        $avatar = $request->body('avatar');

        if (!is_string($avatar) || '' === trim($avatar)) {
            return ['path' => null, 'error' => ''];
        }

        $avatar = trim($avatar);

        if (str_starts_with($avatar, '/storage/uploads/')) {
            $relative = substr($avatar, strlen('/storage/uploads/'));

            return (new LocalStorageProvider())->isAllowed($relative)
                ? ['path' => $avatar, 'error' => '']
                : ['path' => null, 'error' => 'Choose an image from the media library.'];
        }

        if (1 === preg_match('#^https://res\.cloudinary\.com/#i', $avatar)) {
            return ['path' => $avatar, 'error' => ''];
        }

        return ['path' => null, 'error' => 'Choose an image from the media library.'];
    }

    /**
     * Avatar change state for the edit submit: upload error appended to the
     * error list (nothing stored on error), resolved new path, removal flag.
     *
     * @param list<string> $errors
     * @param string|null  $currentAvatar the stored value, so an unchanged
     *                                    missing file does not block unrelated edits
     *
     * @return array{errors: list<string>, newAvatar: ?string, removeAvatar: bool}
     */
    private function editAvatarState(array $errors, Request $request, ?string $currentAvatar = null): array
    {
        $removeAvatar = in_array($request->body('remove_avatar'), ['on', '1', 'true', true], true);
        $selection = $this->avatarSelection($request);
        $posted = $request->body('avatar');
        $newAvatar = $selection['path'];

        // The form always posts the stored value. If that file has gone
        // missing, saving the name or role must still work: the avatar is
        // unchanged, not an invalid new selection.
        if ('' !== $selection['error'] && is_string($posted) && $posted === $currentAvatar) {
            $selection = ['path' => null, 'error' => ''];
            $newAvatar = null;
        }

        if ('' !== $selection['error']) {
            $errors[] = $selection['error'];
        }

        return ['errors' => $errors, 'newAvatar' => $newAvatar, 'removeAvatar' => $removeAvatar];
    }

    /**
     * Apply an avatar change from the edit form: a new selection, a removal,
     * or nothing. The media asset is shared, so the old file is never deleted
     * here — an image used by an article or another user must survive an
     * avatar change.
     */
    private function applyAvatarChange(\Modules\Users\Domain\Entities\User $user, ?string $newAvatar, bool $removeAvatar): void
    {
        if (null !== $newAvatar) {
            CardboardServices::users()->updateAvatar($user->id(), $newAvatar);

            return;
        }

        if ($removeAvatar && null !== $user->avatar()) {
            CardboardServices::users()->updateAvatar($user->id(), null);
        }
    }

    /**
     * Attribution for the Outbox copy of a user security event.
     *
     * The payload's `userId` is the affected account, not the administrator, so
     * the actor travels in metadata. Without it the Audit handler fell back to
     * that id and wrote the target's hash into the Admin column.
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
     * Replace the user's role with the chosen one (no-op when none chosen).
     */
    private function syncRole(string $userId, string $roleId): void
    {
        if ('' === $roleId) {
            return;
        }

        $roles = CardboardServices::roles();
        $users = CardboardServices::users();

        foreach ($roles->roleIdsForUser($userId) as $existingRoleId) {
            $users->removeRole($userId, $existingRoleId);
        }
        $users->assignRole($userId, $roleId);
    }

    /**
     * @return list<string>
     */
    private function validate(string $name, string $email, #[\SensitiveParameter] string $password, bool $passwordRequired): array
    {
        // The admin form's legacy strings, kept byte-identical; the field
        // rules live in the Validation module. Two deliberate tightenings
        // vs the old `=== ''` checks (which the raw, untrimmed inputs
        // reached): `required` also rejects whitespace-only names and
        // passwords, and `min` counts characters, not bytes.
        //
        // The MINIMUM is not this file's to state (audit finding #3): it comes
        // from `PasswordPolicy`, the same owner the public register and reset
        // flows ask, so an admin cannot set what a reader cannot choose. The
        // `required` message stays the admin form's own — an admin who left the
        // field blank is told the field is required, not lectured about length.
        return (new Validator())->validate(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name'     => 'required',
                'email'    => 'required|email',
                'password' => $passwordRequired
                    ? 'required|' . PasswordPolicy::minRule()
                    : PasswordPolicy::minRule(),
            ],
            [
                'name.required'     => 'Name is required.',
                'email.required'    => 'A valid email is required.',
                'email.email'       => 'A valid email is required.',
                'password.required' => 'Password is required.',
                'password.min'      => PasswordPolicy::tooShortMessage(),
            ],
        )->all();
    }
}
