<?php

declare(strict_types = 1);

namespace Modules\RestPanel\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\CardboardLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\RestPanel\Application\Mapping\ApiException;
use Modules\RestPanel\Application\Mapping\FieldMapper;
use Modules\RestPanel\Application\Mapping\MappingException;
use Modules\RestPanel\Domain\Model\ResourceSpec;
use Modules\RestPanel\Domain\Model\ServiceSpec;
use Modules\RestPanel\Interfaces\HTTP\Panel;
use Modules\RestPanel\Interfaces\HTTP\PanelHtml;
use Modules\RestPanel\Interfaces\HTTP\RestPanelServices;

/**
 * ItemDetailAction — one member, as the service answered.
 *
 * Every stored field is shown, not only the list's columns: the detail page is
 * where an operator looks when a list cell is surprising, so hiding the fields
 * that are not in the table would hide the answer. A field the API did not send
 * renders as an em dash, the same mark `FieldMapper`'s absence rule gives the
 * form, so "null" and "absent" do not silently become "empty".
 *
 * Edit and delete appear only when the resource's action set allows them. The
 * forms post to routes that check the same allowlist again — a hidden button is
 * not a permission.
 */
#[Route(path: '/mark/rest/services/{id}/resources/{resource}/items/{item}', method: 'GET', name: 'mark.rest.item', handlerMethod: 'handle')]
final class ItemDetailAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $spec = Panel::service((string) $request->routeParam('id'));
        $resource = null === $spec ? null : $this->resource($spec, (string) $request->routeParam('resource'));

        if (null === $spec || null === $resource) {
            return Panel::notFound($this->router(), 'That resource does not exist.');
        }

        $id = (string) $request->routeParam('item');

        try {
            $item = RestPanelServices::itemReader()->read($spec->service, $resource, $id);
        } catch (ApiException $exception) {
            if ('not_found' === $exception->error->kind) {
                return Panel::notFound($this->router(), $exception->error->message);
            }

            return Panel::error($this->router(), 'The service refused the request', $exception->error->message);
        } catch (MappingException $exception) {
            return Panel::error($this->router(), 'The answer is not an item', $exception->getMessage());
        }

        $mapper = new FieldMapper();
        $details = '<dl class="m-dl">';

        foreach ($resource->fields as $field) {
            $details .= '<dt>' . CardboardLayout::e($field->label) . '</dt><dd>'
                . CardboardLayout::e($mapper->has($item, $field->name) ? PanelHtml::format($mapper->read($item, $field->name)) : '—')
                . '</dd>';
        }

        $details .= '</dl>';

        $actions = '</p>';

        if ($resource->actions->allows('update') || $resource->actions->allows('delete')) {
            $actions = '<p class="m-actions">';

            if ($resource->actions->allows('update')) {
                $actions .= '<a class="m-btn" href="' . CardboardLayout::e($this->url('mark.rest.item.edit', ['id' => $spec->service->id, 'resource' => $resource->name, 'item' => $id])) . '">Edit</a> ';
            }

            if ($resource->actions->allows('delete')) {
                $actions .= '<form method="post" action="' . CardboardLayout::e($this->url('mark.rest.item.delete', ['id' => $spec->service->id, 'resource' => $resource->name, 'item' => $id])) . '" onsubmit="return confirm(\'Delete this item?\');">'
                    . CardboardLayout::csrfField()
                    . '<button class="m-btn m-btn--danger" type="submit">Delete</button>'
                    . '</form>';
            }

            $actions .= '</p>';
        }

        $content = '<h1 class="m-heading">' . CardboardLayout::e($resource->name) . ' · ' . CardboardLayout::e($id) . '</h1>'
            . '<p><a href="' . CardboardLayout::e($this->url('mark.rest.items', ['id' => $spec->service->id, 'resource' => $resource->name])) . '">Back to the collection</a></p>'
            . $details
            . $actions;

        return Panel::render($this->router(), $resource->name . ' ' . $id, $content);
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
