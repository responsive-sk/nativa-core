<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogArticlesService;
use Modules\Blog\Application\Services\CoverJobs;
use Modules\Blog\Application\Services\BlogPageCache;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use Modules\Locales\Interfaces\HTTP\LocalesServices;
use Modules\Outbox\Interfaces\HTTP\SecurityEventServices;
use Modules\Users\Interfaces\HTTP\AuthServices;

/**
 * Inline body save from the public article page — POST /blog/{slug}/inline.
 *
 * ## Why this route exists
 *
 * The owner already had a way in: the "Edit" link that rides
 * `PageContext::$editUrl` points at `/mark/articles/{slug}/edit`. This route
 * exists so the round trip can stay ON the article — read it, fix a typo,
 * read it again — instead of bouncing the author out to the panel and back.
 *
 * ## What it deliberately does NOT do
 *
 * **It does not save front matter.** Not `author_email`, not `published`,
 * not `members_only`, not `translation`. The posted body is the ONLY thing
 * that reaches disk: the stored front matter is re-read from the file and
 * handed back unchanged. A form that posts a whole article is a form that
 * eventually posts somebody else's byline, and `/mark` needed exactly that
 * bug fixed once already (the TOCTOU in `ArticlesEditAction`).
 *
 * **It does not re-implement authorization.** `ArticleWriteGuard` is the
 * same gate the `/mark` route goes through. A second ownership check here
 * would eventually disagree with it, which is how the bulk endpoints ended
 * up deleting a colleague's article while every single-article route
 * returned 403.
 *
 * ## Why there is no sanitization pass
 *
 * None is needed, and saying so matters because it looks like an oversight:
 * the stored format is Markdown and `MarkdownRenderer` escapes everything it
 * did not generate itself. Raw `<script>` renders as text; `javascript:` URLs
 * are never linkified. So this route has exactly the XSS surface of the
 * `/mark` editor, which is none, because both write Markdown through
 * `BlogCrudService` and both render through `MarkdownRenderer`.
 *
 * CSRF is not checked here either — `CsrfMiddleware` guards every unsafe
 * method globally, which is why a new POST route is safe by construction
 * instead of by remembering.
 *
 * @internal
 *
 * @coversNothing
 */
#[Route(path: '/blog/{slug}/inline', method: 'POST', name: 'blog.inline.save')]
final class BlogInlineEditAction
{
    public function handle(Request $request): Response
    {
        $slug = $request->routeParam('slug');

        if (!is_string($slug) || '' === $slug) {
            return Response::html('Not found', 404);
        }

        $locale = BlogArticlesService::requestLocale($request);
        $body = $request->body('body');

        // A non-string body is a malformed request, NOT an empty article:
        // writing '' over a real article on a bad POST would destroy it.
        if (!is_string($body)) {
            return $this->refuse($request, $slug, $locale, 'Bad request', 'The submitted body was not text.', 400);
        }

        $article = BlogServices::crud()->find($slug, $locale);

        if (null === $article) {
            return Response::html('Not found', 404);
        }

        $user = AuthServices::auth()->currentUser();
        $guard = BlogServices::writeGuard();

        // One gate, called the same way the /mark route calls it. The stored
        // front matter travels with the save so the writer cannot change it.
        if (!$guard->save($slug, $article['meta'], $body, $locale, $user)) {
            return $this->refuse($request, $slug, $locale, 'Not your article', 'You can edit articles you wrote.', 403);
        }

        BlogPageCache::invalidate();

        // The cover is derived work: slow, and nothing in this request should
        // wait for it. Queued where the cache is already flushed for a content
        // write — see CoverJobs for why this is NOT the outbox publish event,
        // which fires on publish/unpublish and on nothing else.
        CoverJobs::request($slug, $locale);

        AuditServices::log(
            'article.inline-edit',
            'article:' . $slug,
            sprintf('Saved the body inline on the public page (%d bytes)', strlen($body)),
            $request->getClientIp(),
        );

        return $this->saved($request, $slug, $locale);
    }

    /**
     * A refusal is a 403, never a redirect: a redirect looks like success to
     * the person who clicked, and they would only learn the text was never
     * saved by noticing the article unchanged.
     *
     * Deliberately NO `Location` header here, and that is load-bearing rather
     * than tidiness. `Response::send()` sets the status first and then calls
     * `header('Location: …')`, and PHP overrides the status with 302 for any
     * `Location` header — so a "403 that also points at the article" leaves
     * the machine as a 302 whose body the browser throws away. The author is
     * returned to the article believing it saved. It is exactly the outcome
     * the paragraph above exists to prevent, reached by trying to help.
     *
     * A refused inline write is also the same category of fact as a refused
     * `/mark` write, so it leaves the same durable trace.
     */
    private function refuse(Request $request, string $slug, string $locale, string $title, string $detail, int $status): Response
    {
        error_log(sprintf('[inline-edit] refused slug=%s status=%d', $slug, $status));

        if (403 === $status) {
            SecurityEventServices::bulkRefused('articles', 'inline-edit');
        }

        // The notice carries the ACTIVE theme's prefix, not a hardcoded one:
        // this action can be reached under any of the four wired themes, and a
        // starter class on a magazine page is a class nobody styled.
        $prefix = ThemeKernel::for((new ThemeResolver())->resolve($request))->prefix();

        return Response::html(
            sprintf(
                '<div class="%s-inline-edit__notice" role="alert"><h2>%s</h2><p>%s</p></div>',
                self::e($prefix),
                self::e($title),
                self::e($detail),
            ),
            $status,
        );
    }

    /**
     * An HTMX post swaps the notice in place; a native form lands back on the
     * article with `?saved=1`, which the page turns into a confirmation.
     */
    private function saved(Request $request, string $slug, string $locale): Response
    {
        if ('true' === strtolower((string) $request->header('hx-request'))) {
            $prefix = ThemeKernel::for((new ThemeResolver())->resolve($request))->prefix();

            return Response::html(
                sprintf(
                    '<div class="%s-inline-edit__notice" role="status">%s</div>',
                    self::e($prefix),
                    self::e(LocalesServices::t('blog.saved', 'Saved.', $locale)),
                ),
                200,
            );
        }

        return Response::redirect($this->articleUrl($slug, $locale) . '?saved=1');
    }

    private function articleUrl(string $slug, string $locale): string
    {
        return '/blog/' . rawurlencode($slug);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
