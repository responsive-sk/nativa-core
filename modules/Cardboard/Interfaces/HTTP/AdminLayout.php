<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP;

use Modules\Cardboard\Application\Services\IconRegistry;
use Themes\AssetManager;
use View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Navigation\Infrastructure\Registry\NavBadgeRegistry;
use Modules\Core\Interfaces\HTTP\Middleware\CsrfMiddleware;
use Themes\ThemeResolver;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Shell\ShellContextProviderRegistry;

/**
 * AdminLayout — shared admin page shell (task 9.5).
 *
 * Renders a self-contained HTML page with a minimal BEM-styled admin
 * navbar (Mark, Users, Roles, Settings, logout) and a content slot.
 * No external CDNs — CSS is inline and self-hosted, mirroring the
 * project asset policy.
 */
final class AdminLayout
{
    /**
     * @param list<array{label: string, href: string}>               $actions
     * @param list<array{label: string, href: string, icon: string}> $recentItems
     */
    public static function render(
        string $title,
        string $content,
        string $activeSection = '',
        array $actions = [],
        array $recentItems = [],
        ?RouterInterface $router = null,
    ): string {
        // Check layout setting — delegate to CompactLayout if needed.
        $layout = self::resolveLayout();
        if ('compact' === $layout) {
            return CompactLayout::render($title, $content, $activeSection, $actions, $recentItems, $router);
        }

        $actionsHtml = '';

        foreach ($actions as $action) {
            $label = self::e($action['label']);
            $href = self::e($action['href']);
            $actionsHtml .= "<a class=\"cba__action\" href=\"{$href}\">{$label}</a>";
        }

        $actionsBar = '' !== $actionsHtml
            ? "<div class=\"cba__actions\">{$actionsHtml}</div>"
            : '';

        $titleEscaped = self::e($title);
        $currentUserName = self::e(self::currentUserName());

        // DB-driven admin navigation items (hierarchical tree) — Cardboard is
        // the admin app itself, so the layout calls its own services directly.
        // No provider, no personalized nav, still a page.
        try {
            $adminNavItems = CardboardServices::adminNav()->hierarchicalItems($activeSection, self::grantedPermissions());
        } catch (\Throwable) {
            $adminNavItems = [];
        }

        $navHtml = '';
        foreach ($adminNavItems as $item) {
            $navHtml .= self::renderNavItem($item, $activeSection);
        }

        // Dynamic html lang from the kernel shell port (sk / en) — the same
        // answer FrontendLayout uses. A layout that imports a locale module
        // for one attribute makes every admin page depend on that module.
        $htmlLang = ShellContextProviderRegistry::htmlLang() ?? 'en';
        $csrf = self::csrfField();

        // Build external CSS link tags from the Vite manifest.
        $markCssLinks = self::markCssLinks();

        // HTMX opt-in: load only if the self-hosted file exists.
        $htmxScript = self::htmxScriptTag();
        $htmxConfig = '' !== $htmxScript ? self::htmxConfigScript() : '';

        // Encode recent items for command palette.
        $recentJson = json_encode($recentItems, JSON_HEX_TAG | JSON_HEX_AMP) ?: '[]';

        return ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/layout.phtml', ['actionsBar' => $actionsBar, 'content' => $content, 'csrf' => $csrf, 'currentUserName' => $currentUserName, 'htmlLang' => $htmlLang, 'htmxConfig' => $htmxConfig, 'htmxScript' => $htmxScript, 'markCssLinks' => $markCssLinks, 'navHtml' => $navHtml, 'recentJson' => $recentJson, 'titleEscaped' => $titleEscaped, 'url' => self::urlBuilder($router)]);
    }

    /**
     * The template's URL seam — a closure, so a template cannot reach the
     * Router itself and the layout decides what a template may address.
     *
     * Task 2.9: templates used to spell `/mark/...` by hand. They call
     * `$url('mark.settings')` now, and a layout rendered without a router
     * fails loudly instead of shipping a wrong link.
     *
     * @return \Closure(string, array<string, string|int>): string
     */
    public static function urlBuilder(?RouterInterface $router): \Closure
    {
        return static function (string $name, array $params = []) use ($router): string {
            if (null === $router) {
                throw new \LogicException(
                    'An admin layout was rendered without a Router; pass one to AdminLayout::render().',
                );
            }

            return $router->url($name, $params);
        };
    }

