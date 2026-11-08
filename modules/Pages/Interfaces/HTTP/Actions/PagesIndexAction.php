<?php

declare(strict_types = 1);

namespace Modules\Pages\Interfaces\HTTP\Actions;

use Modules\Cardboard\Application\Services\AdminLocale;
use Modules\Cardboard\Application\Services\BulkActions;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Pages\Application\Services\PageCrudService;
use Modules\Pages\Interfaces\HTTP\PagesTableRenderer;

/**
 * Pages index — GET /mark/pages.
 *
 * Lists all pages with edit/delete actions. SK/EN locale toggle.
 */
#[Route(path: '/mark/pages', method: 'GET', name: 'pages.index')]
final class PagesIndexAction
{
    use ResolvesRouteUrls;

    private const int PER_PAGE = 20;

    /** Whitelisted sortable columns (query ?sort= value). */
    private const array SORT_COLUMNS = ['date', 'title', 'published'];

    public function handle(Request $request): Response
    {
        // Allowlisted at the boundary: the raw param is re-emitted into
        // row/filter/create URLs, so anything outside sk/en would reflect
        // verbatim (AdminLocale::raw() is the identity on legit values).
        $localeRaw = AdminLocale::raw($request->queryString('locale', 'sk'));
        $locale = ContentServices::mapper()->code($localeRaw);
        $search = $request->queryString('q');
        $pageParam = $request->query('page');
        $page = max(1, (int) (is_string($pageParam) ? $pageParam : '1'));

        $pages = $this->loadPages($locale, $search, $request);

        $total = count($pages);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::PER_PAGE;
        $paginated = array_slice($pages, $offset, self::PER_PAGE);

        [$sort, $dir] = $this->sortState($request);

        $skClass = 'sk_SK' === $locale ? 'm-pill m-pill-active' : 'm-pill';
        $enClass = 'en_US' === $locale ? 'm-pill m-pill-active' : 'm-pill';

        $localeBar = '<div class="m-filter-bar">'
            . '<a class="' . $skClass . '" href="' . AdminLayout::e($this->url('pages.index') . '?locale=sk') . '">SK</a>'
            . '<a class="' . $enClass . '" href="' . AdminLayout::e($this->url('pages.index') . '?locale=en') . '">EN</a>'
            . '</div>';

        $grid = $this->renderGrid($paginated, $localeRaw, $search, $page, $totalPages, $total, $sort, $dir);

        $breadcrumb = AdminLayout::breadcrumb([
            ['label' => 'Dashboard', 'href' => $this->url('mark.dashboard')],
            ['label' => 'Pages'],
        ]);

        $content = $breadcrumb . $localeBar . $grid;

        // HTMX: return only the content div (no search input to avoid re-triggering).
        if (AdminLayout::isHtmx($request)) {
            return Response::html($this->renderContent($paginated, $localeRaw, $search, $page, $totalPages, $total, $sort, $dir));
        }

        $toast = '';
        $toastMessage = $request->queryString('toast');
        if ('' !== $toastMessage) {
            $toast = AdminLayout::toast(urldecode($toastMessage));
        }

        $recentPages = $this->recentItems($paginated, $localeRaw);

        return Response::html(AdminLayout::render(
            'Pages',
            $content . $toast,
            'pages',
            [['label' => 'New page', 'href' => $this->url('pages.create') . '?locale=' . $localeRaw]],
            $recentPages,
        router: $this->router()));
    }

    /**
     * Build recent items for the command palette.
     *
     * @param list<array{slug: string, title: string, published: bool, seo_title: string, seo_description: string}> $pages
     *
     * @return list<array{label: string, href: string, icon: string}>
     */
    private function recentItems(array $pages, string $locale): array
    {
        $recent = [];
        foreach (array_slice($pages, 0, 5) as $page) {
            $recent[] = [
                'label' => $page['title'],
                'href' => $this->url('pages.edit', ['slug' => $page['slug']]) . '?locale=' . $locale,
                'icon' => '📄',
            ];
        }

        return $recent;
    }

    /**
     * @param list<array{slug: string, title: string, published: bool, seo_title: string, seo_description: string}> $pages
     */
    /**
     * Resolve the requested sort state from the query string. Both params
     * are whitelisted — unknown values fall back to the default view.
     *
     * @return array{string, string} [column, direction]
     */
    private function sortState(Request $request): array
    {
        $sortRaw = $request->queryString('sort');
        $dirRaw = $request->queryString('dir');
        $sort = in_array($sortRaw, self::SORT_COLUMNS, true) ? $sortRaw : 'date';
        $dir = in_array($dirRaw, ['asc', 'desc'], true) ? $dirRaw : 'desc';

        return [$sort, $dir];
    }

