<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogCrudService;
use Modules\Blog\Application\Services\CoverJobs;
use Modules\Blog\Application\Services\BlogPageCache;
use Modules\Cardboard\Application\Services\AdminLocale;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Users\Interfaces\HTTP\AuthServices;
use Modules\Validation\Validation\Validator;

/**
 * Articles edit — GET/POST /mark/articles/{slug}/edit.
 *
 * GET: renders the article edit form pre-filled with existing data.
 * POST: validates input, saves the .md file, and redirects to the index.
 */
#[Route(path: '/mark/articles/{slug}/edit', method: 'GET|POST', name: 'articles.edit')]
final class ArticlesEditAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $slug = (string) ($request->routeParam('slug') ?? '');
        $locale = $this->resolveLocale($request);
        $service = new BlogCrudService();
        $article = $service->find($slug, $locale);

        if (null === $article) {
            return Response::html(AdminLayout::render('Not found', '<div class="m-card"><p>Article not found.</p></div>', router: $this->router()), 404);
        }

        if (!BlogServices::writeGuard()->owns($slug, $locale, AuthServices::auth()->currentUser())) {
            return $this->refused($slug);
        }

        if ($request->isMethod('POST')) {
            $data = ArticleFormPayload::extract($request);

            return $this->handleUpdate($request, $data, $slug, $locale, $service);
        }

        return Response::html($this->form($article, $slug, $locale, [], [], $request));
    }

    /**
     * @param array<string, string> $data
     */
    private function handleUpdate(Request $request, array $data, string $slug, string $locale, BlogCrudService $service): Response
    {
        $errors = $this->validate($data);

        if ([] !== $errors) {
            $fallback = $service->find($slug, $locale) ?? ['meta' => [], 'body' => ''];
            $fallbackData = $this->normalizeArticle($fallback, $slug);
            $fallbackData['passthrough'] = ArticleFormPayload::passthroughValues($fallbackData)
                + ArticleFormPayload::passthroughMeta($request);
            $override = $data;
            $override['passthrough'] = $fallbackData['passthrough'];

            return Response::html($this->form($fallback, $slug, $locale, $override, $errors, $request), 400);
        }

        $meta = array_merge(ArticleFormPayload::passthroughMeta($request), $this->buildMeta($data));
        $saved = BlogServices::writeGuard()->save(
            $slug,
            $meta,
            $data['body'],
            $locale,
            AuthServices::auth()->currentUser(),
        );

        if (!$saved) {
            // Ownership was checked on GET and re-checked here. Between the two,
            // the session can change — the second check is the one that counts.
            return $this->refused($slug);
        }

        BlogPageCache::invalidate();

        // The cover is derived work: slow, and nothing in this request should
        // wait for it. Queued where the cache is already flushed for a content
        // write — see CoverJobs for why this is NOT the outbox publish event,
        // which fires on publish/unpublish and on nothing else.
        CoverJobs::request($slug, $locale);

        AuditServices::log(
            'article.edit',
            'article',
            'Edited: ' . $data['title'] . ' (' . $slug . ')',
            $request->getClientIp(),
        );

        return $this->saved($request, $locale);
    }

    /**
     * The 403 body, shared with the pre-save check.
     */
    private function refused(string $slug): Response
    {
        return Response::html(
            AdminLayout::render('Not your article', BlogServices::ownership()->refusal($slug), router: $this->router()),
            403,
        );
    }

    /**
     * Where a saved article sends the browser back to.
     */
    private function saved(Request $request, string $locale): Response
    {
        $localeParam = ContentServices::mapper()->languageCode($locale);
        $target = $this->url('articles.index') . '?locale=' . $localeParam . '&toast=Article+saved+successfully';

        if (AdminLayout::isHtmx($request)) {
            $response = Response::html('');
            $response->setHeader('HX-Redirect', $target);

            return $response;
        }

        return Response::redirect($target);
    }

    /**
     * @param array{meta: array<string, mixed>, body: string} $article
     * @param array<string, mixed>                            $override
     * @param list<string>                                    $errors
     */
    private function form(array $article, string $slug, string $locale, array $override = [], array $errors = [], ?Request $request = null): string
    {
        $data = $override ?: $this->normalizeArticle($article, $slug);
        $localeParam = ContentServices::mapper()->languageCode($locale);
        $slugEscaped = AdminLayout::e($slug);
        $backHref = $this->url('articles.index') . '?locale=' . $localeParam;
        $fields = $this->passthroughFields($data) . $this->formFields($data, $slug, $locale);
        $bodyValue = AdminLayout::e($data['body']);
        $questionContext = $this->questionContext($slug, $locale);

        $htmx = null !== $request && AdminLayout::isHtmx($request);
        $hxPost = $htmx ? ' hx-post="' . AdminLayout::e($this->url('articles.edit', ['slug' => $slugEscaped])) . '?locale=' . $localeParam . '" hx-indicator="#edit-spinner"' : '';
        $spinner = '<span class="htmx-indicator" id="edit-spinner">⏳</span> ';

        $content = '<form method="POST" action="' . AdminLayout::e($this->url('articles.edit', ['slug' => $slugEscaped])) . '?locale=' . $localeParam . '"' . $hxPost . '>'
            . AdminLayout::messages($errors, 'err')
            . AdminLayout::csrfField()
            . $fields
            . $questionContext
            . '<div class="m-card"><label class="m-label--field">Content (Markdown)</label>'
            . '<textarea id="body" name="body" rows="20" class="m-textarea--code">' . $bodyValue . '</textarea></div>'
            . '<div class="m-filter-bar">'
            . '<button class="m-btn m-btn-primary" type="submit">' . $spinner . 'Save changes</button>'
            . '<a class="m-btn m-btn-ghost" href="' . $backHref . '">Cancel</a>'
            . '</div>'
            . '</form>'
            . ArticlesFormHelper::mediaPickerScript($this->router())
            . ArticlesFormHelper::formGuardScript();

        return AdminLayout::render('Edit: ' . $slug, $content, 'articles', router: $this->router());
    }

    private function questionContext(string $slug, string $locale): string
    {
        // Same guard as the article embeds: the edit form works without the
        // read-only questions sidebar.
        if (!class_exists('Modules\\Questions\\Application\\QuestionsServices')) {
            return '';
        }

        $questions = \Modules\Questions\Application\QuestionsServices::repository()
            ->findBySubject(\Modules\Questions\Domain\Entities\Question::SUBJECT_ARTICLE, $slug, $locale);
        $html = '';

        foreach ($questions as $question) {
            $html .= '<li><p>' . AdminLayout::e($question->text()) . '</p>'
                . '<p class="m-muted">Tried: ' . AdminLayout::e($question->attemptedAnswer()) . '</p></li>';
        }

        if ([] === $questions) {
            return '';
        }

        return '<section class="m-card" data-question-context><h2>Reader questions (read-only)</h2><ul>'
            . $html . '</ul></section>';
    }

    /**
     * @param array{meta: array<string, mixed>, body: string} $article
     *
     * @return array<string, mixed>
     */
    private function normalizeArticle(array $article, string $slug): array
    {
        $meta = $article['meta'];

        $passthrough = [];

        foreach ($meta as $key => $value) {
            if (is_scalar($value)) {
                $passthrough[$key] = (string) $value;
            }
        }

        return [
            'passthrough' => $passthrough,
            'title'        => is_string($meta['title'] ?? null) ? $meta['title'] : '',
            'slug'         => $slug,
            'date'         => is_string($meta['date'] ?? null) ? $meta['date'] : '',
            'category'     => is_string($meta['category'] ?? null) ? $meta['category'] : '',
            'tags'         => is_array($meta['tags'] ?? null) ? implode(', ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $meta['tags'])) : '',
            'excerpt'      => is_string($meta['excerpt'] ?? null) ? $meta['excerpt'] : '',
            'author_email' => is_string($meta['author_email'] ?? null) ? $meta['author_email'] : '',
            'image'        => is_string($meta['image'] ?? null) ? $meta['image'] : '',
            'members_only' => true === ($meta['members_only'] ?? false) ? '1' : '',
            'published'    => true === ($meta['published'] ?? true) ? '1' : '',
            'translation'  => is_string($meta['translation'] ?? null) ? $meta['translation'] : '',
            'body'         => $article['body'],
        ];
    }

    /**
     * Hidden "meta[key]" inputs preserving front-matter keys the form does
     * not own (series, series-part, version, views, …) — dropping them
     * would silently rewrite article identity on save.
     *
     * @param array<string, mixed> $data
     */
    private function passthroughFields(array $data): string
    {
        $html = '';

        foreach (ArticleFormPayload::passthroughValues($data) as $key => $value) {
            $html .= '<input type="hidden" name="meta[' . AdminLayout::e($key) . ']" value="' . AdminLayout::e($value) . '">';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formFields(array $data, string $slug, string $locale): string
    {
        $val = static fn (string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
        $e = static fn (string $key, string $default = ''): string => AdminLayout::e($data[$key] ?? $default);
        $dateDefault = '' !== $val('date') ? $val('date') : date('Y-m-d');
        $localeInput = '<input type="hidden" name="locale" value="' . $e('locale', $locale) . '">';
        $slugEscaped = AdminLayout::e($slug);
        $imagePreviewDisplay = '' !== $val('image') ? '' : 'display:none;';
        $membersChecked = '1' === $data['members_only'] ? 'checked' : '';
        $publishedChecked = '1' === ($data['published'] ?? '1') ? 'checked' : '';

        return $localeInput
            . '<div class="m-card"><div class="m-form">'
            . '<label for="title">Title *</label>'
            . '<input type="text" id="title" name="title" value="' . $e('title') . '" required autofocus>'
            . '<label for="slug">Slug</label>'
            . '<input type="text" id="slug" name="slug" value="' . $slugEscaped . '" readonly style="opacity:.7;">'
            . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">'
            . '<div><label for="date">Date *</label><input type="date" id="date" name="date" value="' . $e('date', $dateDefault) . '" required></div>'
            . '<div><label for="category">Category</label>' . ArticlesFormHelper::categoryInput($val('category'), $locale) . '</div>'
            . '</div>'
            . '<label for="tags">Tags (comma-separated)</label>'
            . ArticlesFormHelper::tagsInput($val('tags'), $locale)
            . '<label for="excerpt">Excerpt</label>'
            . '<textarea id="excerpt" name="excerpt" rows="3">' . $e('excerpt') . '</textarea>'
            . '<label for="author_email">Author</label>'
            . ArticlesFormHelper::authorSelect($val('author_email'), $locale)
            . '<label for="image">Cover image URL</label>'
            . '<div style="display:flex;gap:8px;align-items:start;">'
            . '<input type="text" id="image" name="image" value="' . $e('image') . '" style="flex:1;">'
            . '<button type="button" class="m-btn m-btn-ghost" data-action="open-media-picker" data-target="image" style="white-space:nowrap;font-size:12px;">Pick from Media</button>'
            . '</div>'
            . '<div id="image-preview" style="margin-top:8px;' . $imagePreviewDisplay . '">'
            . '<img id="image-preview-img" src="' . $e('image') . '" alt="Preview" style="max-width:200px;max-height:120px;border-radius:8px;border:1px solid var(--cba-border);">'
            . '</div>'
            . '<label class="m-check" for="members_only"><input type="checkbox" id="members_only" name="members_only" value="1" ' . $membersChecked . '> Members only</label>'
            . '<label class="m-check" for="published"><input type="checkbox" id="published" name="published" value="1" ' . $publishedChecked . '> Published</label>'
            . '<label for="translation">Translation slug</label>'
            . '<input type="text" id="translation" name="translation" value="' . $e('translation') . '">'
            . '</div></div>';
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

        // Always persisted explicitly — an omitted flag would silently
        // re-publish a draft on save (public frontend defaults to true).
        $meta['published'] = '1' === ($data['published'] ?? '1');

        return $meta;
    }

    private function resolveLocale(Request $request): string
    {
        $localeRaw = $request->queryString('locale', 'sk');

        return AdminLocale::canonical($localeRaw);
    }
}
