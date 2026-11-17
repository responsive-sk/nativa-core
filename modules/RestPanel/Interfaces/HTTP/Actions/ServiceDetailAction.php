<?php

declare(strict_types = 1);

namespace Modules\RestPanel\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\CardboardLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\RestPanel\Interfaces\HTTP\Panel;
use Modules\RestPanel\Interfaces\HTTP\PanelHtml;

/**
 * ServiceDetailAction — one service: what it is, and what the panel may do.
 *
 * The token row shows the *variable name*, never a value, and says whether the
 * variable is set right now. That is the one auth failure an operator can fix
 * without reading a log: an unset `RESTADMIN_BLOG_TOKEN` looks exactly like a
 * wrong token until the page tells them it is absent.
 *
 * Resources are listed with their action set, because a read-only resource and
 * a writable one look identical until a button appears or does not. The
 * `create` opt-in is a property of the specification (plan D6), so the list is
 * where an operator checks it.
 */
#[Route(path: '/mark/rest/services/{id}', method: 'GET', name: 'mark.rest.service', handlerMethod: 'handle')]
final class ServiceDetailAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $spec = Panel::service((string) $request->routeParam('id'));

        if (null === $spec) {
            return Panel::notFound($this->router(), 'That REST service does not exist.');
        }

        $tokenEnv = $spec->service->tokenEnv();
        $tokenState = null === $tokenEnv
            ? 'no token (public API)'
            : ($tokenEnv . (is_string(getenv($tokenEnv)) && '' !== getenv($tokenEnv) ? ' (set)' : ' (NOT SET)'));

        $summary = '<dl class="m-dl">'
            . '<dt>Service id</dt><dd>' . CardboardLayout::e($spec->service->id) . '</dd>'
            . '<dt>Base URL</dt><dd>' . CardboardLayout::e($spec->service->baseUrl) . '</dd>'
            . '<dt>Token</dt><dd>' . CardboardLayout::e($tokenState) . '</dd>'
            . '<dt>Timeout</dt><dd>' . CardboardLayout::e($spec->service->timeoutSeconds) . ' s</dd>'
            . '</dl>';

        $rows = [];

        foreach ($spec->resources as $resource) {
            $rows[] = [
                ['html' => '<a href="' . CardboardLayout::e($this->url('mark.rest.items', ['id' => $spec->service->id, 'resource' => $resource->name])) . '">' . CardboardLayout::e($resource->name) . '</a>'],
                $resource->path,
                implode(', ', $resource->actions->toArray()),
                $resource->pagination->strategy,
                ['html' => '<a class="m-btn" href="' . CardboardLayout::e($this->url('mark.rest.resource.edit', ['id' => $spec->service->id, 'resource' => $resource->name])) . '">Edit</a>'],
            ];
        }

        $content = '<h1 class="m-heading">' . CardboardLayout::e($spec->service->name) . '</h1>'
            . '<p class="m-actions">'
            . '<a class="m-btn" href="' . CardboardLayout::e($this->url('mark.rest.service.edit', ['id' => $spec->service->id])) . '">Edit service</a> '
            . '<a class="m-btn m-btn--primary" href="' . CardboardLayout::e($this->url('mark.rest.resource.create', ['id' => $spec->service->id])) . '">New resource</a>'
            . '</p>'
            . $summary
            . '<h2>Resources</h2>'
            . PanelHtml::table(
                ['Resource', 'Path', 'Actions', 'Pagination', ''],
                $rows,
                'No resources yet.',
                'A resource is one collection this service exposes.',
                $this->url('mark.rest.resource.create', ['id' => $spec->service->id]),
                'New resource',
            )
            . '<form method="post" action="' . CardboardLayout::e($this->url('mark.rest.service.delete', ['id' => $spec->service->id])) . '" onsubmit="return confirm(\'Remove this service and every resource in it?\');">'
            . CardboardLayout::csrfField()
            . '<button class="m-btn m-btn--danger" type="submit">Delete service</button>'
            . '</form>';

        return Panel::render($this->router(), $spec->service->name, $content);
    }
}
