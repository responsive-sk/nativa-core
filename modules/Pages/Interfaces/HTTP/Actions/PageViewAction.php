<?php

declare(strict_types = 1);

namespace Modules\Pages\Interfaces\HTTP\Actions;

use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Application\Services\StructuredDataService;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\ErrorPageRenderer;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\Markdown\MarkdownBlockContext;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use Modules\Locales\Domain\Model\Locale;
use Modules\Pages\Application\Services\PageCrudService;

/**
 * Page view — public page at /{slug}.
 *
 * Renders a published page inside the FrontendLayout shell with SEO meta.
 * Returns a styled 404 page for unpublished or non-existent pages.
 *
 * NOTE: This route is a catch-all for root-level paths. It must be registered
 * AFTER all other routes to avoid conflicts (/, /blog, /mark, etc.).
 */
#[Route(path: '/{slug}', method: 'GET', name: 'page.view')]
final class PageViewAction
{
    public function handle(Request $request): Response
    {
        $slug = (string) ($request->routeParam('slug') ?? '');

        // Skip empty slugs
        if ('' === $slug) {
            return $this->notFound($request);
        }

        // Resolve locale from the URL prefix (set by LocaleMiddleware)
        $locale = $this->resolveLocale($request);

        $service = new PageCrudService();
        $page = $service->find($slug, $locale);

        // A slug with no page of its own may still be a page's PREVIOUS slug:
        // the rename left it in `aliases:`, so every old URL keeps working.
        if (null === $page) {
            return $this->aliasRedirect($request, $slug, $locale, $service);
        }

        // Not published — styled 404. Never redirect at an unpublished page:
        // a 301 that lands on a 404 is a broken promise.
        if (true !== ($page['meta']['published'] ?? false)) {
            return $this->notFound($request);
        }

        return $this->published($request, $slug, $page, $locale, $service);
    }

    /**
     * 301 a renamed page's previous slug to its current URL — the redirect
     * answers only when the target page is PUBLISHED, and the query string is
     * preserved (the Router's trailing-slash canonicalization is the
     * behavioral precedent). A refusal carries no Location header.
     */
    private function aliasRedirect(Request $request, string $slug, string $locale, PageCrudService $service): Response
    {
        $target = $service->aliasTarget($slug, $locale);

        if (null === $target || !$service->isPublished($target, $locale)) {
            return $this->notFound($request);
        }

        $path = $this->pageHref($locale, $target);
        $query = http_build_query($request->getQueryParams());

        error_log(sprintf('[pages] alias redirect %s -> %s locale=%s', $slug, $target, $locale));

        return Response::redirect('' === $query ? $path : $path . '?' . $query, 301);
    }

    /**
     * Render a published page inside the FrontendLayout shell with SEO meta.
     * The CSS prefix comes from the resolved theme module (theme-as-module).
     *
     * @param array{meta: array<string, mixed>, body: string} $page
     */
    private function published(Request $request, string $slug, array $page, string $locale, PageCrudService $service): Response
    {
        $theme = (new ThemeResolver())->resolve($request);
        $renderer = new MarkdownRenderer();
        $bodyHtml = $renderer->render(
            $this->withEmbeddedForm($page['body'], $page['meta']),
            new MarkdownBlockContext(
                $locale,
                ThemeKernel::for($theme)->prefix(),
                $request->getPathInfo(),
                $this->blockState($request),
            ),
        );

        $title = $this->pageTitle($page, $slug);
        $seoTitle = is_string($page['meta']['seo_title'] ?? null) && '' !== $page['meta']['seo_title']
            ? $page['meta']['seo_title']
            : $title;
        $seoDescription = is_string($page['meta']['seo_description'] ?? null)
            ? $page['meta']['seo_description']
            : '';
        $links = $this->localeLinks($locale, $slug, $service);
        $canonicalUrl = $request->absoluteUrl($links[$locale]);

        $prefix = ThemeKernel::for($theme)->prefix();

        // The reader-questions block sits at the end of a page exactly as it sits at
        // the end of an article — INSIDE the theme's container, which is what
        // `articleTail()` does for an article. Appended after `</section>` it is a
        // sibling of the container instead of its child, so a theme that gives the
        // page its width on `.…-section` (Boson's `.bo-section`) renders the whole
        // questions box edge to edge.
        $content = '<section class="' . $prefix . '-section">'
            . '<h1 class="' . $prefix . '-section__title">' . FrontendLayout::e($title) . '</h1>'
            . '<div class="' . $prefix . '-article__body">' . $bodyHtml . '</div>'
            . $this->questionBlock($request, $slug, $locale, $prefix)
            . '</section>';

        $desc = '' !== $seoDescription ? $seoDescription : 'Nativa Minimal';

        return Response::html(FrontendLayout::render(
            $seoTitle . ' — Nativa',
            $content,
            description: $desc,
            currentPath: $request->getPathInfo(),
            canonicalUrl: $canonicalUrl,
            localeLinks: $links,
            hreflangLinks: $this->hreflangLinks($request, $links),
            ogType: 'website',
            structuredData: StructuredDataService::website($seoTitle, $canonicalUrl),
            theme: $theme,
        ));
    }

    // ─── Locale / SEO helpers (the blog's pair pattern, one family down) ──

    /**
     * locale → URL for the switcher: the current page plus its PAIRED page
     * under the other locale's OWN slug — the pair is what makes
     * /o-nas and /en/about one page in two languages.
     *
     * @return array<string, string>
     */
    private function localeLinks(string $locale, string $slug, PageCrudService $service): array
    {
        $links = [$locale => $this->pageHref($locale, $slug)];
        $pair = $service->translationOf($slug, $locale);

        if (null !== $pair) {
            $links[$this->otherLocale($locale)] = $this->pageHref($this->otherLocale($locale), $pair);
        }

        return $links;
    }

