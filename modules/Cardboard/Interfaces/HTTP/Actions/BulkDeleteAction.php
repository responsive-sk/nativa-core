<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP\Actions;

use Modules\Cardboard\Application\Services\AdminLocale;
use Modules\Cardboard\Application\Services\BulkEntityGate;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Cardboard\Interfaces\HTTP\CardboardServices;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Database\Infrastructure\Persistence\UnitOfWork;

/**
 * BulkDeleteAction — handles bulk delete for admin tables.
 *
 * DB-backed entities (users, roles) are deleted inside a UnitOfWork
 * transaction — all-or-nothing.  Filesystem entities (pages, articles)
 * are deleted sequentially; a failure on one does not roll back the rest.
 *
 * The refused path's security event rides the Outbox when it is installed,
 * behind a `class_exists` guard and never a `use` statement: an import here
 * puts Outbox back in this module's closure, and Outbox imports Cardboard in
 * turn (its metric provider does), so with Security also on the path the graph
 * is `Cardboard → Security → Outbox → Cardboard` — a cycle no install order
 * can satisfy, measured on a fresh tree in part 07. Without the Outbox the
 * refusal still refuses; only the trace is missing, the trade the Users
 * module's login and logout actions make too.
 */
#[Route(path: '/mark/bulk-delete', method: 'POST', name: 'mark.bulk-delete')]
final class BulkDeleteAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $entity = $request->bodyString('entity');

        // The entity is chosen by the POST body, so the path-level permission
        // (content.manage) is not the entity's own gate. Checked BEFORE any id
        // is read: without this, an author holding content.manage could post
        // entity=users here and delete accounts.
        $denied = BulkEntityGate::refuse($entity, 'delete', \Modules\Users\Interfaces\HTTP\AuthServices::auth()->currentUser());

        if (null !== $denied) {
            // A refused bulk operation is the same category of fact as a
            // refused path, so it gets the same durable record — an attacker
            // probing the entity parameter must leave a trace just as probing
            // a URL does.
            if (class_exists('Modules\\Outbox\\Interfaces\\HTTP\\SecurityEventServices')) {
                $events = \Modules\Outbox\Interfaces\HTTP\SecurityEventServices::class;
                $events::bulkRefused($entity, 'bulk-delete', $request->getClientIp());
            }

            return $denied;
        }

        // Body carries the raw param ('sk' | 'en'); storage services need the
        // canonical code — directoryFor() only maps 'en_US' to blog/en, a raw
        // 'en' here silently fell back to the SK directory (EN bulk no-op bug).
        $localeRaw = $request->bodyString('locale', 'sk');
        $locale = AdminLocale::canonical($localeRaw);
        $ids = $this->selectedIds($request);

        if ([] === $ids) {
            return $this->toastResponse($request, $entity, $localeRaw, 'No items selected', true);
        }

        ['deleted' => $deleted, 'unpublished' => $unpublished, 'skipped' => $skipped]
            = $this->deleteInTransaction($entity, $ids, $locale);

        $this->recordDelete($deleted, $unpublished, $entity, $request);

        return $this->respond($request, $entity, $localeRaw, $locale, $this->toast($deleted, $unpublished, $skipped, $entity));
    }

    /**
     * The submitted ids, or an empty list when the body carries none.
     *
     * Non-scalar entries are dropped rather than passed on: an id ends up in a
     * filesystem path or a DB lookup, so an array in the body is not an id.
     *
     * @return list<int|string>
     */
    private function selectedIds(Request $request): array
    {
        $raw = $request->body('ids');

        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            $raw,
            static fn (mixed $id): bool => is_string($id) || is_int($id),
        ));
    }

    /**
     * What a delete is worth remembering, and nothing when it deleted nothing.
     *
     * The detail line carries the unpublished count because "10 deleted" and
     * "10 deleted, 4 of them live until a moment ago" are different facts about
     * the site, and the audit trail is the only place the second one survives.
     */
    private function recordDelete(int $deleted, int $unpublished, string $entity, Request $request): void
    {
        if ($deleted <= 0) {
            return;
        }

        $detail = $unpublished > 0
            ? "Bulk deleted {$deleted} {$entity} ({$unpublished} unpublished first)"
            : "Bulk deleted {$deleted} {$entity}";

        CacheInvalidator::flush();
        \Modules\Audit\Interfaces\HTTP\AuditServices::log('bulk.delete', $entity, $detail, $request->getClientIp());
    }

    /**
     * The result, in the shape the caller made the request in: a fragment for
     * HTMX (the table it targets is replaced in place) and a redirect for a
     * native form navigation, which must never land on this POST-only URL.
     *
     * The redirect keeps the locale so the user lands back on the same language
     * table they started from (was: always SK).
     */
    private function respond(Request $request, string $entity, string $localeRaw, string $locale, string $toast): Response
    {
        if (!AdminLayout::isHtmx($request)) {
            return Response::redirect($this->url('mark.' . $this->indexSegment($entity)) . '?locale=' . AdminLocale::raw($localeRaw)
                . '&toast=' . urlencode($toast));
        }

        return Response::html(
            AdminLayout::toast($toast)
            . $this->renderUpdatedTable($entity, $locale),
        );
    }

    /**
     * The result line, including what was NOT done.
     *
     * A silently skipped row reads as "the bulk action ignored my selection",
     * which is indistinguishable from a bug — so the skipped count is reported
     * rather than swallowed.
     */
    private function toast(int $deleted, int $unpublished, int $skipped, string $entity): string
    {
        if ($deleted <= 0) {
            return $skipped > 0
                ? sprintf('Deleted nothing — %d %s you do not own', $skipped, $entity)
                : 'No items deleted';
        }

        $parts = [sprintf('Deleted %d %s', $deleted, $entity)];

        if ($unpublished > 0) {
            $parts[] = sprintf('%d were unpublished first', $unpublished);
        }

        if ($skipped > 0) {
            $parts[] = sprintf('%d skipped (not yours)', $skipped);
        }

        return implode(', ', $parts) . '.';
    }

    /**
     * A plain toast/redirect for an invalid request.
     */
    private function toastResponse(Request $request, string $entity, string $localeRaw, string $message, bool $isError): Response
    {
        if (AdminLayout::isHtmx($request)) {
            return Response::html(AdminLayout::toast($message, $isError ? 'err' : 'ok'));
        }

        return Response::redirect($this->url('mark.' . $this->indexSegment($entity))
            . '?locale=' . AdminLocale::raw($localeRaw) . '&toast=' . urlencode($message));
    }

    /**
     * Admin index path segment for an entity (users, roles, pages, articles).
     */
    private function indexSegment(string $entity): string
    {
        return match ($entity) {
            'users' => 'users',
            'roles' => 'roles',
            'pages' => 'pages',
            default => 'articles',
        };
    }

    /**
     * Execute bulk delete inside a transaction (DB-backed entities)
     * or sequentially (filesystem entities).
     *
     * @param list<int|string> $ids
     *
     * @return array{deleted: int, unpublished: int, skipped: int}
     */
    private function deleteInTransaction(string $entity, array $ids, string $locale): array
    {
        if ('users' === $entity || 'roles' === $entity) {
            return ['deleted' => $this->deleteDbEntities($entity, $ids), 'unpublished' => 0, 'skipped' => 0];
        }

        return $this->deleteFileEntities($entity, $ids, $locale);
    }

    /**
     * Delete DB-backed entities inside a UnitOfWork transaction.
     *
     * @param list<int|string> $ids
     */
    private function deleteDbEntities(string $entity, array $ids): int
    {
        $pdo = DatabaseConnectionManager::instance()->connection('users');
        $uow = new UnitOfWork($pdo);
        $deleted = 0;

        $uow->transaction(function (\PDO $_pdo) use ($entity, $ids, &$deleted): void {
            foreach ($ids as $id) {
                if ($this->deleteItem($entity, (string) $id, '')) {
                    $deleted++;
                }
            }
        });

        return $deleted;
    }

    /**
     * Delete filesystem entities (pages/articles) sequentially.
     * Unpublish published items before deleting.
     *
     * @param list<int|string> $ids
     *
     * @return array{deleted: int, unpublished: int, skipped: int}
     */
    private function deleteFileEntities(string $entity, array $ids, string $locale): array
    {
        $deleted = 0;
        $unpublished = 0;
        $skipped = 0;
        $slugs = array_map(static fn (int|string $id): string => (string) $id, $ids);

        // Articles are gated on OWNERSHIP as well as on `content.manage`:
        // without this an author could delete a colleague's post through the
        // bulk endpoint while every single-article route returned 403. Pages
        // carry no per-page owner and stay group-editable by design.
        if ('articles' === $entity) {
            $partition = \Modules\Blog\Application\BlogServices::writeGuard()->partition($slugs, $locale, \Modules\Users\Interfaces\HTTP\AuthServices::auth()->currentUser());
            $skipped = count($partition['refused']);
            $slugs = $partition['allowed'];
        }

        foreach ($slugs as $slug) {
            // Unpublish before delete if published.
            if ('articles' === $entity || 'pages' === $entity) {
                if ($this->isPublished($entity, $slug, $locale)) {
                    $this->unpublishItem($entity, $slug, $locale);
                    $unpublished++;
                }
            }

            if ($this->deleteItem($entity, $slug, $locale)) {
                $deleted++;
            }
        }

        return ['deleted' => $deleted, 'unpublished' => $unpublished, 'skipped' => $skipped];
    }

    private function isPublished(string $entity, string $slug, string $locale): bool
    {
        return match ($entity) {
            'articles' => CardboardServices::blogCrud()->isPublished($slug, $locale),
            'pages' => CardboardServices::pageCrud()->isPublished($slug, $locale),
            default => false,
        };
    }

    private function unpublishItem(string $entity, string $slug, string $locale): void
    {
        match ($entity) {
            'articles' => CardboardServices::blogCrud()->setPublished($slug, false, $locale),
            'pages' => CardboardServices::pageCrud()->setPublished($slug, false, $locale),
            default => null,
        };
    }

    private function deleteItem(string $entity, string $id, string $locale): bool
    {
        return match ($entity) {
            'users' => $this->deleteUser($id),
            'roles' => $this->deleteRole($id),
            'pages' => $this->deletePage($id, $locale),
            'articles' => $this->deleteArticle($id, $locale),
            default => false,
        };
    }

    private function deleteUser(string $id): bool
    {
        $users = CardboardServices::users();
        $user = $users->findById($id);

        if (null === $user) {
            return false;
        }

        // Prevent deleting the last admin.
        if (in_array('admin', $user->roles(), true) && $this->isLastAdmin($id)) {
            return false;
        }

        $users->delete($id);

        return true;
    }

    private function isLastAdmin(string $excludeId): bool
    {
        $admins = 0;

        foreach (CardboardServices::users()->all() as $user) {
            if ($user->id() === $excludeId) {
                continue;
            }

            if (in_array('admin', $user->roles(), true)) {
                $admins++;
            }
        }

        return 0 === $admins;
    }

    private function deleteRole(string $id): bool
    {
        $roles = CardboardServices::roles();

        // Prevent deleting admin role.
        $adminRole = $roles->findByName('admin');
        $adminId = is_string($adminRole['id'] ?? null) ? (string) $adminRole['id'] : '';

        if ('' !== $adminId && $adminId === $id) {
            return false;
        }

        $roles->deleteRole($id);

        return true;
    }

    private function deletePage(string $slug, string $locale): bool
    {
        return CardboardServices::pageCrud()->delete($slug, $locale);
    }

    private function deleteArticle(string $slug, string $locale): bool
    {
        return CardboardServices::blogCrud()->delete($slug, $locale);
    }

    private function renderUpdatedTable(string $entity, string $locale): string
    {
        return match ($entity) {
            'users' => \Modules\Users\Interfaces\HTTP\UsersTableRenderer::render(CardboardServices::users()->all(), $this->router()),
            'roles' => \Modules\Users\Interfaces\HTTP\RolesTableRenderer::render(CardboardServices::roles()->allRoles(), $this->router()),
            'pages' => \Modules\Pages\Interfaces\HTTP\PagesTableRenderer::render(
                CardboardServices::pageCrud()->list($locale),
                $locale,
                $this->router(),
            ),
            'articles' => \Modules\Blog\Interfaces\HTTP\ArticlesTableRenderer::render(
                CardboardServices::blogCrud()->list($locale),
                $locale,
                $this->router(),
            ),
            default => '',
        };
    }
}
