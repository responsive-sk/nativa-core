<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogCrudService;
use Modules\Blog\Interfaces\HTTP\ArticlesTableRenderer;
use Modules\Cardboard\Application\Services\AdminLocale;
use Modules\Cardboard\Application\Services\BulkActions;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Users\Interfaces\HTTP\AuthServices;

/**
 * Articles index — GET /mark/articles.
 *
 * Lists all blog articles with edit/delete actions. SK/EN locale toggle.
 */
#[Route(path: '/mark/articles', method: 'GET', name: 'articles.index')]
final class ArticlesIndexAction
{
    use ResolvesRouteUrls;

    private const int PER_PAGE = 20;

    /** Published-date windows for the period filter (query value => days). */
    private const array PERIOD_DAYS = ['7d' => 7, '30d' => 30, '90d' => 90];

    /** Whitelisted sortable columns (query ?sort= value). */
    private const array SORT_COLUMNS = ['date', 'title', 'published'];

    public function handle(Request $request): Response
    {
        // Allowlisted at the boundary: the raw param is re-emitted into
        // row/filter/create URLs, so anything outside sk/en would reflect
        // verbatim (AdminLocale::raw() is the identity on legit values).
        $localeRaw = AdminLocale::raw($request->queryString('locale', 'sk'));
        $locale = AdminLocale::canonical($localeRaw);
        $search = $request->queryString('q');
        $periodRaw = $request->queryString('period');
        $period = isset(self::PERIOD_DAYS[$periodRaw]) ? $periodRaw : '';
        $pageParam = $request->query('page');
        $page = max(1, (int) (is_string($pageParam) ? $pageParam : '1'));
        [$sort, $dir] = $this->sortState($request);

        $articles = $this->loadArticles($locale, $search, $period, $sort, $dir);
        $articles = $this->ownedByCaller($articles);

        $total = count($articles);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::PER_PAGE;
        $paginated = array_slice($articles, $offset, self::PER_PAGE);

        $filterBars = $this->buildFilterBars($locale, $localeRaw, $period);

        $grid = $this->renderGrid($paginated, $localeRaw, $search, $period, $page, $totalPages, $total, $sort, $dir);

        $breadcrumb = AdminLayout::breadcrumb([
            ['label' => 'Dashboard', 'href' => $this->url('mark.dashboard')],
            ['label' => 'Articles'],
        ]);

        $content = $breadcrumb . $filterBars . $grid;

        // HTMX: return only the content div (no search input to avoid re-triggering).
        if (AdminLayout::isHtmx($request)) {
            return Response::html($this->renderContent($paginated, $localeRaw, $search, $period, $page, $totalPages, $total, $sort, $dir));
        }

        $toast = '';
        $toastMessage = $request->queryString('toast');
        if ('' !== $toastMessage) {
            $toast = AdminLayout::toast(urldecode($toastMessage));
        }

        $recentArticles = $this->recentItems($articles, $localeRaw);

        return Response::html(AdminLayout::render(
            'Articles',
            $content . $toast,
            'articles',
            [['label' => 'New article', 'href' => $this->url('articles.create') . '?locale=' . $localeRaw]],
            $recentArticles,
        router: $this->router()));
    }

    /**
     * A non-admin sees only the articles they wrote.
     *
     * The write paths already refuse another author's article; hiding it from
     * the list too is the difference between "you may not do that" and "that
     * does not exist here". The row would otherwise render as a normal link
     * that 403s on click.
     *
     * @param list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}> $articles
     *
     * @return list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}>
     */
    private function ownedByCaller(array $articles): array
    {
        $user = AuthServices::auth()->currentUser();

        if (null === $user || $user->hasRole('admin')) {
            return $articles;
        }

        $ownership = BlogServices::ownership();

        // The row already carries the byline, so ownership is one string
        // compare per article — no second file read per row.
        return array_values(array_filter(
            $articles,
            static fn (array $article): bool => $ownership->canEditByEmail($article['author_email'], $user),
        ));
    }

