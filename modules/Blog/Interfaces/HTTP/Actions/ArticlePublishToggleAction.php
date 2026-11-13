<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogCrudService;
use Modules\Blog\Interfaces\HTTP\ArticlesTableRenderer;
use Modules\Blog\Application\Services\BlogPageCache;
use Modules\Cardboard\Application\Services\AdminLocale;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Outbox\Application\Services\OutboxDrain;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;
use Modules\Users\Interfaces\HTTP\AuthServices;

/**
 * ArticlePublishToggleAction — POST /mark/articles/{slug}/publish.
 *
 * Flips the published flag of a single article (query param `to` = 1|0,
 * default: invert the current state). HTMX requests get the updated table
 * row back; native form submits are redirected to the index with a toast —
 * a POST-only URL is never rendered as a page.
 */
#[Route(path: '/mark/articles/{slug}/publish', method: 'POST', name: 'articles.publish-toggle')]
final class ArticlePublishToggleAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $slug = (string) ($request->routeParam('slug') ?? '');
        $localeRaw = $request->queryString('locale', 'sk');
        $localeParam = AdminLocale::raw($localeRaw);
        $locale = AdminLocale::canonical($localeRaw);

        $crud = new BlogCrudService();
        $guard = BlogServices::writeGuard();
        $user = AuthServices::auth()->currentUser();
        $article = $crud->find($slug, $locale);

        if (null === $article || !$guard->owns($slug, $locale, $user)) {
            return $this->refused($slug);
        }

        $toRaw = $request->body('to');
        $to = is_string($toRaw) && in_array($toRaw, ['0', '1'], true)
            ? '1' === $toRaw
            : !$crud->isPublished($slug, $locale);

        $changed = $guard->setPublished($slug, $to, $locale, $user);

        if ($changed) {
            $this->record($request, $slug, $locale, $to);
        }

        if (AdminLayout::isHtmx($request)) {
            return $this->row($crud, $slug, $locale, $localeParam);
        }

        return Response::redirect($this->indexUrl($localeParam, $changed, $to));
    }

    /**
     * Cache flush, audit row and outbox event for one state change.
     */
    private function record(Request $request, string $slug, string $locale, bool $to): void
    {
        BlogPageCache::invalidate();
        AuditServices::log(
            $to ? 'article.publish' : 'article.unpublish',
            'article',
            ($to ? 'Published: ' : 'Unpublished: ') . $slug,
            $request->getClientIp(),
        );

        $this->recordEvent($slug, $locale, $to);
    }

    /**
     * The HTMX answer: this article's row, replaced in place.
     */
    private function row(BlogCrudService $crud, string $slug, string $locale, string $localeParam): Response
    {
        foreach ($crud->list($locale) as $article) {
            if ($article['slug'] === $slug) {
                return Response::html(ArticlesTableRenderer::row($article, $localeParam, $this->router()));
            }
        }

        return Response::html('');
    }

    private function indexUrl(string $localeParam, bool $changed, bool $to): string
    {
        $toast = $changed
            ? ($to ? 'Article+published' : 'Article+unpublished')
            : 'Article+not+found';

        return $this->url('articles.index') . '?locale=' . $localeParam . '&toast=' . $toast;
    }

    /**
     * Record the state change in the outbox and queue its delivery.
     *
     * The front matter is already written when this runs, so a failure here
     * must not surface as a failed publish — the cache flush above and the
     * audit entry are both idempotent, and the publish itself succeeded.
     *
     * The actor comes from the same session lookup the audit entry uses, so
     * the event and its audit row can never disagree about who acted.
     */
    private function refused(string $slug): Response
    {
        return Response::html(
            AdminLayout::render('Not your article', BlogServices::ownership()->refusal($slug), router: $this->router()),
            403,
        );
    }

    private function recordEvent(string $slug, string $locale, bool $published): void
    {
        $user = AuditServices::auth()->currentUser();

        $recorded = OutboxServices::articleEvent(
            $published ? OutboxServices::ARTICLE_PUBLISHED : OutboxServices::ARTICLE_UNPUBLISHED,
            $slug,
            $locale,
            null === $user ? 'system' : $user->name(),
        );

        if ($recorded) {
            OutboxDrain::defer();
        }
    }
}
