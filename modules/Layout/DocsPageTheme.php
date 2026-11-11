<?php

declare(strict_types = 1);

namespace Modules\Layout;

/**
 * DocsPageTheme — the optional `/docs` surfaces a `ThemeModule` may compose.
 *
 * The docs routes live in the Docs module (`DocsIndexAction`, `DocsPageAction`)
 * and used to render a generic list and article body in the theme's class PREFIX
 * alone — `{prefix}-section`, `{prefix}-section__title`, `{prefix}-article__body`.
 * A theme whose vocabulary has no `-section` block (boson's does not) therefore
 * served an unstyled document under a fully styled header, and the fix belongs
 * in the theme, not in Core: only the theme knows what its document surface is
 * called.
 *
 * Optional for the same reason `AuthPageTheme` is. A theme that implements this
 * contract composes the surfaces; the actions fall back to the generic markup
 * otherwise, so no installed theme changes behaviour by not answering.
 *
 * The actions keep everything a theme must not decide: the catalogue boundary
 * (`Modules\Docs\Application\Services\DocsCatalog`), the Markdown pipeline
 * (`MarkdownRenderer` + `MarkdownBlockContext`), the `lang`/`noindex` head flags
 * and the `FrontendLayout` wrapper. The theme receives a guide that has already been
 * resolved, rendered and escaped where escaping applies.
 */
interface DocsPageTheme
{
    /**
     * The guide list, as the theme renders it.
     *
     * @param list<array{key: string, title: string, description: string}> $entries
     */
    public function docsIndex(array $entries): string;

    /**
     * One guide: the heading, the optional source path and the rendered body.
     *
     * `$bodyHtml` is raw by contract — it is `MarkdownRenderer` output, the
     * same pipeline articles and pages read.
     *
     * The reading layout — a prose column bounded by measure beside a floating
     * contents rail — is NOT the theme's obligation here. The action wraps what
     * this method returns in the kernel's reader layout unless the composed
     * document already carries a rail (measured: `aria-label="On this page"`),
     * so a theme gets the article-like reading surface without a rule in its own
     * stylesheet and without a second rail when it composes one itself.
     *
     * That is why the signature carries no `$headings` argument: PHP requires an
     * implementation to accept every optional parameter the interface declares,
     * so adding one would change the signature of every installed theme at once
     * — and the themes are a copy of `nativa-themes`, where a stray edit is
     * drift the gate reports (`make themes-check`).
     */
    public function docsPage(string $key, string $title, string $bodyHtml): string;
}
