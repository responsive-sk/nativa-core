<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Actions;

use Modules\Core\Application\Services\DocsCatalog;
use Modules\Core\Application\Services\DocsSearchService;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use Themes\DocsPageTheme;

/**
 * DocsIndexAction — GET /docs: the published guides.
 *
 * The list is the catalogue, so a document nobody published cannot appear here
 * even though its file sits in the same directory: the index is not a directory
 * listing, it is the publication boundary rendered.
 *
 * Deliberately `noindex` and without a canonical URL: these are engineering
 * guides, and the 182 links into them come from blog articles that already
 * rank. Keeping the surface out of the index is also what keeps the canonical
 * sitemap requirement ("every public INDEXABLE URL") literally true, so this
 * route needs no change to a canonical requirement.
 *
 * `?q=` turns the same route into the guides' search: `DocsSearchService`
 * answers from the SAME catalogue the index renders, so a hit and a card are
 * two views of one publication boundary. The header's search field is routed
 * here on `/docs*` by `FrontendLayout` — the theme owns that markup and this
 * tree only holds a copy of it (`make themes-check`), so routing is the kernel's
 * half of the job.
 */
#[Route(path: '/docs', method: 'GET', name: 'docs-index')]
final readonly class DocsIndexAction
{
    private DocsCatalog $catalog;

    private DocsSearchService $search;

    /**
     * @param DocsCatalog|null       $catalog Test seam for a deployment without docs/.
     * @param DocsSearchService|null $search  Test seam for a query with no hits.
     */
    public function __construct(?DocsCatalog $catalog = null, ?DocsSearchService $search = null)
    {
        $this->catalog = $catalog ?? new DocsCatalog();
        $this->search = $search ?? new DocsSearchService($this->catalog);
    }

    public function handle(Request $request): Response
    {
        $theme = (new ThemeResolver())->resolve($request);
        $module = ThemeKernel::for($theme);
        $query = trim($request->queryString('q'));

        // Whether the page needs a field of its own is the THEME's answer, not
        // ours: `headerCarriesSearchField()` renders the shell and looks for the
        // input. Asking beats assuming, because assuming every shell has one is
        // what left four of the five bundled themes with no way into
        // `GET /docs?q=` once the index stopped composing it.
        $shellHasField = FrontendLayout::headerCarriesSearchField($request->getPathInfo(), $theme);

        if ('' !== $query) {
            return Response::html(FrontendLayout::render(
                'Search — Documentation',
                $this->searchContent($module->prefix(), $query, $this->search->search($query), $shellHasField),
                description: 'Search the guides that ship with the repository.',
                currentPath: $request->getPathInfo(),
                lang: DocsCatalog::LANGUAGE,
                noindex: true,
                theme: $theme,
            ));
        }

        // A theme with its own document surface composes the list; the
        // generic `{prefix}-section` markup stays the floor for every theme
        // that has no vocabulary for it.
        //
        // No field is prepended while the shell has one. On `/docs` the header's
        // field is routed here by `FrontendLayout` (`GET /docs?q=`), so a second
        // input beside it would be the same query in two boxes with one
        // destination. A shell that carries none gets the kernel's field back,
        // because the alternative is a page searchable only by typing the URL:
        // inside the section that holds the count and the rows when this tree
        // composes the list, and above the block for a `DocsPageTheme`, which
        // owns that markup and cannot be edited here (`make themes-check`).
        //
        // No wrapper and no rule set of our own. A kernel rule that zeroed the
        // page's top padding (the old `indexStyle()`) made `/docs` open 4em
        // higher than `/blog`, which is two surfaces of one site reading at two
        // different heights.
        return Response::html(FrontendLayout::render(
            'Documentation — Nativa',
            $module instanceof DocsPageTheme
                ? ($shellHasField ? '' : $this->searchField($module->prefix(), ''))
                    . $module->docsIndex($this->catalog->entries())
                : $this->content($module->prefix(), $shellHasField),
            description: 'The guides that ship with the repository, rendered by the site.',
            currentPath: $request->getPathInfo(),
            lang: DocsCatalog::LANGUAGE,
            noindex: true,
            theme: $theme,
        ));
    }

    /**
     * The guide list, in the active theme's own vocabulary.
     *
     * The list is bounded by measure (`data-docs-prose`, 68ch) the same way a
     * guide's body is: a card carries up to 180 characters of description, and
     * on a wide display an unbounded one runs the full viewport. The attribute is
     * the docs surface's own, not a theme prefix, so every theme renders the same
     * readable column without a rule in its stylesheet — see
     * `DocsPageAction::readerLayout()` for the rule set that travels with it.
     */
    private function content(string $prefix, bool $shellHasField): string
    {
        $html = '<section class="' . $prefix . '-section" data-docs-prose>'
            . '<h1 class="' . $prefix . '-section__title">Documentation</h1>'
            . ($shellHasField ? '' : $this->searchField($prefix, ''));

        $entries = $this->catalog->entries();

        if ([] === $entries) {
            return $html
                . '<p>No guides are published in this deployment.</p>'
                . '</section>';
        }

        // Grouped in the order the groups first appear, so the map's order still
        // decides the sequence: a guide is filed by its key's first segment and
        // the group label is that segment. `intro`, `core-philosophy` and the
        // module guides have no segment and therefore form the leading group —
        // the reading order the map was written in.
        $groups = [];

        foreach ($entries as $entry) {
            $at = strpos($entry['key'], '/');
            $group = false === $at ? '' : substr($entry['key'], 0, $at);
            $groups[$group][] = $entry;
        }

        $html .= '<div class="' . $prefix . '-article__body">';

        foreach ($groups as $group => $members) {
            if ('' !== $group) {
                $html .= '<h2>' . FrontendLayout::e($this->groupLabel($group)) . '</h2>';
            }

            $html .= '<ul>';

            foreach ($members as $entry) {
                $html .= '<li>'
                    . '<a href="/docs/' . FrontendLayout::e($entry['key']) . '">'
                    . FrontendLayout::e($entry['title']) . '</a>'
                    . ('' !== $entry['description'] ? ' — ' . FrontendLayout::e($entry['description']) : '')
                    . '</li>';
            }

            $html .= '</ul>';
        }

        return $html . '</div></section>';
    }

    /**
     * The search surface: the count and one row per hit, in a single section.
     *
     * The field the reader refines the query with is the shell's when the shell
     * has one, routed here by section. A theme without one gets the kernel's
     * field under the count, pre-filled with what was typed, because a miss a
     * reader cannot narrow is a dead end. `searchField()` is where that markup
     * lives.
     *
     * It is the kernel's own markup rather than `DocsPageTheme::docsIndex()`, and
     * the reason is measured on the rendered page: that method is the INDEX, so
     * it opens with its own page head (label, an `<h1>` reading "Documentation"
     * whatever was typed, and the subtitle about the guides that ship with the
     * repository) inside a `{prefix}-page` block. Composed under the kernel's
     * summary it put a second heading over the hits and more than three hundred
     * pixels of padding between the count and the first row, because a section's
     * bottom gap (8 rem), a page's top padding (4 em), a page head (3 em + 2.5
     * em) and a list margin (3 em) all stack. A reader who typed a word had to
     * scroll to find out whether it matched.
     *
     * The theme cannot be asked for a search-shaped variant instead: it is a
     * byte-copy of `nativa-themes` (`make themes-check`), so the choice is its
     * index surface or this one, and the miss sentence the theme carries —"no
     * guides are published" — is the wrong sentence for a query.
     *
     * @param list<array{key: string, title: string, description: string}> $results
     */
    private function searchContent(string $prefix, string $query, array $results, bool $shellHasField): string
    {
        $count = count($results);
        $escaped = FrontendLayout::e($query);

        $html = '<section class="' . $prefix . '-section" data-docs-prose>'
            . '<h1 class="' . $prefix . '-section__title">Search</h1>'
            . '<p>'
            . (0 === $count
                ? 'No guide matches "' . $escaped . '".'
                : $count . (1 === $count ? ' guide matches "' : ' guides match "') . $escaped . '".')
            . '</p>'
            . ($shellHasField ? '' : $this->searchField($prefix, $query));

        if (0 === $count) {
            return $html . '</section>';
        }

        return $html . $this->hits($prefix, $results) . '</section>';
    }

    /**
     * The hits themselves, in the vocabulary the theme already styles for a
     * guide's body, so the rows sit under the count instead of behind a second
     * page header.
     *
     * @param list<array{key: string, title: string, description: string}> $results
     */
    private function hits(string $prefix, array $results): string
    {
        $html = '<div class="' . $prefix . '-article__body"><ul>';

        foreach ($results as $result) {
            $html .= '<li>'
                . '<a href="/docs/' . FrontendLayout::e($result['key']) . '">'
                . FrontendLayout::e($result['title']) . '</a>'
                . ('' !== $result['description'] ? ' — ' . FrontendLayout::e($result['description']) : '')
                . '</li>';
        }

        return $html . '</ul></div>';
    }

    /**
     * The field a shell without one leaves the page.
     *
     * `{prefix}-search-form` is the vocabulary the themes already style for the
     * blog's field, so the guides' field arrives styled in every theme that has
     * one and readable in the rest. `action="/docs"` is the route this action
     * answers and `name="q"` is the query it reads, so field and route cannot
     * drift apart.
     */
    private function searchField(string $prefix, string $query): string
    {
        return '<form class="' . $prefix . '-search-form" method="get" action="/docs" role="search">'
            . '<label class="' . $prefix . '-sr-only" for="' . $prefix . '-docs-search">Search guides</label>'
            . '<input class="' . $prefix . '-search-form__input" id="' . $prefix . '-docs-search"'
            . ' type="search" name="q" placeholder="Search guides" value="'
            . FrontendLayout::e($query) . '" autocomplete="off">'
            . '<button class="' . $prefix . '-search-form__submit" type="submit">Search</button>'
            . '</form>';
    }

    /**
     * A group is a directory under `docs/`, so its label is the directory's own
     * name made readable — never a second list the kernel would have to keep in
     * sync with the tree. `kernel-status` reads as "Kernel status".
     */
    private function groupLabel(string $group): string
    {
        return ucfirst(str_replace(['-', '_'], ' ', $group));
    }
}
