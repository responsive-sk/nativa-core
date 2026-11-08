<?php

declare(strict_types = 1);

namespace Modules\Pages\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Pages\Application\Services\PageCrudService;

/**
 * Pages delete — POST /mark/pages/{slug}/delete.
 *
 * Removes the page .md file and logs the deletion to the audit log.
 * Returns empty response on HTMX request (row removal), redirect otherwise.
 */
#[Route(path: '/mark/pages/{slug}/delete', method: 'POST', name: 'pages.delete')]
final class PagesDeleteAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $slug = (string) ($request->routeParam('slug') ?? '');
        $localeParam = $request->queryString('locale', 'sk');
        $locale = ContentServices::mapper()->code($localeParam);

        if ('' !== $slug) {
            $service = new PageCrudService();
            $deleted = $service->delete($slug, $locale);

            if ($deleted) {
                CacheInvalidator::flush();
                AuditServices::log(
                    'page.delete',
                    'page',
                    'Deleted: ' . $slug,
                    $request->getClientIp(),
                );
            }
        }

        // HTMX: return empty response to remove row from table.
        if (AdminLayout::isHtmx($request)) {
            return Response::html('');
        }

        return Response::redirect($this->url('pages.index') . '?locale=' . $localeParam . '&toast=Page+deleted');
    }
}
