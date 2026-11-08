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
use Modules\Media\Interfaces\HTTP\MediaEditorHelper;
use Modules\Pages\Application\Services\PageCrudService;
use Modules\Validation\Validation\Validator;

/**
 * Pages edit — GET/POST /mark/pages/{slug}/edit.
 *
 * GET: renders the page edit form pre-filled with existing data.
 * POST: validates input, saves the .md file, and redirects to the index.
 */
#[Route(path: '/mark/pages/{slug}/edit', method: 'GET|POST', name: 'pages.edit')]
final class PagesEditAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $slug = (string) ($request->routeParam('slug') ?? '');
        $locale = $this->resolveLocale($request);
        $service = new PageCrudService();
        $page = $service->find($slug, $locale);

        if (null === $page) {
            return Response::html(AdminLayout::render('Not found', '<div class="m-card"><p>Page not found.</p></div>', router: $this->router()), 404);
        }

        if ($request->isMethod('POST')) {
            return $this->handleUpdate($request, $slug, $locale, $service);
        }

        return Response::html($this->form($page, $slug, $locale));
    }

    private function handleUpdate(Request $request, string $slug, string $locale, PageCrudService $service): Response
    {
        $data = $this->extractForm($request);
        $errors = $this->validate($data);

        if ([] !== $errors) {
            $fallback = $service->find($slug, $locale) ?? ['meta' => [], 'body' => ''];

            return Response::html($this->form($fallback, $slug, $locale, $data, $errors), 400);
        }

        // A changed slug is a RENAME: rename() moves the file and appends the
        // old slug to `aliases:`, so every previously published URL answers a
        // 301. It refuses taken or invalid slugs — report, don't half-move.
        $newSlug = '' === $data['slug'] ? $slug : $data['slug'];

        if ($newSlug !== $slug && !$service->rename($slug, $newSlug, $locale)) {
            $fallback = $service->find($slug, $locale) ?? ['meta' => [], 'body' => ''];

            return Response::html($this->form($fallback, $slug, $locale, $data, ['Slug is invalid or already in use.']), 400);
        }

        // Re-read AFTER the rename: the saved meta must carry the aliases the
        // rename just appended — a form that rebuilds meta from the form alone
        // silently unlinks the page's redirect history.
        $current = $service->find($newSlug, $locale) ?? ['meta' => [], 'body' => ''];
        $meta = $this->buildMeta($data, $current['meta']);
        $service->save($newSlug, $meta, $data['body'], $locale);

        CacheInvalidator::flush();
        AuditServices::log(
            'page.edit',
            'page',
            'Edited: ' . $data['title'] . ' (' . $newSlug . ')',
            $request->getClientIp(),
        );

        $localeParam = ContentServices::mapper()->languageCode($locale);
        $redirect = $this->url('pages.index') . '?locale=' . $localeParam . '&toast=Page+saved+successfully';

        // HTMX: return redirect header for partial update.
        if (AdminLayout::isHtmx($request)) {
            $response = Response::html('');
            $response->setHeader('HX-Redirect', $redirect);

            return $response;
        }

        return Response::redirect($redirect);
    }

    /**
     * @param array{meta: array<string, mixed>, body: string} $page
     * @param array<string, string>                           $override
     * @param list<string>                                    $errors
     */
    private function form(array $page, string $slug, string $locale, array $override = [], array $errors = []): string
    {
        $data = $override ?: $this->normalizePage($page, $slug);
        $localeParam = ContentServices::mapper()->languageCode($locale);
        $slugEscaped = AdminLayout::e($slug);
        $backHref = $this->url('pages.index') . '?locale=' . $localeParam;
        $bodyValue = AdminLayout::e($data['body']);

        $actions = '<div class="m-filter-bar">'
            . '<button class="m-btn m-btn-primary" type="submit">'
            . '<span class="htmx-indicator" id="edit-spinner">⏳</span> Save changes</button>'
            . '<a class="m-btn m-btn-ghost" href="' . $backHref . '">Cancel</a>'
            . '</div>';

        $content = '<form method="POST" action="' . AdminLayout::e($this->url('pages.edit', ['slug' => $slugEscaped])) . '?locale=' . $localeParam . '"'
            . ' hx-post="' . AdminLayout::e($this->url('pages.edit', ['slug' => $slugEscaped])) . '?locale=' . $localeParam . '"'
            . ' hx-indicator="#edit-spinner">'
            . AdminLayout::messages($errors, 'err')
            . AdminLayout::csrfField()
            . $this->formFields($data, $slug, $locale)
            . '<div class="m-card"><label style="font-size:14px;font-weight:600;">Content (Markdown)</label>'
            . '<textarea id="body" name="body" rows="20" style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;padding:12px;border:1px solid var(--m-border);border-radius:8px;background:var(--m-surface);color:var(--m-ink);box-sizing:border-box;">' . $bodyValue . '</textarea></div>'
            . $actions
            . '</form>'
            . MediaEditorHelper::editorScript($this->router(), 'body');

        return AdminLayout::render('Edit: ' . $slug, $content, 'pages', router: $this->router());
    }

    /**
     * @param array{meta: array<string, mixed>, body: string} $page
     *
     * @return array<string, string>
     */
    private function normalizePage(array $page, string $slug): array
    {
        $meta = $page['meta'];

        return [
            'title'           => is_string($meta['title'] ?? null) ? $meta['title'] : '',
            'slug'            => $slug,
            'seo_title'       => is_string($meta['seo_title'] ?? null) ? $meta['seo_title'] : '',
            'seo_description' => is_string($meta['seo_description'] ?? null) ? $meta['seo_description'] : '',
            'image'           => is_string($meta['image'] ?? null) ? $meta['image'] : '',
            'translation'     => is_string($meta['translation'] ?? null) ? $meta['translation'] : '',
            'form'            => is_string($meta['form'] ?? null) ? $meta['form'] : '',
            'form_submitted'  => '1',
            'published'       => true === ($meta['published'] ?? false) ? '1' : '',
            'body'            => $page['body'],
        ];
    }

    /**
     * @param array<string, string> $data
     */
    private function formFields(array $data, string $slug, string $locale): string
    {
        $e = static fn (string $key, string $default = ''): string => AdminLayout::e($data[$key] ?? $default);
        $slugEscaped = AdminLayout::e($slug);
        $localeInput = '<input type="hidden" name="locale" value="' . $e('locale', $locale) . '">';
        $publishedChecked = '1' === $data['published'] ? 'checked' : '';

        return $localeInput
            . '<div class="m-card"><div class="m-form">'
            . '<label for="title">Title *</label>'
            . '<input type="text" id="title" name="title" value="' . $e('title') . '" required autofocus>'
            . '<label for="slug">Slug</label>'
            . '<input type="text" id="slug" name="slug" value="' . $slugEscaped . '" placeholder="changing it renames the page — old URLs keep working">'
            . '<label for="translation">Translation slug (the paired page\'s slug in the other language)</label>'
            . '<input type="text" id="translation" name="translation" value="' . $e('translation') . '" placeholder="slug-of-translated-version">'
            . '<label for="form">Embedded form slug (optional)</label>'
            . '<input type="text" id="form" name="form" value="' . $e('form') . '" placeholder="contact" maxlength="80">'
            . '<label for="seo_title">SEO title</label>'
            . '<input type="text" id="seo_title" name="seo_title" value="' . $e('seo_title') . '">'
            . '<label for="seo_description">SEO description</label>'
            . '<textarea id="seo_description" name="seo_description" rows="2">' . $e('seo_description') . '</textarea>'
            . '<label for="image">Cover image URL</label>'
            . '<div style="display:flex;gap:8px;align-items:start;">'
            . '<input type="text" id="image" name="image" value="' . $e('image') . '" style="flex:1;" placeholder="https://...">'
            . '<button type="button" class="m-btn m-btn-ghost" data-action="open-media-picker" data-target="image" style="white-space:nowrap;font-size:12px;">Pick from Media</button>'
            . '</div>'
            . '<div id="image-preview" style="margin-top:8px;display:none;">'
            . '<img id="image-preview-img" src="" alt="Preview" style="max-width:200px;max-height:120px;border-radius:8px;border:1px solid var(--cba-border);">'
            . '</div>'
            . '<label class="m-check" for="published"><input type="checkbox" id="published" name="published" value="1" ' . $publishedChecked . '> Published</label>'
            . '</div></div>';
    }

    /**
     * @return array<string, string>
     */
    private function extractForm(Request $request): array
    {
        $str = static fn (mixed $v): string => is_string($v) ? $v : '';

        return [
            'title'           => trim($str($request->body('title'))),
            'slug'            => trim($str($request->body('slug'))),
            'translation'     => trim($str($request->body('translation'))),
            // Absent and empty are different answers: an unrelated edit that
            // does not submit the field must keep the page's embedded form.
            'form'            => strtolower(trim($str($request->body('form')))),
            'form_submitted'  => null !== $request->body('form') ? '1' : '',
            'seo_title'       => trim($str($request->body('seo_title'))),
            'seo_description' => trim($str($request->body('seo_description'))),
            'image'           => trim($str($request->body('image'))),
            'published'       => '1' === $str($request->body('published')) ? '1' : '',
            'body'            => $str($request->body('body')),
            'locale'          => trim($str($request->body('locale')) ?: 'sk_SK'),
        ];
    }

    /**
     * @param array<string, string> $data
     *
     * @return list<string>
     */
    private function validate(array $data): array
    {
        // Inputs arrive trimmed from the form payload, so `required` matches
        // the old `=== ''` checks exactly; the translation slug is optional
        // (a non-required empty value skips `regex`).
        return (new Validator())->validate(
            $data,
            [
                'title'       => 'required',
                'body'        => 'required',
                'translation' => 'regex:/^[a-z0-9][a-z0-9_-]*$/',
                'form'        => 'regex:/^[a-z0-9][a-z0-9_-]{0,79}$/',
            ],
            [
                'title.required'    => 'Title is required.',
                'body.required'     => 'Content is required.',
                'translation.regex' => 'Translation slug must be URL-safe.',
                'form.regex'        => 'The form slug must be lowercase letters, digits, `_` or `-` (max 80).',
            ],
        )->all();
    }

    /**
     * The form owns six keys; everything else on disk (`form:`, `keywords:`,
     * `locale:`, `aliases:`, …) belongs to another feature and MUST survive
     * the save untouched — a form that rebuilds meta from its own fields
     * alone silently detaches the page's embedded form the first time an
     * admin renames an unrelated field. Only the filename-owned vestigial
     * `slug:` is dropped: the file IS the slug, re-adding it cements drift.
     *
     * @param array<string, string> $data
     * @param array<string, mixed>  $existing The meta as it sits on disk NOW
     *
     * @return array<string, mixed>
     */
    private function buildMeta(array $data, array $existing): array
    {
        $meta = $existing;
        unset($meta['slug']);

        $meta['title'] = $data['title'];
        $meta['seo_title'] = $data['seo_title'];
        $meta['seo_description'] = $data['seo_description'];
        $meta['published'] = '1' === $data['published'];

        if ('' !== $data['image']) {
            $meta['image'] = $data['image'];
        } else {
            unset($meta['image']);
        }

        if ('' !== $data['translation']) {
            $meta['translation'] = $data['translation'];
        } else {
            unset($meta['translation']);
        }

        // Only touch `form:` when the editor submitted it: this form is also
        // posted by paths that do not know the key, and the field's whole job
        // is to survive those.
        if ('1' === ($data['form_submitted'] ?? '')) {
            if ('' !== $data['form']) {
                $meta['form'] = $data['form'];
            } else {
                unset($meta['form']);
            }
        }

        return $meta;
    }

    private function resolveLocale(Request $request): string
    {
        $localeParam = $request->queryString('locale', 'sk');

        return ContentServices::mapper()->code($localeParam);
    }
}