    /**
     * Load, filter and sort the article list for one request.
     *
     * @return list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}>
     */
    private function loadArticles(string $locale, string $search, string $period, string $sort, string $dir): array
    {
        $articles = (new BlogCrudService())->list($locale);

        // Filter by search query.
        if ('' !== $search) {
            $articles = $this->filterArticles($articles, $search);
        }

        // Filter by published-date period.
        if ('' !== $period) {
            $articles = $this->filterByPeriod($articles, self::PERIOD_DAYS[$period]);
        }

        return $this->sortArticles($articles, $sort, $dir);
    }

    /**
     * Build recent items for the command palette.
     *
     * @param list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}> $articles
     *
     * @return list<array{label: string, href: string, icon: string}>
     */
    private function recentItems(array $articles, string $locale): array
    {
        $recent = [];
        foreach (array_slice($articles, 0, 5) as $article) {
            $recent[] = [
                'label' => $article['title'],
                'href' => $this->url('articles.edit', ['slug' => $article['slug']]) . '?locale=' . $locale,
                'icon' => '📝',
            ];
        }

        return $recent;
    }

    /**
     * @param list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}> $articles
     */
    /**
     * Resolve the requested sort state from the query string. Both params
     * are whitelisted — unknown values fall back to the default view
     * (newest first) instead of an error.
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
     * Order the (already filtered) article list for display. The publish
     * state is the primary key when requested; date-newest then title are
     * the tie-breakers inside each group so the order stays stable.
     *
     * @param list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}> $articles
     *
     * @return list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}> $articles
     */
    private function sortArticles(array $articles, string $sort, string $dir): array
    {
        // Single convention: every branch builds an ASCENDING comparator,
        // the direction multiplier flips it — no per-branch desc swaps.
        $flip = 'asc' === $dir ? 1 : -1;

        usort($articles, static function (array $a, array $b) use ($sort, $flip): int {
            $result = match ($sort) {
                'published' => [$a['published'], $a['date'], $a['title']] <=> [$b['published'], $b['date'], $b['title']],
                'title' => strcasecmp($a['title'], $b['title']),
                default => [$a['date'], $a['title']] <=> [$b['date'], $b['title']],
            };

            return $flip * $result;
        });

        return $articles;
    }

    /**
     * @param list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}> $articles
     */
    private function renderGrid(array $articles, string $locale, string $search, string $period, int $currentPage, int $totalPages, int $total, string $sort = 'date', string $dir = 'desc'): string
    {
        $searchInput = $this->renderSearchInput($search, $locale, $period, $sort, $dir);

        if ([] === $articles) {
            $hasFilter = '' !== $search || '' !== $period;
            $emptyTitle = '' !== $search
                ? 'No articles match your search'
                : ('' !== $period ? 'No articles published in this period' : 'No articles yet');
            $emptyDesc = $hasFilter
                ? 'Try a different search term or period.'
                : 'Write your first article to get started.';
            $cta = $hasFilter ? '' : $this->url('articles.create') . '?locale=' . $locale;
            $ctaLabel = $hasFilter ? '' : 'New article';

            return $searchInput . AdminLayout::emptyState($emptyTitle, $emptyDesc, $cta, $ctaLabel);
        }

        $bulkBar = BulkActions::actionBarWithPublish(
            'articles',
            $this->url('mark.bulk-publish'),
            $this->url('mark.bulk-delete'),
            $locale,
        );

        return $searchInput . $bulkBar . $this->renderContent($articles, $locale, $search, $period, $currentPage, $totalPages, $total, $sort, $dir);
    }

