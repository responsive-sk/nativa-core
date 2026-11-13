<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\HTTP;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\RestAdmin\Domain\Model\ServiceSpec;

/**
 * Panel — the three things every REST admin page does the same way.
 *
 * A page is `AdminLayout` plus content; a missing service or resource is a 404
 * page rather than a redirect to the list, because "the thing you asked for is
 * gone" and "go look at the list" are different answers; and an upstream
 * failure is rendered with the API's own operator-facing message at 502
 * (the panel worked, the service did not).
 */
final class Panel
{
    public static function render(RouterInterface $router, string $title, string $content): Response
    {
        return Response::html(AdminLayout::render($title, $content, 'rest', [], [], $router));
    }

    public static function service(string $id): ?ServiceSpec
    {
        return '' === $id ? null : RestAdminServices::repository()->find($id);
    }

    public static function notFound(RouterInterface $router, string $message): Response
    {
        $content = '<h1 class="m-heading">Not found</h1>'
            . '<p class="m-muted">' . AdminLayout::e($message) . '</p>';

        return Response::html(AdminLayout::render('Not found', $content, 'rest', [], [], $router), 404);
    }

    public static function error(
        RouterInterface $router,
        string $title,
        string $message,
        int $status = 502,
    ): Response {
        $content = '<h1 class="m-heading">' . AdminLayout::e($title) . '</h1>'
            . '<div class="m-alert m-alert--err">' . AdminLayout::e($message) . '</div>'
            . '<p><a class="m-btn" href="' . AdminLayout::e($router->url('mark.rest')) . '">Back to services</a></p>';

        return Response::html(AdminLayout::render($title, $content, 'rest', [], [], $router), $status);
    }
}