    /**
     * Order the (already filtered) page list for display. The publish state
     * is the primary key when requested; title is the tie-breaker inside
     * each group.
     *
     * @template T of array{slug: string, title: string, published: bool}
     *
     * @param list<T> $pages
     *
     * @return list<T> $pages
     */
    private function sortPages(array $pages, string $sort, string $dir): array
    {
        // Single convention: every branch builds an ASCENDING comparator,
        // the direction multiplier flips it. Pages carry no date — title is
        // the tie-breaker inside groups (storage list is title-ordered).
        $flip = 'asc' === $dir ? 1 : -1;

        usort($pages, static function (array $a, array $b) use ($sort, $flip): int {
            $result = match ($sort) {
                'published' => [$a['published'], $a['title']] <=> [$b['published'], $b['title']],
                'title' => strcasecmp($a['title'], $b['title']),
                default => $a['title'] <=> $b['title'],
            };

            return $flip * $result;
        });

        return $pages;
    }

    /**
     * @param list<array{slug: string, title: string, published: bool, seo_title: string, seo_description: string}> $pages
     */
    private function renderGrid(array $pages, string $locale, string $search, int $currentPage, int $totalPages, int $total, string $sort = 'date', string $dir = 'desc'): string
    {
        $searchInput = $this->renderSearchInput($search, $locale, $sort, $dir);

        if ([] === $pages) {
            $emptyTitle = '' !== $search ? 'No pages match your search' : 'No pages yet';
            $emptyDesc = '' !== $search ? 'Try a different search term.' : 'Create your first page to get started.';
            $cta = '' !== $search ? '' : $this->url('pages.create') . '?locale=' . $locale;
            $ctaLabel = '' !== $search ? '' : 'New page';

            return $searchInput . AdminLayout::emptyState($emptyTitle, $emptyDesc, $cta, $ctaLabel);
        }

        $bulkBar = BulkActions::actionBarWithPublish(
            'pages',
            $this->url('mark.bulk-publish'),
            $this->url('mark.bulk-delete'),
            $locale,
        );

        return $searchInput . $bulkBar . $this->renderContent($pages, $locale, $search, $currentPage, $totalPages, $total, $sort, $dir);
    }

    /**
     * Render the #pages-content div (table + pagination) — used for both full page and HTMX partial.
     * The bulk bar is NOT part of this fragment: it lives outside the swapped
     * div (rendered by renderGrid) so HTMX search/pagination swaps never
     * duplicate it.
     *
     * @param list<array{slug: string, title: string, published: bool, seo_title: string, seo_description: string}> $pages
     */
    private function renderContent(array $pages, string $locale, string $search, int $currentPage, int $totalPages, int $total, string $sort = 'date', string $dir = 'desc'): string
    {
        $pagination = $this->renderPagination($currentPage, $totalPages, $locale, $search, $sort, $dir);
        $countInfo = '<div class="m-muted" style="font-size:13px;margin-bottom:12px;">Showing ' . count($pages) . ' of ' . $total . ' pages</div>';
        $tableHtml = PagesTableRenderer::table($pages, $locale, $sort, $dir, ['locale' => $locale, 'q' => $search], $this->router());

        $skeleton = AdminLayout::skeletonTable([
            ['5%'], ['30%'], ['20%'], ['20%'], ['15%'],
        ]);

        return '<div id="pages-content">'
            . '<div id="pages-skeleton" class="htmx-indicator">' . $skeleton . '</div>'
            . $countInfo
            . $tableHtml
            . $pagination
            . '</div>';
    }

    /**
     * Render search input with HTMX live search attributes.
     */
    private function renderSearchInput(string $search, string $locale, string $sort = 'date', string $dir = 'desc'): string
    {
        $escapedSearch = AdminLayout::e($search);
        $sortInclude = 'date' !== $sort || 'desc' !== $dir ? ', [name="sort"], [name="dir"]' : '';
        $sortHidden = 'date' !== $sort || 'desc' !== $dir
            ? '<input type="hidden" name="sort" value="' . AdminLayout::e($sort) . '">'
                . '<input type="hidden" name="dir" value="' . AdminLayout::e($dir) . '">'
            : '';

        return '<div class="m-filter-bar" style="margin-bottom:16px;">'
            . '<input type="search" name="q" value="' . $escapedSearch . '"'
            . ' hx-get="' . $this->url('pages.index') . '"'
            . ' hx-trigger="keyup changed delay:300ms, search"'
            . ' hx-target="#pages-content"'
            . ' hx-swap="outerHTML"'
            . ' hx-include="[name=\"locale\"]' . $sortInclude . '"'
            . ' hx-indicator="#pages-skeleton"'
            . ' placeholder="Search pages..."'
            . ' style="flex:1;padding:8px 12px;border:1px solid var(--m-border);border-radius:var(--m-r-md);background:var(--m-surface);color:var(--m-ink);font-size:14px;">'
            . '<input type="hidden" name="locale" value="' . AdminLayout::e($locale) . '">'
            . $sortHidden
            . '</div>';
    }

