<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogCrudService;
use Modules\Blog\Application\Services\BlogPageCache;
use Modules\Cardboard\Application\Services\AdminLocale;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Users\Interfaces\HTTP\AuthServices;

/**
 * Articles delete — POST /mark/articles/{slug}/delete.
 *
 * Removes the article .md file and logs the deletion to the audit log.
 * Redirects back to the articles index.
 */
#[Route(path: '/mark/articles/{slug}/delete', method: 'POST|DELETE', name: 'articles.delete')]
final class ArticlesDeleteAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $slug = (string) ($request->routeParam('slug') ?? '');
        $localeRaw = $request->queryString('locale', 'sk');
        $localeParam = AdminLocale::raw($localeRaw);
        $locale = AdminLocale::canonical($localeRaw);

        if ('' !== $slug) {
            $service = new BlogCrudService();
            $guard = BlogServices::writeGuard();
            $user = AuthServices::auth()->currentUser();
            $article = $service->find($slug, $locale);

            if (null === $article || !$guard->owns($slug, $locale, $user)) {
                return Response::html(
                    AdminLayout::render('Not your article', BlogServices::ownership()->refusal($slug), router: $this->router()),
                    403,
                );
            }

            $deleted = $guard->delete($slug, $locale, $user);

            if ($deleted) {
                BlogPageCache::invalidate();
                AuditServices::log(
                    'article.delete',
                    'article',
                    'Deleted: ' . $slug,
                    $request->getClientIp(),
                );
            }
        }

        return $this->redirect($request, $localeParam);
    }

    private function redirect(Request $request, string $localeParam): Response
    {
        if (AdminLayout::isHtmx($request)) {
            return Response::html('');
        }

        return Response::redirect($this->url('articles.index') . '?locale=' . $localeParam . '&toast=Article+deleted');
    }
}
