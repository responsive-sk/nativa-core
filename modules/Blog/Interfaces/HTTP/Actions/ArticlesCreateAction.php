<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Blog\Application\Services\BlogCrudService;
use Modules\Blog\Application\Services\CoverJobs;
use Modules\Blog\Application\Services\BlogPageCache;
use Modules\Cardboard\Application\Services\AdminLocale;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Users\Interfaces\HTTP\AuthServices;
use Modules\Validation\Validation\Validator;

/**
 * Articles create — GET/POST /mark/articles/create.
 *
 * GET: renders the article creation form with fields for title, slug,
 *      date, category, tags, excerpt, author, image (media picker),
 *      members_only, translation, and markdown body.
 * POST: validates input, generates slug, saves the .md file, and
 *       redirects to the articles index.
 */
#[Route(path: '/mark/articles/create', method: 'GET|POST', name: 'articles.create')]
final class ArticlesCreateAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleCreate($request, ArticleFormPayload::extract($request));
        }

        $locale = $this->resolveLocale($request);

        return Response::html($this->form([], $locale, [], $request));
    }

    /**
     * @param array<string, string> $data
     */
    private function handleCreate(Request $request, array $data): Response
    {
        $locale = $this->resolveLocale($request);
        $errors = $this->validate($data);

        if ([] !== $errors) {
            return Response::html($this->form($data, $locale, $errors, $request), 400);
        }

        $service = new BlogCrudService();
        $slug = '' !== $data['slug'] ? $data['slug'] : $service->generateSlug($data['title']);

        if ($service->exists($slug, $locale)) {
            return Response::html($this->form($data, $locale, ['Article with this slug already exists.']), 400);
        }

        $meta = array_merge(
            ArticleFormPayload::passthroughMeta($request),
            $this->buildMeta($this->stampOwnByline($data)),
        );
        $service->save($slug, $meta, $data['body'], $locale);

        $auth = AuditServices::auth();
        $userId = $auth->currentUserId();
        $actorName = null !== $userId ? ($auth->currentUser()?->name() ?? 'admin') : 'admin';

        BlogPageCache::invalidate();

        // The cover is derived work: slow, and nothing in this request should
        // wait for it. Queued where the cache is already flushed for a content
        // write — see CoverJobs for why this is NOT the outbox publish event,
        // which fires on publish/unpublish and on nothing else.
        CoverJobs::request($slug, $locale);

        AuditServices::log(
            'article.create',
            'article',
            'Created: ' . $data['title'] . ' (' . $slug . ')',
            $request->getClientIp(),
        );

        if (AdminLayout::isHtmx($request)) {
            $response = Response::html('');
            $response->setHeader('HX-Redirect', $this->url('articles.index') . '?locale=' . $locale . '&toast=Article+created+successfully');

            return $response;
        }

        return Response::redirect($this->url('articles.index') . '?locale=' . $locale . '&toast=Article+created+successfully');
    }

    /**
     * @param array<string, string> $data
     * @param list<string>          $errors
     */
    private function form(array $data, string $locale, array $errors = [], ?Request $request = null): string
    {
        $errorHtml = AdminLayout::messages($errors, 'err');
        $csrf = AdminLayout::csrfField();
        $e = static fn (string $key, string $default = ''): string => AdminLayout::e($data[$key] ?? $default);

        $localeParam = ContentLocaleServices::mapper()->languageCode($locale);
        $backHref = $this->url('articles.index') . '?locale=' . $localeParam;

        $htmx = null !== $request && AdminLayout::isHtmx($request);
        $hxPost = $htmx ? ' hx-post="' . AdminLayout::e($this->url('articles.create')) . '?locale=' . $localeParam . '" hx-indicator="#create-spinner"' : '';
        $spinner = '<span class="htmx-indicator" id="create-spinner">⏳</span> ';

        $content = '<form method="POST" action="' . AdminLayout::e($this->url('articles.create')) . '?locale=' . $localeParam . '"' . $hxPost . '>'
            . $errorHtml
            . $this->formFields($csrf, $data, $locale)
            . '<div class="m-filter-bar">'
            . '<button class="m-btn m-btn-primary" type="submit">' . $spinner . 'Create article</button>'
            . '<a class="m-btn m-btn-ghost" href="' . $backHref . '">Cancel</a>'
            . '</div>'
            . '</form>'
            . ArticlesFormHelper::mediaPickerScript($this->router())
            . ArticlesFormHelper::formGuardScript();

        return AdminLayout::render('New article', $content, 'articles', router: $this->router());
    }

    /**
     * @param array<string, string> $data
     */
    private function formFields(string $csrf, array $data, string $locale): string
    {
        $e = static fn (string $key, string $default = ''): string => AdminLayout::e($data[$key] ?? $default);

        $localeInput = '<input type="hidden" name="locale" value="' . $e('locale', $locale) . '">';
        $dateValue = $e('date', date('Y-m-d'));
        $titleValue = $e('title');
        $slugValue = $e('slug');
        $categoryValue = $e('category');
        $categoryField = ArticlesFormHelper::categoryInput($data['category'] ?? '', $locale);
        $tagsValue = $e('tags');
        $tagsField = ArticlesFormHelper::tagsInput($data['tags'] ?? '', $locale);
        $excerptValue = $e('excerpt');
        $authorField = ArticlesFormHelper::authorSelect($data['author_email'] ?? '', $locale);
        $imageValue = $e('image');
        $translationValue = $e('translation');
        $bodyValue = $e('body');
        $membersChecked = '1' === ($data['members_only'] ?? '') ? 'checked' : '';
        // New articles default to published — the checkbox starts checked.
        $publishedChecked = '1' === ($data['published'] ?? '1') ? 'checked' : '';

        return ViewRenderer::render('Modules/Blog/Interfaces/HTTP/templates/article-create.phtml', ['authorField' => $authorField, 'bodyValue' => $bodyValue, 'categoryField' => $categoryField, 'csrf' => $csrf, 'dateValue' => $dateValue, 'excerptValue' => $excerptValue, 'imageValue' => $imageValue, 'localeInput' => $localeInput, 'membersChecked' => $membersChecked, 'publishedChecked' => $publishedChecked, 'slugValue' => $slugValue, 'tagsField' => $tagsField, 'titleValue' => $titleValue, 'translationValue' => $translationValue]);
    }

    /**
     * @param array<string, string> $data
     *
     * @return list<string>
     */
    private function validate(array $data): array
    {
        // Title and date arrive trimmed from the form payload, so `required`
        // matches the old `=== ''` checks exactly. Body travels raw (base64
        // or plain), so a whitespace-only body is now rejected instead of
        // stored — the same deliberate tightening as UsersAction.
        return (new Validator())->validate(
            $data,
            [
                'title' => 'required',
                'date'  => 'required',
                'body'  => 'required',
            ],
            [
                'title.required' => 'Title is required.',
                'date.required'  => 'Date is required.',
                'body.required'  => 'Content is required.',
            ],
        )->all();
    }

    /**
     * @param array<string, string> $data
     *
     * @return array<string, mixed>
     */
    /**
     * A non-admin writes under their OWN byline.
     *
     * The form posts an `author_email` select so an administrator can attribute
     * a post to someone else. For anyone else the posted value is ignored and
     * the session user is stamped in — otherwise an author could file a post
     * under a colleague, and ownership would follow the lie.
     *
     * @param array<string, string> $data
     *
     * @return array<string, string>
     */
    private function stampOwnByline(array $data): array
    {
        $user = AuthServices::auth()->currentUser();

        if (null === $user || $user->hasRole('admin')) {
            return $data;
        }

        $data['author_email'] = $user->email();
        $data['author'] = $user->name();

        return $data;
    }

    /**
     * @param array<string, string> $data
     *
     * @return array<string, mixed>
     */
    private function buildMeta(array $data): array
    {
        $tags = '' !== $data['tags']
            ? array_map(trim(...), explode(',', $data['tags']))
            : [];

        $meta = [
            'title'        => $data['title'],
            'date'         => $data['date'],
            'tags'         => $tags,
            'excerpt'      => $data['excerpt'],
            'author'       => $data['author_email'],
            'author_email' => $data['author_email'],
            'category'     => $data['category'],
        ];

        if ('' !== $data['image']) {
            $meta['image'] = $data['image'];
        }
        if ('' !== $data['translation']) {
            $meta['translation'] = $data['translation'];
        }
        if ('1' === $data['members_only']) {
            $meta['members_only'] = true;
        }

        // Always persisted explicitly — an omitted flag would default the
        // article to published on the public frontend.
        $meta['published'] = '1' === ($data['published'] ?? '1');

        return $meta;
    }

    private function resolveLocale(Request $request): string
    {
        $localeRaw = $request->queryString('locale', 'sk');

        return AdminLocale::canonical($localeRaw);
    }
}
