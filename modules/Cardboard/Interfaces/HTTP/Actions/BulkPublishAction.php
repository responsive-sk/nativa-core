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

/**
 * BulkPublishAction — handles bulk publish/unpublish for admin tables.
 *
 * Toggles the published status of selected articles or pages by
 * rewriting their markdown frontmatter.
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
#[Route(path: '/mark/bulk-publish', method: 'POST', name: 'mark.bulk-publish')]
final class BulkPublishAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $entity = $request->bodyString('entity');

        // The entity is chosen by the POST body, so the path-level permission
        // (content.manage) is not the entity's own gate. Checked BEFORE any id
        // is read: without this, an author holding content.manage could post
        // entity=users here.
        $denied = BulkEntityGate::refuse($entity, 'publish', \Modules\Users\Interfaces\HTTP\AuthServices::auth()->currentUser());

        if (null !== $denied) {
            // A refused bulk operation is the same category of fact as a
            // refused path, so it gets the same durable record — an attacker
            // probing the entity parameter must leave a trace just as probing
            // a URL does.
            if (class_exists('Modules\\Outbox\\Interfaces\\HTTP\\SecurityEventServices')) {
                $events = \Modules\Outbox\Interfaces\HTTP\SecurityEventServices::class;
                $events::bulkRefused($entity, 'bulk-publish', $request->getClientIp());
            }

            return $denied;
        }

        // Body carries the raw param ('sk' | 'en'); storage services need the
        // canonical code — directoryFor() only maps 'en_US' to blog/en, a raw
        // 'en' here silently fell back to the SK directory (EN bulk no-op bug).
        $localeRaw = $request->bodyString('locale', 'sk');
        $locale = AdminLocale::canonical($localeRaw);
        $publishedRaw = $request->body('published');
        $published = '1' === $publishedRaw || 'true' === $publishedRaw;
        $ids = $this->selectedIds($request);

        if ([] === $ids) {
            return $this->toastResponse($request, $entity, $localeRaw, 'No items selected', true);
        }

        $result = $this->publishItems($entity, $ids, $published, $locale);
        $updated = $result['updated'];
        $skipped = $result['skipped'];

        $this->recordPublish($updated, $entity, $published, $request);

        return $this->respond($request, $entity, $localeRaw, $locale, $this->toast($updated, $skipped, $entity, $published));
    }

    /**
     * The result, in the shape the caller made the request in: a fragment for
     * HTMX (the table it targets is replaced in place) and a redirect for a
     * native form navigation, which must never land on this POST-only URL.
     */
    private function respond(Request $request, string $entity, string $localeRaw, string $locale, string $toast): Response
    {
        if (!AdminLayout::isHtmx($request)) {
            return $this->toastResponse($request, $entity, $localeRaw, $toast, false);
        }

        return Response::html($this->renderUpdatedTable($entity, $locale));
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
     * What a bulk publish is worth remembering, and nothing when it changed
     * nothing — a no-op run is not an event, and an audit trail full of them is
     * a trail nobody reads.
     */
    private function recordPublish(int $updated, string $entity, bool $published, Request $request): void
    {
        if ($updated <= 0) {
            return;
        }

        $action = $published ? 'publish' : 'unpublish';

        CacheInvalidator::flush();
        \Modules\Audit\Interfaces\HTTP\AuditServices::log(
            'bulk.' . $action,
            $entity,
            "Bulk {$action}d {$updated} " . $entity,
            $request->getClientIp(),
        );
    }

    /**
     * The result line, including what was NOT done.
     *
     * A silently skipped row reads as "the bulk action ignored my selection",
     * which is indistinguishable from a bug — so the skipped count is reported
     * rather than swallowed.
     */
    private function toast(int $updated, int $skipped, string $entity, bool $published): string
    {
        $verb = $published ? 'Published' : 'Unpublished';

        if ($updated <= 0) {
            return $skipped > 0
                ? sprintf('%s nothing — %d %s you do not own', $verb, $skipped, $entity)
                : 'No items changed';
        }

        return $skipped > 0
            ? sprintf('%s %d %s, skipped %d (not yours).', $verb, $updated, $entity, $skipped)
            : sprintf('%s %d %s.', $verb, $updated, $entity);
    }

    /**
     * Toast for an invalid request: fragment for HTMX, redirect back to the
     * index (with a ?toast= message) for a native form navigation so the
     * message renders inside the full layout instead of a bare white page.
     */
    private function toastResponse(Request $request, string $entity, string $localeRaw, string $message, bool $isError): Response
    {
        if (AdminLayout::isHtmx($request)) {
            return Response::html(AdminLayout::toast($message, $isError ? 'err' : 'ok'));
        }

        $type = $isError ? 'err' : 'ok';

        return Response::redirect($this->url('mark.' . $this->indexSegment($entity)) . '?locale=' . AdminLocale::raw($localeRaw)
            . '&toast=' . urlencode($message));
    }

    /**
     * Admin index path segment for an entity (articles, pages, …).
     */
    private function indexSegment(string $entity): string
    {
        return match ($entity) {
            'pages' => 'pages',
            default => 'articles',
        };
    }

    /**
     * @param list<int|string> $ids
     *
     * @return array{updated: int, skipped: int}
     */
    private function publishItems(string $entity, array $ids, bool $published, string $locale): array
    {
        $updated = 0;
        $slugs = array_map(static fn (int|string $id): string => (string) $id, $ids);

        // Articles are gated on OWNERSHIP as well as on `content.manage`:
        // without this an author could unpublish a colleague's post through the
        // bulk endpoint while every single-article route returned 403. Pages
        // carry no per-page owner and stay group-editable by design.
        if ('articles' === $entity) {
            $partition = \Modules\Blog\Application\BlogServices::writeGuard()->partition($slugs, $locale, \Modules\Users\Interfaces\HTTP\AuthServices::auth()->currentUser());
            $skipped = count($partition['refused']);
            $slugs = $partition['allowed'];
        } else {
            $skipped = 0;
        }

        foreach ($slugs as $slug) {
            if ($this->publishItem($entity, $slug, $published, $locale)) {
                $updated++;
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    private function publishItem(string $entity, string $slug, bool $published, string $locale): bool
    {
        return match ($entity) {
            'articles' => \Modules\Blog\Application\BlogServices::writeGuard()->setPublished(
                $slug,
                $published,
                $locale,
                \Modules\Users\Interfaces\HTTP\AuthServices::auth()->currentUser(),
            ),
            'pages' => CardboardServices::pageCrud()->setPublished($slug, $published, $locale),
            default => false,
        };
    }

    private function renderUpdatedTable(string $entity, string $locale): string
    {
        return match ($entity) {
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
