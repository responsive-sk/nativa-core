<?php

declare(strict_types = 1);

namespace Modules\Pages\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Media\Interfaces\HTTP\MediaEditorHelper;
use Modules\Pages\Application\Services\PageCrudService;
use Modules\Validation\Validation\Validator;

/**
 * Pages create — GET/POST /mark/pages/create.
 *
 * GET: renders the page creation form with fields for title, slug,
 *      seo_title, seo_description, image (media picker), published, and markdown body.
 * POST: validates input, generates slug, saves the .md file, and
 *       redirects to the pages index.
 */
#[Route(path: '/mark/pages/create', method: 'GET|POST', name: 'pages.create')]
final class PagesCreateAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleCreate($request);
        }

        $locale = $this->resolveLocale($request);

        return Response::html($this->form([], $locale));
    }

    private function handleCreate(Request $request): Response
    {
        $locale = $this->resolveLocale($request);
        $data = $this->extractForm($request);
        $errors = $this->validate($data);

        if ([] !== $errors) {
            return Response::html($this->form($data, $locale, $errors), 400);
        }

        $service = new PageCrudService();
        $slug = '' !== $data['slug'] ? $data['slug'] : $service->generateSlug($data['title']);

        // isSlugTaken, not exists: a slug that is somebody's ALIAS is taken
        // too — creating a page there would shadow a live redirect.
        if ($service->isSlugTaken($slug, $locale)) {
            return Response::html($this->form($data, $locale, ['Slug is already in use (as a page or a redirect).']), 400);
        }

        $meta = $this->buildMeta($data);
        $service->save($slug, $meta, $data['body'], $locale);

        CacheInvalidator::flush();
        AuditServices::log(
            'page.create',
            'page',
            'Created: ' . $data['title'] . ' (' . $slug . ')',
            $request->getClientIp(),
        );

        $redirect = $this->url('pages.index') . '?locale=' . $locale . '&toast=Page+created+successfully';

        // HTMX: return redirect header for partial update.
        if (AdminLayout::isHtmx($request)) {
            $response = Response::html('');
            $response->setHeader('HX-Redirect', $redirect);

            return $response;
        }

        return Response::redirect($redirect);
    }

    /**
     * @param array<string, string> $data
     * @param list<string>          $errors
     */
    private function form(array $data, string $locale, array $errors = []): string
    {
        $errorHtml = AdminLayout::messages($errors, 'err');
        $csrf = AdminLayout::csrfField();

        $localeParam = ContentServices::mapper()->languageCode($locale);
        $backHref = $this->url('pages.index') . '?locale=' . $localeParam;

        $content = '<form method="POST" action="' . AdminLayout::e($this->url('pages.create')) . '?locale=' . $localeParam . '"'
            . ' hx-post="' . AdminLayout::e($this->url('pages.create')) . '?locale=' . $localeParam . '"'
            . ' hx-indicator="#create-spinner">'
            . $errorHtml
            . $csrf
            . $this->formFields($data, $locale)
            . '<div class="m-filter-bar">'
            . '<button class="m-btn m-btn-primary" type="submit">'
            . '<span class="htmx-indicator" id="create-spinner">⏳</span> Create page</button>'
            . '<a class="m-btn m-btn-ghost" href="' . $backHref . '">Cancel</a>'
            . '</div>'
            . '</form>'
            . MediaEditorHelper::editorScript($this->router(), 'body');

        return AdminLayout::render('New page', $content, 'pages', router: $this->router());
    }

    /**
     * @param array<string, string> $data
     */
    private function formFields(array $data, string $locale): string
    {
        $e = static fn (string $key, string $default = ''): string => AdminLayout::e($data[$key] ?? $default);

        $localeInput = '<input type="hidden" name="locale" value="' . $e('locale', $locale) . '">';
        $titleValue = $e('title');
        $slugValue = $e('slug');
        $translationValue = $e('translation');
        $formValue = $e('form');
        $seoTitleValue = $e('seo_title');
        $seoDescValue = $e('seo_description');
        $imageValue = $e('image');
        $publishedChecked = '1' === ($data['published'] ?? '') ? 'checked' : '';
        $bodyValue = $e('body');

        return ViewRenderer::render('Modules/Pages/Interfaces/HTTP/templates/page-create.phtml', ['formValue' => $formValue, 'bodyValue' => $bodyValue, 'imageValue' => $imageValue, 'localeInput' => $localeInput, 'publishedChecked' => $publishedChecked, 'seoDescValue' => $seoDescValue, 'seoTitleValue' => $seoTitleValue, 'slugValue' => $slugValue, 'titleValue' => $titleValue, 'translationValue' => $translationValue]);
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
            'form'            => strtolower(trim($str($request->body('form')))),
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
                'title.required'   => 'Title is required.',
                'body.required'    => 'Content is required.',
                'translation.regex' => 'Translation slug must be URL-safe.',
                'form.regex'        => 'The form slug must be lowercase letters, digits, `_` or `-` (max 80).',
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
        $meta = [
            'title'           => $data['title'],
            'seo_title'       => $data['seo_title'],
            'seo_description' => $data['seo_description'],
            'published'       => '1' === $data['published'],
        ];

        if ('' !== $data['image']) {
            $meta['image'] = $data['image'];
        }

        if ('' !== $data['translation']) {
            $meta['translation'] = $data['translation'];
        }

        if ('' !== $data['form']) {
            $meta['form'] = $data['form'];
        }

        return $meta;
    }

    private function resolveLocale(Request $request): string
    {
        $localeParam = $request->queryString('locale', 'sk');

        return ContentServices::mapper()->code($localeParam);
    }
}
