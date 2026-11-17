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
use Modules\RestPanel\Interfaces\HTTP\RestPanelServices;

/**
 * RestPanelIndexAction — the service list at `/mark/rest`.
 *
 * The page answers the only question the panel's entry point has: which remote
 * services exist, and where does each one's resources live. A service that
 * failed to save its snapshot is still listed, because `restpanel.db` is the
 * runtime truth (plan F2); the snapshot is export, not state.
 *
 * `/mark/rest` is classified in `MarkRoutePolicy` under `settings.manage`, and
 * the longest-prefix rule covers every child route this module adds.
 */
#[Route(path: '/mark/rest', method: 'GET', name: 'mark.rest')]
final class RestPanelIndexAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $services = RestPanelServices::repository()->listServices();

        $rows = [];

        foreach ($services as $service) {
            $href = $this->url('mark.rest.service', ['id' => $service['id']]);
            $updatedAt = (string) $service['updated_at'];
            $rows[] = [
                ['html' => '<a href="' . CardboardLayout::e($href) . '">' . CardboardLayout::e($service['name']) . '</a>'],
                $service['base_url'],
                '' === $updatedAt ? '—' : CardboardLayout::formatTime($updatedAt),
            ];
        }

        $content = '<h1 class="m-heading">REST services</h1>'
            . '<p class="m-muted">A service is one remote REST API. Its resources are what the panel may list, read and — when the specification says so — write.</p>'
            . PanelHtml::table(
                ['Service', 'Base URL', 'Updated'],
                $rows,
                'No REST service is registered yet.',
                'Add one to give the panel an API to read.',
                $this->url('mark.rest.services.create'),
                'New service',
            );

        return Panel::render($this->router(), 'REST services', $content);
    }
}
