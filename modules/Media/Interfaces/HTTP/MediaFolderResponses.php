<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Kernel\Contracts\RouterInterface;

/**
 * MediaFolderResponses — how a folder operation answers, in one place.
 *
 * Three actions ask the same two questions ("did it work?", "what do I say
 * about it?"), and the answer has to be given twice: once as an HTMX fragment
 * for a panel that swapped its own content, and once as a redirect for a
 * native form POST. Two copies of that branch is how one of them ends up
 * returning a bare fragment to a browser navigation — a white page with a
 * toast script on it.
 *
 * Every redirect carries the folder the user was looking at, so a refusal does
 * not silently move them somewhere else in the library.
 */
final class MediaFolderResponses
{
    /**
     * The operation succeeded: say so and return to the folder it affects.
     */
    public static function success(RouterInterface $router, Request $request, string $message, string $folder = ''): Response
    {
        return self::answer($router, $request, $message, 'ok', $folder);
    }

    /**
     * The operation was refused: the message names the problem, and nothing
     * was written.
     */
    public static function refusal(RouterInterface $router, Request $request, string $message, string $folder = ''): Response
    {
        return self::answer($router, $request, $message, 'err', $folder);
    }

    private static function answer(RouterInterface $router, Request $request, string $message, string $type, string $folder): Response
    {
        if (AdminLayout::isHtmx($request)) {
            return Response::html(AdminLayout::toast($message, $type));
        }

        $query = '' !== $folder ? 'folder=' . urlencode($folder) . '&' : '';

        if ('err' === $type) {
            $query .= 'toast-type=error&';
        }

        return Response::redirect($router->url('media.index') . '?' . $query . 'toast=' . urlencode($message));
    }
}