    /**
     * Render the #articles-content div (table + pagination) — used for both full page and HTMX partial.
     * The bulk bar is NOT part of this fragment: it lives outside the swapped
     * div (rendered by renderGrid) so HTMX search/pagination swaps never
     * duplicate it.
     *
     * @param list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}> $articles
     */
    private function renderContent(array $articles, string $locale, string $search, string $period, int $currentPage, int $totalPages, int $total, string $sort = 'date', string $dir = 'desc'): string
    {
        $pagination = $this->renderPagination($currentPage, $totalPages, $locale, $search, $period, $sort, $dir);
        $countInfo = '<div class="m-muted" style="font-size:13px;margin-bottom:12px;">Showing ' . count($articles) . ' of ' . $total . ' articles</div>';
        $tableHtml = ArticlesTableRenderer::table($articles, $locale, $sort, $dir, router: $this->router());

        $skeleton = AdminLayout::skeletonTable([
            ['5%'], ['30%'], ['15%'], ['20%'], ['20%'], ['15%'],
        ]);

        return '<div id="articles-content">'
            . '<div id="articles-skeleton" class="htmx-indicator">' . $skeleton . '</div>'
            . $countInfo
            . $tableHtml
            . $pagination
            . '</div>';
    }

    /**
     * Render search input with HTMX live search attributes.
     */
    private function renderSearchInput(string $search, string $locale, string $period, string $sort = 'date', string $dir = 'desc'): string
    {
        $periodInclude = '' !== $period ? ', [name="period"]' : '';
        $sortInclude = 'date' !== $sort || 'desc' !== $dir ? ', [name="sort"], [name="dir"]' : '';
        $periodHidden = '' !== $period
            ? '<input type="hidden" name="period" value="' . AdminLayout::e($period) . '">'
            : '';
        $sortHidden = 'date' !== $sort || 'desc' !== $dir
            ? '<input type="hidden" name="sort" value="' . AdminLayout::e($sort) . '">'
                . '<input type="hidden" name="dir" value="' . AdminLayout::e($dir) . '">'
            : '';

        return '<div class="m-filter-bar m-filter-bar--search m-filter-row__search">'
            . '<input type="search" name="q" value="' . AdminLayout::e($search) . '"'
            . ' hx-get="' . $this->url('articles.index') . '"'
            . ' hx-trigger="keyup changed delay:300ms, search"'
            . ' hx-target="#articles-content"'
            . ' hx-swap="outerHTML"'
            . ' hx-include="[name=\"locale\"]' . $periodInclude . $sortInclude . '"'
            . ' hx-indicator="#articles-skeleton"'
            . ' placeholder="Search articles..."'
            . ' class="m-input--search">'
            . '<input type="hidden" name="locale" value="' . AdminLayout::e($locale) . '">'
            . $periodHidden
            . $sortHidden
            . '</div>';
    }

    /**
     * Combined locale + period filter bar — one horizontal row so locale and
     * period chips sit side by side instead of stacked (avoids double card
     * chrome and saves vertical space). Period pills sit on the left; the
     * language toggle is pushed to the far right so the two groups read as
     * separate controls.
     */
    private function buildFilterBars(string $locale, string $localeRaw, string $period): string
    {
        $skClass = 'sk_SK' === $locale ? 'm-pill m-pill-active' : 'm-pill';
        $enClass = 'en_US' === $locale ? 'm-pill m-pill-active' : 'm-pill'; // pill state, not a storage path
        $periodSuffix = '' !== $period ? '&period=' . $period : '';

        $labels = ['' => 'All', '7d' => '7 days', '30d' => '30 days', '90d' => '90 days'];
        $html = '<div class="m-filter-bar m-filter-row__filters">';

        foreach ($labels as $value => $label) {
            $href = $this->url('articles.index') . '?locale=' . $localeRaw . ('' !== $value ? '&period=' . $value : '');
            $class = $value === $period ? 'm-pill m-pill-active' : 'm-pill';
            $html .= '<a class="' . $class . '" href="' . $href . '">' . AdminLayout::e($label) . '</a>';
        }

        // Flexible spacer pushes the language toggle to the right edge; on
        // narrow screens it collapses and the language pills wrap below.
        $html .= '<div class="m-filter-spacer"></div>';

        $html .= '<a class="' . $skClass . '" href="' . $this->url('articles.index') . '?locale=sk' . $periodSuffix . '">SK</a>'
            . '<a class="' . $enClass . '" href="' . $this->url('articles.index') . '?locale=en' . $periodSuffix . '">EN</a>';

        return $html . '</div>';
    }