    private function pageHref(string $locale, string $slug): string
    {
        return ContentServices::mapper()->urlPrefix($locale) . '/' . $slug;
    }

    /**
     * The single other locale of the family — derived from the mapper, never
     * from an 'en_US' === $locale ternary that assumes which one is default.
     */
    private function otherLocale(string $locale): string
    {
        foreach (ContentServices::mapper()->locales() as $code) {
            if ($code !== $locale) {
                return $code;
            }
        }

        return $locale;
    }

    /**
     * @param array<string, string> $links
     *
     * @return array<string, string>|null
     */
    private function hreflangLinks(Request $request, array $links): ?array
    {
        if (count($links) < 2) {
            return null;
        }

        $baseUrl = $request->absoluteUrl('');
        $hreflang = [];

        foreach ($links as $code => $path) {
            $hreflang[Locale::fromString($code)->language()] = $baseUrl . $path;
        }

        // x-default points at the configured default locale's version; fall
        // back to the first available link when this page is not paired.
        $defaultCode = ContentServices::mapper()->defaultLocale();
        $fallback = $links[$defaultCode] ?? (string) reset($links);

        if ('' !== $fallback) {
            $hreflang['x-default'] = $baseUrl . $fallback;
        }

        return $hreflang;
    }

    /**
     * The shared, theme-aware 404: `ErrorPageRenderer` resolves the active
     * theme from the request path and renders the theme's own error block.
     * The previous local markup was hardcoded to the kernel starter's `st-`
     * vocabulary and passed no theme, so a boson site answered an unknown URL
     * in another theme's shell — the exact defect the shared renderer exists
     * to prevent.
     */
    private function notFound(Request $request): Response
    {
        return ErrorPageRenderer::response(
            404,
            'Not Found',
            'The requested page could not be found.',
            $request->getPathInfo(),
        );
    }

    /**
     * @param array{meta: array<string, mixed>, body: string} $page
     */
    private function pageTitle(array $page, string $slug): string
    {
        $title = $page['meta']['title'] ?? null;

        return is_string($title) && '' !== $title ? $title : ucfirst($slug);
    }

    /**
     * The reader-questions block for this page, with the visitor's own
     * validation errors and old input when a submission came back from
     * `QuestionSubmitAction`, and the PRG confirmation when the `question-sent`
     * marker is present.
     *
     * Only ever reached for a PUBLISHED page — the draft gate answers 404
     * first — so a reader can never see a box on text they cannot read.
     */
    private function questionBlock(Request $request, string $slug, string $locale, string $prefix): string
    {
        // Guarded by class_exists, never imported: a page is complete
        // without its questions box, so Pages installs and renders without
        // Questions. A use-statement here would close an install-order
        // cycle (Pages -> Questions -> Pages).
        if (!class_exists('Modules\\Questions\\Interfaces\\HTTP\\QuestionBlockRenderer')) {
            return '';
        }

        $errors = $request->getAttribute('question_errors', []);
        $old = $request->getAttribute('question_old', []);

        return \Modules\Questions\Interfaces\HTTP\QuestionBlockRenderer::render(
            $slug,
            \Modules\Questions\Domain\Entities\Question::SUBJECT_PAGE,
            $locale,
            is_array($errors) ? $errors : [],
            is_array($old) ? $old : [],
            $prefix,
            '1' === $request->query('question-sent'),
        );
    }

    /**
     * The re-render state a submission action handed over, keyed by block.
     *
     * `form_errors`, `form_invalid` and `form_old` are written by
     * `FormSubmitAction` before it dispatches here, exactly as
     * `question_errors` and `question_old` are written by
     * `QuestionSubmitAction`. They stay request attributes rather than
     * rendered parameters, so this action names no Forms class.
     *
     * @return array<string, array{errors: list<string>, invalid: list<string>, old: array<string, string>, sent: bool}>
     */
    private function blockState(Request $request): array
    {
        return ['forms' => [
            'errors'  => $request->getAttribute('form_errors', []),
            'invalid' => $request->getAttribute('form_invalid', []),
            'old'     => $request->getAttribute('form_old', []),
            'sent'    => '1' === $request->query('form-sent'),
        ]];
    }

    private function resolveLocale(Request $request): string
    {
        // The locale attribute set by LocaleMiddleware (it strips the
        // /{lang} prefix before routing, so getPathInfo() no longer carries
        // it). It already holds the configured default for a bare request.
        $locale = $request->getAttribute('locale');

        return ContentServices::mapper()->code(is_string($locale) ? $locale : '');
    }

    /**
     * `form: <slug>` in front matter embeds a form at the end of the body.
     *
     * The action only knows the front-matter KEY — the placeholder travels as
     * markdown and the Forms module's markdown block expands it, so neither
     * this action nor MarkdownRenderer imports `Modules\Forms`. The slug is
     * pattern-guarded here: front matter is content, and content must not be
     * able to inject anything into the placeholder.
     *
     * @param array<string, mixed> $meta
     */
    private function withEmbeddedForm(string $body, array $meta): string
    {
        $slug = is_string($meta['form'] ?? null) ? trim($meta['form']) : '';

        if ('' === $slug || 1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            return $body;
        }

        return $body . "\n\n{{form:" . $slug . "}}\n";
    }
}
