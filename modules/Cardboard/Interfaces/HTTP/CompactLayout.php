<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP;

use View\ViewRenderer;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\HTTP\Middleware\CsrfMiddleware;
use Shell\ShellContextProviderRegistry;
use Modules\Core\Kernel\Contracts\RouterInterface;

/**
 * CompactLayout — top-menu admin shell (no sidebar).
 *
 * Renders a self-contained HTML page with a horizontal navigation bar
 * at the top instead of a fixed sidebar. Provides more content width
 * for data-heavy pages.
 */
final class CompactLayout
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
        $actionsHtml = '';

        foreach ($actions as $action) {
            $label = AdminLayout::e($action['label']);
            $href = AdminLayout::e($action['href']);
            $actionsHtml .= "<a class=\"cba__action\" href=\"{$href}\">{$label}</a>";
        }

        $actionsBar = '' !== $actionsHtml
            ? "<div class=\"cba__actions\">{$actionsHtml}</div>"
            : '';

        $titleEscaped = AdminLayout::e($title);
        $currentUserName = AdminLayout::e(self::currentUserName());
        $csrf = AdminLayout::csrfField();

        $navHtml = self::navHtml($activeSection, $router);

        // Recent items for command palette
        $recentJson = json_encode($recentItems, JSON_HEX_TAG | JSON_HEX_AMP) ?: '[]';

        // HTMX opt-in
        $htmxScript = self::htmxScriptTag();
        $htmxConfig = '' !== $htmxScript ? self::htmxConfigScript() : '';

        // Dynamic html lang from the kernel shell port.
        $htmlLang = ShellContextProviderRegistry::htmlLang() ?? 'en';

        // Load admin CSS from the Vite bundle (m-table, m-btn, m-card, …
        // plus the mark-switch entry for the Publish/Draft switch).
        $markCssLinks = AdminLayout::markCssLinks();

        return ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/compact.phtml', ['actionsBar' => $actionsBar, 'content' => $content, 'csrf' => $csrf, 'currentUserName' => $currentUserName, 'htmlLang' => $htmlLang, 'htmxConfig' => $htmxConfig, 'htmxScript' => $htmxScript, 'markCssLinks' => $markCssLinks, 'navHtml' => $navHtml, 'recentJson' => $recentJson, 'titleEscaped' => $titleEscaped, 'url' => AdminLayout::urlBuilder($router)]);
    }

    /**
     * The DB-driven top-menu links, groups and dropdowns.
     *
     * A group is highlighted when it CONTAINS the active section, but its
     * dropdown is never auto-opened: it would stay pinned open on every page
     * inside the group. It opens on hover, focus or click.
     */
    private static function navHtml(string $activeSection, ?RouterInterface $router): string
    {
        try {
            $navItems = CardboardServices::adminNav()->hierarchicalItems(
                $activeSection,
                self::grantedPermissions(),
                AdminLayout::reachablePaths($router),
            );
        } catch (\Throwable) {
            $navItems = [];
        }
        $html = '';

        foreach ($navItems as $item) {
            $children = $item['children'];

            if ([] === $children) {
                $active = strtolower($item['label']) === $activeSection || $item['id'] === $activeSection
                    ? ' m-nav__link--active'
                    : '';
                $html .= '<a class="m-nav__link' . $active . '" href="' . AdminLayout::e($item['href']) . '">'
                    . AdminLayout::e($item['label']) . '</a>';

                continue;
            }

            $buttonActive = $item['is_expanded'] ? ' m-nav__link--active' : '';
            $html .= '<div class="m-nav__group" data-compact-group="' . AdminLayout::e($item['id']) . '">'
                . '<button type="button" class="m-nav__link m-nav__link--group' . $buttonActive . '"'
                . ' aria-haspopup="true" aria-expanded="false">'
                . AdminLayout::e($item['label'])
                . '<svg class="m-nav__chevron" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>'
                . '</button>'
                . '<div class="m-nav__dropdown">';

            foreach ($children as $child) {
                $childActive = strtolower($child['label']) === $activeSection || $child['id'] === $activeSection
                    ? ' m-nav__link--active'
                    : '';
                $html .= '<a class="m-nav__link m-nav__link--child' . $childActive . '" href="' . AdminLayout::e($child['href']) . '">'
                    . AdminLayout::e($child['label']) . '</a>';
            }

            $html .= '</div></div>';
        }

        return $html;
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

    private static function htmxScriptTag(): string
    {
        $path = AppPaths::instance()->public('dist/htmx.min.js');

        if (!is_file($path)) {
            return '';
        }

        return '<script src="/dist/htmx.min.js" defer></script>';
    }

    private static function htmxConfigScript(): string
    {
        $token = CsrfMiddleware::token();
        $csrfHeader = '' !== $token
            ? 'e.detail.headers["X-CSRF-Token"]="' . AdminLayout::e($token) . '";'
            : '';

        return '<script nonce="__CSP_NONCE_PLACEHOLDER__">'
            . 'document.addEventListener("DOMContentLoaded",function(){'
            . 'if(typeof htmx!=="undefined"){'
            . 'document.body.addEventListener("htmx:configRequest",function(e){'
            . $csrfHeader
            . '});'
            . 'htmx.config.defaultSwapStyle="innerHTML";'
            . 'htmx.config.historyEnabled=true;'
            . '}'
            . '});'
            . '</script>';
    }
}
