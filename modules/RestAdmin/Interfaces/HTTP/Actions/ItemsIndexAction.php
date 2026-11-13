<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\RestAdmin\Application\Mapping\ApiException;
use Modules\RestAdmin\Application\Mapping\FieldMapper;
use Modules\RestAdmin\Application\Mapping\ListPage;
use Modules\RestAdmin\Application\Mapping\MappingException;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Domain\Model\ServiceSpec;
use Modules\RestAdmin\Interfaces\HTTP\Panel;
use Modules\RestAdmin\Interfaces\HTTP\PanelHtml;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * ItemsIndexAction — one page of a resource's collection.
 *
 * The columns are the resource's `in_list` fields, read through `FieldMapper`,
 * so a JSON:API row and a flat row render the same table. The id column links
 * to the member only when the row carries the id field: a link with an empty
 * id would be a link to the collection, which is not where the reader wanted
 * to go.
 *
 * Pagination controls are built from `PageState`, not from the service's raw
 * links: the panel's own page stays at `/mark/rest/...` and carries only the
 * cursor or page number forward, so an upstream `next` URL is data, never a
 * navigation target the panel follows blindly.
 */
#[Route(path: '/mark/rest/services/{id}/resources/{resource}/items', method: 'GET', name: 'mark.rest.items', handlerMethod: 'handle')]
final class ItemsIndexAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $spec = Panel::service((string) $request->routeParam('id'));
        $resource = null === $spec ? null : $this->resource($spec, (string) $request->routeParam('resource'));

        if (null === $spec || null === $resource) {
            return Panel::notFound($this->router(), 'That resource does not exist.');
        }

        $page = max(1, (int) $request->queryString('page', '1'));
        $cursor = $request->queryString('cursor');
        $cursor = '' === $cursor ? null : $cursor;

        try {
            $result = RestAdminServices::listReader()->read($spec->service, $resource, $page, $cursor);
        } catch (ApiException $exception) {
            return Panel::error($this->router(), 'The service refused the request', $exception->error->message);
        } catch (MappingException $exception) {
            return Panel::error($this->router(), 'The answer is not a collection', $exception->getMessage());
        }

        $headers = ['ID'];
        $columns = [];

        foreach ($resource->fields as $field) {
            if ($field->inList && $field->name !== $resource->idField) {
                $columns[] = $field;
                $headers[] = $field->label;
            }
        }

        $mapper = new FieldMapper();
        $rows = [];

        foreach ($result->items as $item) {
            $id = $mapper->read($item, $resource->idField);
            $cells = [];

            if (null === $id || '' === $id) {
                $cells[] = '—';
            } else {
                $href = $this->url('mark.rest.item', ['id' => $spec->service->id, 'resource' => $resource->name, 'item' => (string) $id]);
                $cells[] = ['html' => '<a href="' . AdminLayout::e($href) . '">' . AdminLayout::e(PanelHtml::format($id)) . '</a>'];
            }

            foreach ($columns as $field) {
                $cells[] = PanelHtml::format($mapper->has($item, $field->name) ? $mapper->read($item, $field->name) : null);
            }

            $rows[] = $cells;
        }

        $newHref = $resource->actions->allows('create')
            ? $this->url('mark.rest.item.create', ['id' => $spec->service->id, 'resource' => $resource->name])
            : '';

        $content = '<h1 class="m-heading">' . AdminLayout::e($spec->service->name) . ' · ' . AdminLayout::e($resource->name) . '</h1>'
            . '<p class="m-muted">' . AdminLayout::e($resource->path) . ' · ' . AdminLayout::e(implode(', ', $resource->actions->toArray())) . '</p>'
            . PanelHtml::table(
                $headers,
                $rows,
                'The collection is empty.',
                'The service answered, but this page has no rows.',
                $newHref,
                'New item',
            )
            . $this->pager($result, $spec, $resource);

        return Panel::render($this->router(), $resource->name, $content);
    }

    private function pager(ListPage $page, ServiceSpec $spec, ResourceSpec $resource): string
    {
        $links = [];
        $base = $this->url('mark.rest.items', ['id' => $spec->service->id, 'resource' => $resource->name]);

        if ($page->state->hasPrevious) {
            $links[] = '<a class="m-page-link" href="' . AdminLayout::e($base . '?' . http_build_query(['page' => max(1, $page->state->currentPage - 1)])) . '">‹ Prev</a>';
        }

        if ($page->state->hasNext) {
            $query = null !== $page->state->nextCursor
                ? ['cursor' => $page->state->nextCursor]
                : ['page' => $page->state->currentPage + 1];

            $links[] = '<a class="m-page-link" href="' . AdminLayout::e($base . '?' . http_build_query($query)) . '">Next ›</a>';
        }

        $position = sprintf('Page %d', $page->state->currentPage);

        if (null !== $page->state->total) {
            $position .= sprintf(' · %d rows', $page->state->total);
        }

        return '<div class="m-pagination"><span class="m-muted">' . AdminLayout::e($position) . '</span>'
            . implode('', $links) . '</div>';
    }

    private function resource(ServiceSpec $spec, string $name): ?ResourceSpec
    {
        foreach ($spec->resources as $resource) {
            if ($resource->name === $name) {
                return $resource;
            }
        }

        return null;
    }
}
