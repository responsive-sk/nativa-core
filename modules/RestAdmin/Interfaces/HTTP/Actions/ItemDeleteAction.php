<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\HTTP\Actions;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\RestAdmin\Application\Mapping\ErrorMapper;
use Modules\RestAdmin\Application\Mapping\RequestFactory;
use Modules\RestAdmin\Application\Services\Audit;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Domain\Model\ServiceSpec;
use Modules\RestAdmin\Interfaces\HTTP\Panel;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * ItemDeleteAction — POST (or DELETE) one member.
 *
 * The allowlist check is repeated here rather than trusted from the detail
 * page: a form button is markup, and markup is not an authorization decision.
 * The request is a `DELETE` to the item path, built by the same
 * `RequestFactory` the readers use, so the id is encoded exactly once and a
 * slash in it cannot address a different endpoint.
 */
#[Route(path: '/mark/rest/services/{id}/resources/{resource}/items/{item}/delete', method: 'POST|DELETE', name: 'mark.rest.item.delete', handlerMethod: 'handle')]
final class ItemDeleteAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $spec = Panel::service((string) $request->routeParam('id'));
        $resource = null === $spec ? null : $this->resource($spec, (string) $request->routeParam('resource'));

        if (null === $spec || null === $resource) {
            return Panel::notFound($this->router(), 'That resource does not exist.');
        }

        if (!$resource->actions->allows('delete')) {
            return Panel::error($this->router(), 'Deleting is not allowed', 'This resource does not allow deletes. Add "delete" to its actions to allow them.', 403);
        }

        $id = (string) $request->routeParam('item');

        try {
            $response = RestAdminServices::transport()->send(
                (new RequestFactory())->delete($spec->service, $resource, $id),
            );
        } catch (\Throwable $exception) {
            return Panel::error($this->router(), 'The request could not be sent', $exception->getMessage());
        }

        if (!$response->isSuccess()) {
            return Panel::error($this->router(), 'The service refused the delete', (new ErrorMapper())->map($response)->message);
        }

        Audit::log('restadmin.item.delete', $spec->service->id . '/' . $resource->name, 'Deleted item ' . $id . ' through the panel', $request->getClientIp());

        return Response::redirect(
            $this->url('mark.rest.items', ['id' => $spec->service->id, 'resource' => $resource->name])
            . '?toast=' . rawurlencode('Item deleted.'),
        );
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