    /**
     * Render pagination links with HTMX attributes.
     */
    private function renderPagination(int $currentPage, int $totalPages, string $locale, string $search, string $sort = 'date', string $dir = 'desc'): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $nav = '<nav class="m-filter-bar" style="justify-content:center;margin-top:16px;" aria-label="Pages pagination">';

        // Previous link.
        if ($currentPage > 1) {
            $href = $this->paginationUrl($currentPage - 1, $locale, $search, $sort, $dir);
            $nav .= '<a class="m-btn m-btn-sm" href="' . $href . '"'
                . ' hx-get="' . $this->url('pages.index') . $href . '"'
                . ' hx-target="#pages-content"'
                . ' hx-swap="outerHTML"'
                . ' hx-indicator="#pages-skeleton"'
                . ' hx-push-url="true">&laquo; Prev</a>';
        }

        // Page numbers.
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $start + 4);
        $start = max(1, $end - 4);

        for ($i = $start; $i <= $end; $i++) {
            $href = $this->paginationUrl($i, $locale, $search, $sort, $dir);
            if ($i === $currentPage) {
                $nav .= '<span class="m-btn m-btn-sm m-btn-primary" aria-current="page">' . $i . '</span>';
            } else {
                $nav .= '<a class="m-btn m-btn-sm" href="' . $href . '"'
                    . ' hx-get="' . $this->url('pages.index') . $href . '"'
                    . ' hx-target="#pages-content"'
                    . ' hx-swap="outerHTML"'
                    . ' hx-indicator="#pages-skeleton"'
                    . ' hx-push-url="true">' . $i . '</a>';
            }
        }

        // Next link.
        if ($currentPage < $totalPages) {
            $href = $this->paginationUrl($currentPage + 1, $locale, $search, $sort, $dir);
            $nav .= '<a class="m-btn m-btn-sm" href="' . $href . '"'
                . ' hx-get="' . $this->url('pages.index') . $href . '"'
                . ' hx-target="#pages-content"'
                . ' hx-swap="outerHTML"'
                . ' hx-indicator="#pages-skeleton"'
                . ' hx-push-url="true">Next &raquo;</a>';
        }

        $nav .= '</nav>';

        return $nav;
    }

    /**
     * Build pagination URL with query parameters.
     */
    private function paginationUrl(int $page, string $locale, string $search, string $sort = 'date', string $dir = 'desc'): string
    {
        $params = ['locale' => $locale, 'page' => (string) $page];
        if ('' !== $search) {
            $params['q'] = $search;
        }
        if ('date' !== $sort) {
            $params['sort'] = $sort;
            $params['dir'] = $dir;
        }

        return '?' . http_build_query($params);
    }

    /**
     * Load, filter and sort the page list for one request.
     *
     * @return list<array{slug: string, title: string, published: bool, seo_title: string, seo_description: string}>
     */
    private function loadPages(string $locale, string $search, Request $request): array
    {
        $pages = (new PageCrudService())->list($locale);

        // Filter by search query.
        if ('' !== $search) {
            $pages = $this->filterPages($pages, $search);
        }

        [$sort, $dir] = $this->sortState($request);

        return $this->sortPages($pages, $sort, $dir);
    }

    /**
     * Filter pages by search query (title or slug).
     *
     * @param list<array{slug: string, title: string, published: bool, seo_title: string, seo_description: string}> $pages
     *
     * @return list<array{slug: string, title: string, published: bool, seo_title: string, seo_description: string}>
     */
    private function filterPages(array $pages, string $search): array
    {
        $query = strtolower($search);

        return array_values(array_filter(
            $pages,
            static fn (array $page): bool => str_contains(strtolower($page['slug']), $query)
                || str_contains(strtolower($page['title']), $query),
        ));
    }

}