    /**
     * Render pagination links with HTMX attributes.
     */
    private function renderPagination(int $currentPage, int $totalPages, string $locale, string $search, string $period, string $sort = 'date', string $dir = 'desc'): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $nav = '<nav class="m-filter-bar" style="justify-content:center;margin-top:16px;" aria-label="Articles pagination">';

        // Previous link.
        if ($currentPage > 1) {
            $href = $this->paginationUrl($currentPage - 1, $locale, $search, $period, $sort, $dir);
            $nav .= '<a class="m-btn m-btn-sm" href="' . $href . '"'
                . ' hx-get="' . $this->url('articles.index') . $href . '"'
                . ' hx-target="#articles-content"'
                . ' hx-swap="outerHTML"'
                . ' hx-indicator="#articles-skeleton"'
                . ' hx-push-url="true">&laquo; Prev</a>';
        }

        // Page numbers.
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $start + 4);
        $start = max(1, $end - 4);

        for ($i = $start; $i <= $end; $i++) {
            $href = $this->paginationUrl($i, $locale, $search, $period, $sort, $dir);
            if ($i === $currentPage) {
                $nav .= '<span class="m-btn m-btn-sm m-btn-primary" aria-current="page">' . $i . '</span>';
            } else {
                $nav .= '<a class="m-btn m-btn-sm" href="' . $href . '"'
                    . ' hx-get="' . $this->url('articles.index') . $href . '"'
                    . ' hx-target="#articles-content"'
                    . ' hx-swap="outerHTML"'
                    . ' hx-indicator="#articles-skeleton"'
                    . ' hx-push-url="true">' . $i . '</a>';
            }
        }

        // Next link.
        if ($currentPage < $totalPages) {
            $href = $this->paginationUrl($currentPage + 1, $locale, $search, $period, $sort, $dir);
            $nav .= '<a class="m-btn m-btn-sm" href="' . $href . '"'
                . ' hx-get="' . $this->url('articles.index') . $href . '"'
                . ' hx-target="#articles-content"'
                . ' hx-swap="outerHTML"'
                . ' hx-indicator="#articles-skeleton"'
                . ' hx-push-url="true">Next &raquo;</a>';
        }

        $nav .= '</nav>';

        return $nav;
    }

    /**
     * Build pagination URL with query parameters.
     */
    private function paginationUrl(int $page, string $locale, string $search, string $period, string $sort = 'date', string $dir = 'desc'): string
    {
        $params = ['locale' => $locale, 'page' => (string) $page];
        if ('' !== $search) {
            $params['q'] = $search;
        }
        if ('' !== $period) {
            $params['period'] = $period;
        }
        if ('date' !== $sort) {
            $params['sort'] = $sort;
            $params['dir'] = $dir;
        }

        return '?' . http_build_query($params);
    }

    /**
     * Filter articles by search query (title, slug, category, author).
     *
     * @param list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}> $articles
     *
     * @return list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}>
     */
    private function filterArticles(array $articles, string $search): array
    {
        $query = strtolower($search);

        return array_values(array_filter(
            $articles,
            static fn (array $article): bool => str_contains(strtolower($article['slug']), $query)
                || str_contains(strtolower($article['title']), $query)
                || str_contains(strtolower($article['category']), $query)
                || str_contains(strtolower($article['author']), $query),
        ));
    }

    /**
     * Keep only articles published within the last N days (inclusive of
     * today), based on the frontmatter date. Articles without a date never
     * match a period filter.
     *
     * @param list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}> $articles
     *
     * @return list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}>
     */
    private function filterByPeriod(array $articles, int $days): array
    {
        $cutoffTs = strtotime('today -' . ($days - 1) . ' days');
        $cutoff = false !== $cutoffTs ? date('Y-m-d', $cutoffTs) : date('Y-m-d');

        return array_values(array_filter(
            $articles,
            static fn (array $article): bool => '' !== $article['date'] && $article['date'] >= $cutoff,
        ));
    }
}
