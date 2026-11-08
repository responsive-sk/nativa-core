<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Actions;

use Modules\Core\Application\Services\DocsCatalog;
use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\ErrorPageRenderer;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\Markdown\MarkdownBlockContext;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use Themes\DocsPageTheme;

/**
 * DocsPageAction — GET /docs/{path*}: one published guide.
 *
 * The route parameter is resolved THROUGH the catalogue (DocsCatalog), never
 * onto the filesystem: a path that is not an entry answers 404 before any file
 * is opened, which is why `docs/dex/**`, the trace manifests and the audit
 * reports stay unreachable even when they sit in the working tree.
 *
 * `{path*}` rather than `{slug}` so the URL mirrors the file tree — a nested
 * guide costs one catalogue line instead of an invented flat name. The bare
 * `/docs` path is unambiguous: Router matches literal patterns before
 * parameterized ones, so `/docs` (the index) wins over the `/{slug}` page
 * catch-all. The locale prefixes come for free — LocaleMiddleware strips `/sk`
 * and `/en` before routing, so all three paths reach this action and render the
 * same English guide.
 *
 * The guide declares its own language (`lang: en`) instead of following the
 * visitor's locale, and carries `noindex` with no canonical: a Slovak shell
 * around English prose is read aloud with Slovak phonetics, and a dev-facing
 * guide has no business in an index.
 */
#[Route(path: '/docs/{path*}', method: 'GET', name: 'docs-page')]
final readonly class DocsPageAction
{
    private DocsCatalog $catalog;

    /** @param DocsCatalog|null $catalog Test seam for a deployment without docs/. */
    public function __construct(?DocsCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? new DocsCatalog();
    }

    public function handle(Request $request): Response
    {
        $key = (string) ($request->routeParam('path') ?? '');
        $guide = $this->catalog->read($key);

        if (null === $guide) {
            return $this->notFound($request);
        }

        return $this->render($request, $key, $guide);
    }

    /**
     * Render a guide inside the FrontendLayout shell, in the active theme.
     *
     * @param array{title: string, body: string} $guide
     */
    private function render(Request $request, string $key, array $guide): Response
    {
        $theme = (new ThemeResolver())->resolve($request);
        $module = ThemeKernel::for($theme);
        $prefix = $module->prefix();

        // LocaleMiddleware sets this attribute before routing; a request that
        // reaches the action without one (a direct call) resolves to the
        // configured default rather than throwing on a null.
        $active = $request->getAttribute('locale');
        $locale = ContentServices::mapper()->code(is_string($active) ? $active : '');

        // Same pipeline as pages and articles — the renderer is the one the
        // projects already read their content through, and the theme owns the
        // chrome, so this surface cannot drift away from either.
        $renderer = new MarkdownRenderer();
        $bodyHtml = $renderer->render(
            $guide['body'],
            new MarkdownBlockContext($locale, $prefix),
        );

        // A guide is read, not scanned: it gets the floating contents rail the
        // articles have had since Boson, and a prose column bounded by measure
        // rather than by the viewport. Both are emitted HERE, in the kernel's
        // surface, for two reasons: the rail is built from the headings the
        // renderer already extracts (`extractHeadings()` returns the anchors it
        // generated, so a link can never point at an id the page does not
        // have), and a theme that implements `DocsPageTheme` composes its own
        // document — a rail the action did not emit would be missing there.
        //
        // The markup is keyed on `data-docs-*` attributes, not on the theme's
        // class prefix: the stylesheet below travels with it, so every theme
        // gets the same reader layout without a rule in its own CSS file.
        $headings = $renderer->extractHeadings($bodyHtml);

        $content = $module instanceof DocsPageTheme
            ? $module->docsPage($key, $guide['title'], $bodyHtml)
            : '<section class="' . $prefix . '-section">'
                . '<h1 class="' . $prefix . '-section__title">' . FrontendLayout::e($guide['title']) . '</h1>'
                . '<div class="' . $prefix . '-article__body">' . $bodyHtml . '</div>'
                . '</section>';

        // One rail, never two; `ReaderLayout` owns that rule for every surface
        // that needs it (this one and the article detail in `Blog`).
        $content = FrontendLayout::readerLayout($content, $headings);

        return Response::html(FrontendLayout::render(
            $guide['title'] . ' — Nativa',
            $content,
            description: 'Repository guide: ' . $key,
            currentPath: '/docs/' . $key,
            lang: DocsCatalog::LANGUAGE,
            noindex: true,
            theme: $theme,
        ));
    }

    /**
     * A styled 404 for an unpublished, unsafe or absent guide — the same shell
     * as the page 404, so a deployment that ships no `docs/` reads as a missing
     * document rather than a broken site.
     */
    private function notFound(Request $request): Response
    {
        return ErrorPageRenderer::response(
            404,
            'Not Found',
            'That guide is not published.',
            $request->getPathInfo(),
            DocsCatalog::LANGUAGE,
        );
    }
}