    /**
     * Message banner helper.
     *
     * @param list<string> $messages
     */
    public static function messages(array $messages, string $type = 'ok'): string
    {
        $html = '';
        $class = 'err' === $type ? 'cba-msg--err' : 'cba-msg--ok';

        foreach ($messages as $message) {
            $html .= "<div class=\"cba-msg {$class}\">" . self::e($message) . '</div>';
        }

        return $html;
    }

    /**
     * Escape HTML special characters.
     */
    public static function e(mixed $value): string
    {
        if (is_string($value)) {
            $string = $value;
        } elseif (is_int($value) || is_float($value)) {
            $string = (string) $value;
        } elseif (is_bool($value)) {
            $string = $value ? '1' : '0';
        } elseif (null === $value) {
            $string = '';
        } else {
            $string = json_encode($value) ?: '';
        }

        return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * CSRF token input (from session, issued by CsrfMiddleware).
     */
    public static function csrfField(): string
    {
        $token = CsrfMiddleware::token();

        return '<input type="hidden" name="_token" value="' . self::e($token) . '">';
    }

    /**
     * Render an inline page script with the CSP nonce applied.
     *
     * Use for small page-specific behavior that is not worth a shared
     * layout script (e.g. collapsible create panels). The nonce
     * placeholder is substituted per response by the security headers
     * middleware, exactly like the toast script.
     *
     * @param string $js Raw JavaScript (no <script> tags).
     */
    public static function script(string $js): string
    {
        return '<script nonce="__CSP_NONCE_PLACEHOLDER__">' . $js . '</script>';
    }

    /**
     * Render a toast notification script tag.
     *
     * Call after a successful CRUD operation to show feedback.
     * The mToast() function is defined in the layout's bottom-of-body
     * inline script, which runs AFTER this mid-page script on full page
     * loads — so on first paint the call is deferred to DOMContentLoaded
     * (by then window.mToast exists). On HTMX swaps mToast is already
     * defined, so the toast fires immediately.
     *
     * @param string $message Toast message text.
     * @param string $type    Toast type: 'ok', 'err', or 'info'.
     */
    public static function toast(string $message, string $type = 'ok'): string
    {
        $safeMessage = self::e($message);
        $safeType = self::e($type);
        $call = 'window.mToast("' . $safeMessage . '", "' . $safeType . '");';

        return '<script nonce="__CSP_NONCE_PLACEHOLDER__">'
            . 'if(typeof window.mToast==="function"){' . $call . '}'
            . 'else{document.addEventListener("DOMContentLoaded",function(){' . $call . '});}'
            . '</script>';
    }

    /**
     * Render breadcrumb navigation.
     *
     * @param list<array{label: string, href?: string}> $items
     */
    public static function breadcrumb(array $items): string
    {
        $html = '<nav class="m-breadcrumb" aria-label="Breadcrumb">';
        $count = count($items);

        foreach ($items as $i => $item) {
            $label = self::e($item['label']);
            $isLast = $i === $count - 1;

            if ($isLast) {
                $html .= '<span class="m-breadcrumb__current">' . $label . '</span>';
            } else {
                $href = isset($item['href']) ? self::e($item['href']) : '#';
                $html .= '<a href="' . $href . '">' . $label . '</a>';
                $html .= '<span class="m-breadcrumb__sep" aria-hidden="true">/</span>';
            }
        }

        $html .= '</nav>';

        return $html;
    }

    /**
     * Render a sortable table header cell — a link that toggles the sort
     * direction for its column while preserving the page's other query
     * params (locale, search, period). The active column shows ▲/▼ and the
     * aria-sort attribute; clicking it flips the direction, clicking an
     * inactive column sorts descending first (published-first for status
     * columns).
     *
     * @param array<string, string> $baseParams Query params to preserve (locale, q, period, …).
     */
    public static function sortableHeader(string $label, string $column, string $currentSort, string $currentDir, array $baseParams): string
    {
        $isActive = $column === $currentSort;
        $dir = $isActive ? ('asc' === $currentDir ? 'desc' : 'asc') : 'desc';

        $params = array_merge($baseParams, ['sort' => $column, 'dir' => $dir]);
        $href = self::e('?' . http_build_query($params));
        $label = self::e($label);

        if (!$isActive) {
            return '<th class="m-th-sort" aria-sort="none"><a href="' . $href . '">'
                . $label
                . '<span class="m-th-sort__arrow" aria-hidden="true">↕</span></a></th>';
        }

        $isAsc = 'asc' === $currentDir;
        $arrow = $isAsc ? '▲' : '▼';
        $aria = $isAsc ? 'ascending' : 'descending';

        return '<th class="m-th-sort m-th-sort--active" aria-sort="' . $aria . '"><a href="' . $href . '">'
            . $label
            . '<span class="m-th-sort__arrow" aria-hidden="true">' . $arrow . '</span></a></th>';
    }

    /**
     * The pager under a list: the row range plus the two links that move.
     *
     * Reads the `meta`/`links` arrays `Modules\Pagination\…::toArray()`
     * produces — the same shape a future API answers with — so a page means the
     * same thing in the panel and outside it, and only the markup is ours.
     *
     * A link the paginator left null renders as a disabled-looking span rather
     * than disappearing: controls that come and go shift the row under the
     * reader's cursor, and "Previous is not available here" is information.
     *
     * @param array<string, int|string|bool|null> $meta
     * @param array<string, string|null>          $links
     */
    public static function pager(array $meta, array $links): string
    {
        $from = $meta['from'] ?? 0;
        $to = $meta['to'] ?? 0;
        $total = $meta['total'] ?? 0;

        return '<div class="m-pagination">'
            . '<span class="m-muted">Showing ' . self::e($from) . '–' . self::e($to) . ' of ' . self::e($total) . '</span>'
            . self::pageLink('‹ Prev', $links['previous'] ?? null)
            . self::pageLink('Next ›', $links['next'] ?? null)
            . '</div>';
    }

    /**
     * One pager control — a link, or the same control inert at an end.
     */
    private static function pageLink(string $label, ?string $href): string
    {
        if (null === $href) {
            return '<span class="m-page-link" aria-disabled="true">' . self::e($label) . '</span>';
        }

        return '<a class="m-page-link" href="' . self::e($href) . '">' . self::e($label) . '</a>';
    }

    /**
     * The settings tab bar — one markup for both screens that show it.
     *
     * The five settings panels live on `/mark/settings` and switch in place;
     * the module list is its own page (`mark.settings.modules`). Rendering them
     * as anchors keeps the bar honest on the module page (where a button would
     * have no panel) and still gives instant switching on the settings page:
     * `settings-tabs.js` intercepts the anchors that carry `data-tab` and only
     * navigates when JS is off. Each anchor's href is the no-JS fallback
     * (`?tab=…`), which the script also honours on load.
     *
     * Labels live here once, so the two bars cannot drift apart.
     *
     * @param \Closure(string, array<string, string|int>): string $url
     */
    public static function settingsTabs(\Closure $url, string $active): string
    {
        $tabs = [
            'general'   => 'General',
            'design'    => 'Design',
            'cache'     => 'Cache',
            'nav'       => 'Navigation',
            'admin-nav' => 'Admin Nav',
            'modules'   => 'Modules',
        ];

        $html = '<div class="m-tabs__bar">';

        foreach ($tabs as $tab => $label) {
            $current = $tab === $active;
            $href = 'modules' === $tab
                ? $url('mark.settings.modules')
                : $url('mark.settings') . '?tab=' . $tab;

            $html .= '<a class="m-tabs__btn' . ($current ? ' m-tabs__btn--active' : '') . '"'
                . ' href="' . self::e($href) . '"'
                . ('modules' === $tab ? '' : ' data-tab="' . self::e($tab) . '"')
                . ($current ? ' aria-current="page"' : '')
                . '>' . $label . '</a>';
        }

        return $html . '</div>';
    }

    /**
     * Render skeleton placeholder blocks (shimmer animation).
     *
     * @param list<string> $types Skeleton types: 'title', 'text', 'card', 'avatar'.
     */
    public static function skeleton(array $types = ['title', 'text', 'text']): string
    {
        $html = '';

        foreach ($types as $type) {
            $class = 'm-skeleton m-skeleton--' . self::e($type);
            $html .= '<div class="' . $class . '"></div>';
        }

        return '<div class="m-card" style="padding:16px;">' . $html . '</div>';
    }

    /**
     * Render a skeleton table with shimmer rows (shown during HTMX loading).
     *
     * @param list<array{0: string}> $columns Column width hints (e.g. [['30%'],['50%'],['70%']])
     * @param int                    $rows    Number of skeleton rows
     */
    public static function skeletonTable(array $columns = [['30%'], ['50%'], ['70%']], int $rows = 5): string
    {
        $headerCells = '';
        $bodyRows = '';

        for ($i = 0; $i < $rows; $i++) {
            $bodyRows .= '<tr>';
            foreach ($columns as $col) {
                $w = self::e($col[0]);
                $bodyRows .= '<td><div class="m-skeleton m-skeleton--w' . substr($w, 0, -1) . '" style="width:' . $w . ';"></div></td>';
            }
            $bodyRows .= '</tr>';
        }

        for ($c = 0; $c < count($columns); $c++) {
            $headerCells .= '<th>&nbsp;</th>';
        }

        return '<table class="m-table m-table-skeleton"><thead><tr>' . $headerCells . '</tr></thead><tbody>' . $bodyRows . '</tbody></table>';
    }

    /**
     * Render a relative time string with a <time> element.
     *
     * Examples: "2 minutes ago", "3 hours ago", "yesterday", "5 days ago"
     */
    public static function relativeTime(string $datetime): string
    {
        $now = time();
        $then = strtotime($datetime);

        if (false === $then) {
            return self::e($datetime);
        }

        $diff = $now - $then;
        $escaped = self::e($datetime);

        if ($diff < 0) {
            return '<time datetime="' . $escaped . '">just now</time>';
        }
        if ($diff < 60) {
            return '<time datetime="' . $escaped . '">just now</time>';
        }
        if ($diff < 3600) {
            $mins = (int) floor($diff / 60);

            return '<time datetime="' . $escaped . '">' . $mins . 'm ago</time>';
        }
        if ($diff < 86_400) {
            $hours = (int) floor($diff / 3600);

            return '<time datetime="' . $escaped . '">' . $hours . 'h ago</time>';
        }
        if ($diff < 172_800) {
            return '<time datetime="' . $escaped . '">yesterday</time>';
        }
        if ($diff < 604_800) {
            $days = (int) floor($diff / 86_400);

            return '<time datetime="' . $escaped . '">' . $days . 'd ago</time>';
        }

        // Older than 7 days — show full date.
        return '<time datetime="' . $escaped . '">' . self::e(date('M j', $then)) . '</time>';
    }

    /**
     * Render an empty state illustration with icon, title, description, and optional CTA.
     */
    public static function emptyState(string $title, string $description = '', string $ctaHref = '', string $ctaLabel = ''): string
    {
        $icon = '<div class="m-empty__icon">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/>'
            . '<path d="M14 2v4a2 2 0 0 0 2 2h4"/>'
            . '<path d="M10 12h4"/><path d="M10 16h4"/></svg>'
            . '</div>';

        $desc = '' !== $description
            ? '<p class="m-empty__desc">' . self::e($description) . '</p>'
            : '';

        $cta = '' !== $ctaHref
            ? '<a class="m-btn m-btn-primary" href="' . self::e($ctaHref) . '">' . self::e($ctaLabel) . '</a>'
            : '';

        return '<div class="m-empty">'
            . $icon
            . '<p class="m-empty__title">' . self::e($title) . '</p>'
            . $desc
            . $cta
            . '</div>';
    }

    /**
     * External admin stylesheet <link> tags from the Vite manifest.
     *
     * mark.css carries the shared admin components; mark-switch.css the
     * Publish/Draft switch (separate entry because mark.css is a
     * shrink-only legacy baseline in ThemeFileSizeTest). Both entries live
     * in the admin theme package and resolve from its manifest. Missing
     * entries (before a build) degrade to no tag — the layout still shows
     * the critical tokens inlined in the template.
     *
     * The theme name is read from the owner rather than repeated here. It was
     * `'moonshine'` in this line while `ThemeResolver::ADMIN_THEME` owned it,
     * and Cardboard importing Core is allowed (every module already does), so
     * the duplicate cost one line and nothing gained by carrying it.
     */
    public static function markCssLinks(): string
    {
        $assets = new AssetManager(ThemeResolver::ADMIN_THEME);
        $links = [];

        foreach (['mark', 'mark-switch'] as $entry) {
            $url = $assets->jsUrl($entry);

            if (null !== $url) {
                $links[] = '<link rel="stylesheet" href="' . self::e($url) . '">';
            }
        }

        return implode("\n    ", $links);
    }

    /**
     * Render HTMX script tag (opt-in: only if self-hosted file exists).
     */
    private static function htmxScriptTag(): string
    {
        $path = AppPaths::instance()->public('dist/htmx.min.js');

        if (!is_file($path)) {
            return '';
        }

        return '<script src="/dist/htmx.min.js" defer></script>';
    }

    /**
     * Render HTMX configuration script (CSRF, indicators, history).
     */
    private static function htmxConfigScript(): string
    {
        $token = CsrfMiddleware::token();
        $csrfHeader = '' !== $token
            ? 'e.detail.headers["X-CSRF-Token"]="' . self::e($token) . '";' // PHP is 8.5 so curly braces are fine.
            : '';

        return '<script nonce="__CSP_NONCE_PLACEHOLDER__">'
            . 'document.addEventListener("DOMContentLoaded",function(){'
            . 'if(typeof htmx!=="undefined"){'
            . 'document.body.addEventListener("htmx:configRequest",function(e){'
            . $csrfHeader
            . '});'
            . 'htmx.config.defaultSwapStyle="innerHTML";' // PHP is 8.5 so curly braces are fine.
            . 'htmx.config.historyEnabled=true;' // PHP is 8.5 so curly braces are fine.
            . '}'
            . '});'
            . '</script>';
    }

    /**
     * Detect HTMX request via HX-Request header.
     */
    public static function isHtmx(Request $request): bool
    {
        return 'true' === $request->header('HX-Request');
    }

    /**
     * Resolve the admin layout variant from settings ('sidebar' on any
     * failure — the compact/admin choice is the admin app's own setting).
     */
    private static function resolveLayout(): string
    {
        try {
            $all = CardboardServices::settings()->all();
            $layout = is_string($all['admin_layout'] ?? null) ? $all['admin_layout'] : 'sidebar';

            return in_array($layout, ['sidebar', 'compact'], true) ? $layout : 'sidebar';
        } catch (\Throwable) {
            return 'sidebar';
        }
    }

    /**
     * The signed-in user's permission names, for the nav filter.
     *
     * Null when nobody is signed in, which the nav reads as "do not filter" —
     * the gate has already refused such a request, so this only keeps the
     * layout usable from a test or a CLI render.
     *
     * @return list<string>|null
     */
    private static function grantedPermissions(): ?array
    {
        try {
            $userId = CardboardServices::auth()->currentUserId();

            if (null === $userId) {
                return null;
            }

            $user = CardboardServices::users()->findById($userId);

            return null === $user ? null : $user->permissions();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function currentUserName(): string
    {
        try {
            $userId = CardboardServices::auth()->currentUserId();

            if (null === $userId) {
                return 'Guest';
            }

            $user = CardboardServices::users()->findById($userId);

            return null === $user ? 'Guest' : $user->name();
        } catch (\Throwable) {
            return 'Guest';
        }
    }

    /**
     * Map of admin nav item IDs to SVG icons (18x18, stroke-based).
     *
     * @return array<string, string>
     */
    /**
     * Resolve SVG icon for a nav item using IconRegistry.
     */
    private static function navIcon(string $itemId, string $dbIcon): string
    {
        $key = IconRegistry::resolveKey($itemId, $dbIcon);

        return '' !== $key ? IconRegistry::svg($key) : IconRegistry::svg('search');
    }

    /**
     * Render a single nav item or a group with children.
     *
     * @param array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string, children: list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string}>, is_expanded: bool} $item
     */
    private static function renderNavItem(array $item, string $activeSection): string
    {
        if ([] === $item['children']) {
            return self::navLinkHtml($item, $activeSection);
        }

        return self::navGroupHtml($item, $activeSection);
    }

    /**
     * Active state helpers shared by leaf links and group buttons.
     *
     * @param array{id: string, label: string} $item full nav item or child entry
     *
     * @return array{class: string, aria: string}
     */
    private static function navActiveState(array $item, string $activeSection): array
    {
        $isActive = strtolower($item['label']) === $activeSection || $item['id'] === $activeSection;

        return [
            'class' => $isActive ? ' layout-sidebar__link--active' : '',
            'aria' => $isActive ? ' aria-current="page"' : '',
        ];
    }

    /**
     * Leaf nav item — a plain link, plus a badge when a module says there is
     * something waiting.
     *
     * The badge is asked for HERE, after `hierarchicalItems()` has already
     * dropped everything the session may not reach. That ordering is the
     * whole reason the number is not a leak: a role without
     * `content.manage` never sees the Questions item, so it is never asked
     * how many are pending. A badge resolved before the filter would tell a
     * read-only moderator exactly what they are not allowed to read.
     *
     * @param array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string} $item
     */
    private static function navLinkHtml(array $item, string $activeSection): string
    {
        $state = self::navActiveState($item, $activeSection);
        $icon = self::navIcon($item['id'], $item['icon']);

        return '<a class="layout-sidebar__link' . $state['class'] . '" href="' . self::e($item['href']) . '"' . $state['aria'] . '>'
            . $icon
            . '<span class="layout-sidebar__link-label">' . self::e($item['label']) . '</span>'
            . self::navBadgeHtml($item['href'])
            . '</a>';
    }

    /**
     * The count badge for one nav surface, or '' when nothing should render.
     *
     * Keyed on the item's HREF, not its id: the id column is a generated
     * hash, and a badge keyed on a hash constant is a badge that matches
     * nothing in production while passing against the fallback list.
     *
     * The number is an accessible name, not a decoration: a screen reader
     * announcing "Questions" and nothing else would leave the reason for the
     * digit invisible, and a sighted user gets the same information from the
     * digits. `99+` is a deliberate cap — a five-digit badge widens the
     * sidebar and tells nobody anything the inbox does not.
     */
    private static function navBadgeHtml(string $navHref): string
    {
        $count = NavBadgeRegistry::for($navHref);
        if (null === $count) {
            return '';
        }

        $label = $count > 99 ? '99+' : (string) $count;
        $title = $count . ' waiting';

        return '<span class="layout-sidebar__badge" title="' . self::e($title) . '">'
            . self::e($label)
            . '<span class="sr-only"> ' . self::e($title) . '</span>'
            . '</span>';
    }

    /**
     * Group nav item — expandable button + nested child links.
     *
     * @param array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string, children: list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string}>, is_expanded: bool} $item
     */
    private static function navGroupHtml(array $item, string $activeSection): string
    {
        $isExpanded = $item['is_expanded'];
        $groupId = self::e($item['id']);
        $icon = self::navIcon($item['id'], $item['icon']);

        // Chevron SVG.
        $chevron = '<svg class="layout-sidebar__chevron" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';

        $html = '<div class="layout-sidebar__group' . ($isExpanded ? ' is-expanded' : '') . '" data-group="' . $groupId . '">';
        $html .= '<button type="button" class="layout-sidebar__link layout-sidebar__link--group" data-toggle-group="' . $groupId . '" aria-expanded="' . ($isExpanded ? 'true' : 'false') . '">';
        $html .= $icon;
        $html .= '<span class="layout-sidebar__link-label">' . self::e($item['label']) . '</span>';
        $html .= $chevron;
        $html .= '</button>';
        $html .= '<div class="layout-sidebar__children"><div class="layout-sidebar__children-inner">';

        foreach ($item['children'] as $child) {
            $state = self::navActiveState($child, $activeSection);
            $childIcon = self::navIcon((string) $child['id'], (string) $child['icon']);

            $html .= '<a class="layout-sidebar__link layout-sidebar__link--child' . $state['class'] . '" href="' . self::e((string) $child['href']) . '"' . $state['aria'] . '>';
            $html .= $childIcon;
            $html .= '<span class="layout-sidebar__link-label">' . self::e((string) $child['label']) . '</span>';
            $html .= '</a>';
        }

        $html .= '</div></div></div>';

        return $html;
    }

    /**
     * SVG icon: pencil (edit).
     */
    public static function iconEdit(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>';
    }

    /**
     * SVG icon: trash (delete).
     */
    public static function iconDelete(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>';
    }

    /**
     * SVG icon: eye (view the public page).
     */
    public static function iconView(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/></svg>';
    }

    /**
     * SVG icon: check (approve).
     */
    public static function iconApprove(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
    }

    /**
     * SVG icon: cross (reject).
     */
    public static function iconReject(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
    }

    /**
     * SVG icon: message (form responses).
     */
    public static function iconResponses(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
    }

    /**
     * SVG icon: shield (permissions).
     */
    public static function iconPermissions(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>';
    }
}
