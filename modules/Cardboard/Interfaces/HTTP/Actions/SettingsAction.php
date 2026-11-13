<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP\Actions;

use Modules\Cardboard\Application\Services\IconRegistry;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Cardboard\Interfaces\HTTP\CardboardServices;
use Modules\PageCache\Application\Services\CacheInvalidator;
use View\ViewRenderer;
use Themes\ThemePreference;
use Themes\ThemeSetting;
use Themes\ThemeSettingValues;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Themes\ThemeKernel;
use Themes\ThemeRegistry;
use Themes\ThemeResolver;
use Modules\Settings\Application\DTOs\MenuItemDTO;
use Themes\ThemeModule;
use Themes\ThemeSettings;

/**
 * Settings forms — task 9.4.
 *
 * Routes:
 * - GET  /mark/settings             settings overview (tabs: site, design, navigation)
 * - POST /mark/settings/site        save site settings
 * - POST /mark/settings/tokens      save design tokens
 * - POST /mark/settings/nav         save navigation item
 * - POST /mark/settings/nav/reorder     reorder navigation items (drag + Save order)
 * - POST /mark/settings/nav/{id}/delete  delete navigation item
 * - POST /mark/settings/flush-cache      flush page cache
 */
#[Route(path: '/mark/settings', method: 'GET', name: 'mark.settings')]
#[Route(path: '/mark/settings/site', method: 'POST', name: 'mark.settings.site', handlerMethod: 'saveSite')]
#[Route(path: '/mark/settings/tokens', method: 'POST', name: 'mark.settings.tokens', handlerMethod: 'saveTokens')]
#[Route(path: '/mark/settings/theme', method: 'POST', name: 'mark.settings.theme', handlerMethod: 'saveThemeSettings')]
#[Route(path: '/mark/settings/nav', method: 'POST', name: 'mark.settings.nav', handlerMethod: 'saveNav')]
#[Route(path: '/mark/settings/nav/{id}/delete', method: 'POST', name: 'mark.settings.nav.delete', handlerMethod: 'deleteNav')]
#[Route(path: '/mark/settings/nav/reorder', method: 'POST', name: 'mark.settings.nav.reorder', handlerMethod: 'reorderNav')]
#[Route(path: '/mark/settings/flush-cache', method: 'POST', name: 'mark.settings.flush-cache', handlerMethod: 'flushCache')]
#[Route(path: '/mark/settings/admin-nav', method: 'POST', name: 'mark.settings.admin-nav', handlerMethod: 'saveAdminNav')]
#[Route(path: '/mark/settings/admin-nav/{id}/toggle', method: 'POST', name: 'mark.settings.admin-nav.toggle', handlerMethod: 'toggleAdminNav')]
#[Route(path: '/mark/settings/admin-nav/{id}/sort', method: 'POST', name: 'mark.settings.admin-nav.sort', handlerMethod: 'sortAdminNav')]
#[Route(path: '/mark/settings/admin-nav/{id}/delete', method: 'POST', name: 'mark.settings.admin-nav.delete', handlerMethod: 'deleteAdminNav')]
#[Route(path: '/mark/settings/admin-nav/reorder', method: 'POST', name: 'mark.settings.admin-nav.reorder', handlerMethod: 'reorderAdminNav')]
#[Route(path: '/mark/settings/admin-nav/{id}/icon', method: 'POST', name: 'mark.settings.admin-nav.icon', handlerMethod: 'updateAdminNavIcon')]
final class SettingsAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        return Response::html($this->settingsPage());
    }

    /**
     * Render the settings page (site settings, design tokens, navigation).
     *
     * @param list<string> $errors
     */
    private function settingsPage(array $errors = []): string
    {
        $settings = CardboardServices::settings();
        $all = $settings->all();
        $csrf = AdminLayout::csrfField();
        $errorHtml = AdminLayout::messages($errors, 'err');

        $siteName = AdminLayout::e(is_string($all['site_name'] ?? null) ? $all['site_name'] : '');
        $siteDescription = AdminLayout::e(is_string($all['site_description'] ?? null) ? $all['site_description'] : '');
        $adminEmail = AdminLayout::e(is_string($all['admin_email'] ?? null) ? $all['admin_email'] : '');
        $timezone = AdminLayout::e(is_string($all['timezone'] ?? null) ? $all['timezone'] : 'UTC');
        $locale = AdminLayout::e(is_string($all['locale'] ?? null) ? $all['locale'] : 'en_US');
        $regRaw = $all['registration_enabled'] ?? '1';
        $registrationEnabled = is_string($regRaw) ? '1' === $regRaw : true;
        $regChecked = $registrationEnabled ? ' checked' : '';

        $layoutSelect = $this->layoutSelect($all);
        $themeSelect = $this->themeSelect($all);
        $themeSettingsCard = $this->themeSettingsCard($all, $csrf);
        $tokenTable = $this->designTokensTable($settings->designTokens());
        $navTable = $this->headerNavTable($csrf);

        $adminNavItems = CardboardServices::adminNav()->allItems();
        $iconOptions = IconRegistry::optionsHtml();
        $parentOptions = $this->adminParentOptions($adminNavItems);
        $adminNavTable = $this->adminNavTable($adminNavItems, $csrf);

        $content = ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/settings-form.phtml', ['url' => $this->urlBuilder(), 'settingsTabs' => AdminLayout::settingsTabs($this->urlBuilder(), 'general'), 'adminEmail' => $adminEmail, 'adminNavTable' => $adminNavTable, 'csrf' => $csrf, 'errorHtml' => $errorHtml, 'iconOptions' => $iconOptions, 'layoutSelect' => $layoutSelect, 'locale' => $locale, 'navTable' => $navTable, 'parentOptions' => $parentOptions, 'regChecked' => $regChecked, 'siteDescription' => $siteDescription, 'siteName' => $siteName, 'themeSelect' => $themeSelect, 'themeSettingsCard' => $themeSettingsCard, 'timezone' => $timezone, 'tokenTable' => $tokenTable]);

        return AdminLayout::render(
            'Settings',
            $content,
            'settings',
        router: $this->router());
    }

    /**
     * The admin-layout `<select>`.
     *
     * @param array<string, mixed> $all
     */
    private function layoutSelect(array $all): string
    {
        $current = is_string($all['admin_layout'] ?? null) ? $all['admin_layout'] : 'sidebar';
        $sidebar = 'sidebar' === $current ? ' selected' : '';
        $compact = 'compact' === $current ? ' selected' : '';

        return '<select id="admin_layout" name="admin_layout">'
            . '<option value="sidebar"' . $sidebar . '>Sidebar (default)</option>'
            . '<option value="compact"' . $compact . '>Compact (top menu)</option>'
            . '</select>';
    }

    /**
     * Themes the admin may pick — the kernel whitelist plus every discovered
     * module-carried theme. The single source both `themeSelect()` and the
     * `saveSite()` validation read, so the form and the save path cannot drift
     * apart: a theme the admin can pick but the save path rejects would look
     * like a save that silently did nothing.
     *
     * Public and static so the rule is unit-testable without the request/auth
     * stack `saveSite()` needs.
     *
     * @return list<string>
     */
    public static function selectableThemes(): array
    {
        $discovered = array_keys(ThemeRegistry::all());
        sort($discovered);

        return array_values(array_unique([...ThemeResolver::PUBLIC_WHITELIST, ...$discovered]));
    }

    /**
     * The `saveSite()` acceptance rule, extracted so it is unit-testable
     * without the request/auth stack — and so the form and the save path
     * share more than a comment.
     */
    public static function isSelectableTheme(string $theme): bool
    {
        return in_array($theme, self::selectableThemes(), true);
    }

    /**
     * The public-theme `<select>` (ADR-0002).
     *
     * Options render from `selectableThemes()`, so the form and the validation
     * in `saveSite()` cannot drift apart — a theme the admin can pick but the
     * save path rejects would look like a save that silently did nothing.
     *
     * @param array<string, mixed> $all
     */
    private function themeSelect(array $all): string
    {
        $raw = $all['theme'] ?? ThemeResolver::PUBLIC_THEME;
        $current = is_string($raw) ? $raw : ThemeResolver::PUBLIC_THEME;
        $options = '';

        foreach (self::selectableThemes() as $selectable) {
            $selected = $selectable === $current ? ' selected' : '';
            $options .= '<option value="' . $selectable . '"' . $selected . '>' . ucfirst($selectable) . '</option>';
        }

        return '<select id="theme" name="theme">' . $options . '</select>';
    }

    /**
     * The ACTIVE theme's own settings block, rendered from the schema the
     * theme declares.
     *
     * Only the active theme has controls, because only the active theme's
     * values are ever rendered — a form for a theme nobody is using is a form
     * whose saved values the admin cannot check. The values themselves are
     * still stored per theme (`theme.{name}.{field}`), so switching to another
     * theme and back restores what this one was configured with.
     *
     * A theme without the contract says so in words rather than rendering an
     * empty card: an empty form looks like a bug, and the admin cannot tell
     * "this theme has no options" from "the options failed to load".
     *
     * @param array<string, mixed> $all
     */
    private function themeSettingsCard(array $all, string $csrf): string
    {
        $theme = self::activeThemeName($all);
        $module = ThemeKernel::for($theme);
        $stored = [];

        if ($module instanceof ThemeSettings) {
            $settings = CardboardServices::settings();

            foreach ($module->settingsSchema() as $field) {
                $value = $settings->get(ThemeSettingValues::storageKey($theme, $field->key));

                if (is_string($value)) {
                    $stored[$field->key] = $value;
                }
            }
        }

        return self::renderThemeSettingsCard($theme, $module, $stored, $csrf, $this->urlBuilder());
    }

    /**
     * The card itself — a pure renderer, so the form the admin sees is
     * testable without a database or a request.
     *
     * The stored map is the caller's answer, not read here: the same function
     * then renders the EMPTY form (fresh install) and the FILLED one without a
     * second code path, and a test can prove what a stored `meatball` looks
     * like selected without migrating a tree.
     *
     * @param array<string, string>                              $stored field => stored value
     * @param \Closure(string, array<string, string|int>): string $url    route-name resolver
     */
    public static function renderThemeSettingsCard(
        string $theme,
        ThemeModule $module,
        array $stored,
        string $csrf,
        \Closure $url,
    ): string {
        $themeName = AdminLayout::e($theme);
        $schema = $module instanceof ThemeSettings ? $module->settingsSchema() : [];

        if ([] === $schema) {
            return '<div class="m-card">'
                . '<h2 class="m-heading">Theme settings</h2>'
                . '<p class="m-muted">' . $themeName . ' declares no settings.</p>'
                . '</div>';
        }

        $fields = '';

        foreach ($schema as $field) {
            // The stored value is vetted the same way the renderer vets it, so
            // the form opens on what the page is actually showing instead of on
            // a stale row the theme would reject.
            $raw = $stored[$field->key] ?? null;
            $current = is_string($raw) && $field->accepts($raw) ? $raw : $field->fallbackValue();
            $id = 'theme-setting-' . $field->key;
            $name = 'theme_settings[' . $field->key . ']';
            $fields .= '<label for="' . AdminLayout::e($id) . '">' . AdminLayout::e($field->label) . '</label>';
            $fields .= match ($field->type) {
                'select' => self::themeSettingSelect($field, $id, $name, $current),
                default  => '<input type="text" id="' . AdminLayout::e($id) . '" name="' . AdminLayout::e($name)
                    . '" value="' . AdminLayout::e($current) . '">',
            };
        }

        return '<div class="m-card">'
            . '<h2 class="m-heading">Theme settings — ' . $themeName . '</h2>'
            . '<p class="m-muted m-card-intro">Options this theme declares. Stored per theme, so switching back restores them.</p>'
            . '<form class="m-form" method="POST" action="' . AdminLayout::e($url('mark.settings.theme')) . '">'
            . $csrf
            . $fields
            . '<div><button class="m-btn m-btn-primary" type="submit">Save theme settings</button></div>'
            . '</form>'
            . '</div>';
    }

    /** One declared `select` field — the options are the field's own whitelist. */
    private static function themeSettingSelect(ThemeSetting $field, string $id, string $name, string $current): string
    {
        $options = '';

        foreach ($field->options as $value => $label) {
            $selected = (string) $value === $current ? ' selected' : '';
            $options .= '<option value="' . AdminLayout::e((string) $value) . '"' . $selected . '>'
                . AdminLayout::e($label) . '</option>';
        }

        return '<select id="' . AdminLayout::e($id) . '" name="' . AdminLayout::e($name) . '">' . $options . '</select>';
    }

    /**
     * The theme whose values this screen edits — the stored public theme,
     * resolved exactly as `ThemeResolver` resolves it for a public page.
     *
     * Shared by the form and the save path on purpose: a save that resolved a
     * different theme than the form rendered would write rows nobody reads. The
     * POST body never names the theme — otherwise a hand-made request could
     * write values for a theme that is not active, which the admin could then
     * never see or clear from this screen.
     *
     * @param array<string, mixed> $all
     */
    public static function activeThemeName(array $all): string
    {
        $raw = $all[ThemeResolver::THEME_SETTING] ?? ThemeResolver::PUBLIC_THEME;
        $stored = is_string($raw) ? $raw : ThemeResolver::PUBLIC_THEME;

        return ThemePreference::resolve($stored, self::selectableThemes(), ThemeResolver::PUBLIC_THEME);
    }

    /**
     * The design-token table, or the note that says there are none.
     *
     * @param array<string, mixed> $tokens
     */
    private function designTokensTable(array $tokens): string
    {
        $rows = '';

        foreach ($tokens as $key => $value) {
            $tokenKey = AdminLayout::e((string) $key);
            $json = json_encode($value);
            $tokenValue = AdminLayout::e(is_string($value) ? $value : (false === $json ? '' : $json));
            $rows .= "<tr><td><code>{$tokenKey}</code></td><td>{$tokenValue}</td></tr>";
        }

        if ('' === $rows) {
            return '<p class="m-muted">No design tokens yet.</p>';
        }

        return "<table class=\"m-table\"><thead><tr><th>Token</th><th>Value</th></tr></thead><tbody>{$rows}</tbody></table>";
    }

    /**
     * Parent options for the admin-nav add form — top-level items only, because
     * a group cannot nest inside a group.
     *
     * @param list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string}> $items
     */
    private function adminParentOptions(array $items): string
    {
        $options = '<option value="">None (top-level)</option>';

        foreach ($items as $item) {
            if (null === ($item['parent_id'] ?? null)) {
                $options .= '<option value="' . AdminLayout::e($item['id']) . '">' . AdminLayout::e($item['label']) . '</option>';
            }
        }

        return $options;
    }

    /**
     * The admin-navigation table: one row per item, plus the drag toolbar and
     * the "Save order" button the shared widget binds to.
     *
     * @param list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string}> $items
     */
    private function adminNavTable(array $items, string $csrf): string
    {
        $rows = '';

        foreach ($items as $item) {
            $itemId = AdminLayout::e($item['id']);
            $label = AdminLayout::e($item['label']);
            $href = AdminLayout::e($item['href']);
            $isActive = $item['is_active'];
            $statusClass = $isActive ? 'm-nav-row__status m-nav-row__status--active' : 'm-nav-row__status m-nav-row__status--hidden';
            $statusText = $isActive ? 'Active' : 'Hidden';
            $iconDelete = AdminLayout::iconDelete();
            $currentIcon = $item['icon'];
            $iconSelect = '<select name="icon" data-icon-select class="m-icon-select">'
                . IconRegistry::optionsHtml($currentIcon) . '</select>';
            // Indent children visually.
            $isChild = null !== ($item['parent_id'] ?? null);
            $iconUrl = $this->url('mark.settings.admin-nav.icon', ['id' => $itemId]);
            $toggleUrl = $this->url('mark.settings.admin-nav.toggle', ['id' => $itemId]);
            $deleteUrl = $this->url('mark.settings.admin-nav.delete', ['id' => $itemId]);
            $indent = $isChild ? ' class="m-nav-row--child"' : '';
            $prefix = $isChild ? '<span class="m-nav-row__branch">└</span> ' : '';
            $rows .= "<tr data-nav-id=\"{$itemId}\" draggable=\"true\"{$indent}>
                <td class=\"m-drag-handle\" title=\"Drag to reorder\">⋮⋮</td>
                <td><span class=\"{$statusClass}\">{$prefix}{$label}</span></td>
                <td><code>{$href}</code></td>
                <td>                        <form method=\"POST\" action=\"{$iconUrl}\" class=\"m-inline-form m-inline-form--icon\">
                        {$csrf}
                        {$iconSelect}
                        <button class=\"m-btn m-btn-sm\" title=\"Save icon\">✓</button>
                    </form>
                </td>
                <td>
                    <form method=\"POST\" action=\"{$toggleUrl}\" class=\"m-inline-form\">
                        {$csrf}
                        <button class=\"m-btn m-btn-sm\" title=\"Toggle visibility\">{$statusText}</button>
                    </form>
                </td>
                <td>
                    <form method=\"POST\" action=\"{$deleteUrl}\" class=\"m-inline-form\">
                        {$csrf}
                        <button class=\"m-btn m-btn-error m-btn-sm m-btn-icon\"
                                data-confirm=\"Delete this nav item?\" title=\"Delete\">{$iconDelete}</button>
                    </form>
                </td>
            </tr>";
        }

        if ('' === $rows) {
            return '<p class="m-muted">No admin nav items — using defaults.</p>';
        }

        return ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/settings-admin-table.phtml', ['url' => $this->urlBuilder(), 'adminNavRows' => $rows]);
    }

    /**
     * The public (header) navigation table.
     *
     * It is the SAME drag widget the admin table uses, because the two answer
     * the same question — "what order do these rows go in?" — and a second
     * hand-written drag implementation would be a second place for the drop
     * index to be off by one. What each table owns is its own reorder endpoint:
     * the table carries `data-reorder-url`, and the shared script posts to the
     * URL the table declares, so a public-nav drag can never reach the admin
     * route.
     */
    private function headerNavTable(string $csrf): string
    {
        $rows = '';
        foreach (CardboardServices::menu()->findByLocation('header') as $item) {
            $itemId = AdminLayout::e((string) $item['id']);
            $label = AdminLayout::e((string) $item['label']);
            $href = AdminLayout::e((string) $item['href']);
            $iconDelete = AdminLayout::iconDelete();
            $deleteUrl = $this->url('mark.settings.nav.delete', ['id' => $itemId]);
            $rows .= "<tr data-nav-id=\"{$itemId}\" draggable=\"true\">"
                . '<td class="m-drag-handle" title="Drag to reorder">⋮⋮</td>'
                . "<td>{$label}</td>"
                . "<td><code>{$href}</code></td>"
                . '<td>'
                . "<form method=\"POST\" action=\"{$deleteUrl}\" class=\"m-inline-form\">"
                . $csrf
                . '<button class="m-btn m-btn-error m-btn-sm m-btn-icon"'
                . ' data-confirm="Delete this nav item?" title="Delete">' . $iconDelete . '</button>'
                . '</form>'
                . '</td>'
                . '</tr>';
        }

        if ('' === $rows) {
            return '<p class="m-muted">No navigation items yet.</p>';
        }

        return '<div class="m-table-toolbar">'
            . '<p class="m-muted">Drag rows to reorder. Click "Save order" when done.</p>'
            . '<button type="button" class="m-btn m-btn-primary m-btn-sm" id="header-nav-save-order"'
            . ' data-nav-save-order>Save order</button>'
            . '</div>'
            . '<div class="m-table-scroll"><table class="m-table" id="header-nav-table" data-nav-table'
            . ' data-reorder-url="' . AdminLayout::e($this->url('mark.settings.nav.reorder')) . '">'
            . '<thead><tr><th class="m-table__cell--drag"></th><th>Label</th><th>Href</th><th></th></tr></thead>'
            . "<tbody>{$rows}</tbody></table></div>";
    }

    public function saveSite(Request $request): Response
    {
        $settings = CardboardServices::settings();
        $values = [
            'site_name'             => 'general',
            'site_description'      => 'general',
            'admin_email'           => 'general',
            'timezone'              => 'general',
            'locale'                => 'general',
            'registration_enabled'  => 'general',
            'admin_layout'          => 'design',
            'theme'                 => 'design',
        ];

        foreach ($values as $key => $group) {
            $value = $request->body($key);
            if ('admin_layout' === $key) {
                $layout = is_string($value) ? $value : 'sidebar';
                $settings->set($key, in_array($layout, ['sidebar', 'compact'], true) ? $layout : 'sidebar', $group, false);
            } elseif ('theme' === $key) {
                // ADR-0002: the posted value must be one `themeSelect()` could
                // have offered (`selectableThemes()`); anything else falls back
                // to the default instead of being stored.
                $theme = is_string($value) ? $value : ThemeResolver::PUBLIC_THEME;
                $settings->set(
                    $key,
                    self::isSelectableTheme($theme) ? $theme : ThemeResolver::PUBLIC_THEME,
                    $group,
                    false,
                );
            } elseif ('registration_enabled' === $key) {
                $settings->set($key, in_array($value, ['on', '1', 'true', true], true), $group, true);
            } elseif ('admin_email' === $key) {
                $email = is_string($value) ? $value : '';
                $settings->set(
                    $key,
                    '' === $email || false !== filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
                    $group,
                    true,
                );
            } else {
                $settings->set($key, is_string($value) ? $value : '', $group, true);
            }
        }

        CacheInvalidator::flush();
        \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.save', 'settings', 'Saved site settings', $request->getClientIp());

        return Response::redirect($this->url('mark.settings'));
    }

    /**
     * Store the posted values of the ACTIVE theme's declared fields.
     *
     * Iterates the POST body, not the schema: a field the form did not send
     * keeps its stored value. Writing every schema field on every save would
     * turn a partially-submitted form into a silent reset.
     *
     * An unknown key or an unacceptable value never fails the save — the form
     * was rendered from this schema, so the request is hand-made or the schema
     * changed mid-session. Unknown keys are dropped (logged), unacceptable
     * values become the field's default (logged), which is the same policy
     * `saveSite()` applies to an unknown theme.
     */
    public function saveThemeSettings(Request $request): Response
    {
        $settings = CardboardServices::settings();
        $theme = self::activeThemeName($settings->all());
        $module = ThemeKernel::for($theme);

        if (!$module instanceof ThemeSettings) {
            error_log('[SettingsAction.saveThemeSettings] theme=' . $theme . ' declares no settings schema — nothing stored');

            return Response::redirect($this->url('mark.settings'));
        }

        $posted = $request->body('theme_settings');
        $saved = 0;

        foreach (is_array($posted) ? $posted : [] as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            $sanitized = ThemeSettingValues::sanitize($module, $key, $value);

            if (null === $sanitized) {
                error_log('[SettingsAction.saveThemeSettings] dropped unknown field `' . $key . '` for theme=' . $theme);

                continue;
            }

            if ($sanitized !== trim($value)) {
                error_log('[SettingsAction.saveThemeSettings] field `' . $key . '` value `' . trim($value)
                    . '` is not accepted by theme=' . $theme . ' — stored `' . $sanitized . '` instead');
            }

            $settings->set(ThemeSettingValues::storageKey($theme, $key), $sanitized, 'design', false);
            ++$saved;
        }

        if (0 !== $saved) {
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log(
                'settings.theme',
                'settings',
                'Saved ' . $saved . ' theme setting(s) for ' . $theme,
                $request->getClientIp(),
            );
        }

        return Response::redirect($this->url('mark.settings'));
    }

    public function saveTokens(Request $request): Response
    {
        $key = trim($request->bodyString('token_key'));
        $value = trim($request->bodyString('token_value'));

        if ('' !== $key && '' !== $value) {
            CardboardServices::settings()->setDesignToken($key, $value);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.tokens', 'settings', 'Saved design token ' . $key, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.settings'));
    }

    public function saveNav(Request $request): Response
    {
        $label = trim($request->bodyString('label'));
        $href = trim($request->bodyString('href'));
        $sortRaw = $request->body('sort_order');
        $sort = is_numeric($sortRaw) ? (int) $sortRaw : 0;

        // F-8: validate href with the same rule the public renderer uses — an
        // invalid value must never reach menu_items (it would crash the public
        // homepage at render time).
        if ('' !== $label && '' !== $href && !MenuItemDTO::isValidHref($href)) {
            return Response::html($this->settingsPage([
                'Navigation href must be a valid URL or path.',
            ]));
        }

        if ('' !== $label && '' !== $href) {
            CardboardServices::menu()->create($label, $href, 'header', $sort);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.nav', 'settings', 'Added nav item ' . $label, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.settings'));
    }

    public function deleteNav(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null !== $id) {
            CardboardServices::menu()->delete($id);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.nav.delete', 'settings', 'Deleted nav item ' . $id, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.settings'));
    }

    public function flushCache(Request $request): Response
    {
        $deleted = CacheInvalidator::flush();
        \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.flush-cache', 'settings', 'Flushed page cache (' . $deleted . ' files)', $request->getClientIp());

        return Response::redirect($this->url('mark.settings'));
    }

    public function saveAdminNav(Request $request): Response
    {
        $label = trim($request->bodyString('label'));
        $href = trim($request->bodyString('href'));
        $sortRaw = $request->body('sort_order');
        $sort = is_numeric($sortRaw) ? (int) $sortRaw : 0;
        $icon = $request->bodyString('icon');
        $parentId = trim($request->bodyString('parent_item_id'));

        if ('' !== $icon && !IconRegistry::isValid($icon)) {
            $icon = '';
        }

        if ('' !== $label && '' !== $href && !MenuItemDTO::isValidHref($href)) {
            return Response::html($this->settingsPage([
                'Admin nav href must be a valid URL or path.',
            ]));
        }

        if ('' !== $label && '' !== $href) {
            CardboardServices::menu()->create($label, $href, 'admin', $sort, '' !== $parentId ? $parentId : null, false, null, $icon);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.admin-nav', 'settings', 'Added admin nav item ' . $label, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.settings'));
    }

    public function toggleAdminNav(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null !== $id) {
            CardboardServices::menu()->toggleActive($id);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.admin-nav.toggle', 'settings', 'Toggled admin nav item ' . $id, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.settings'));
    }

    public function sortAdminNav(Request $request): Response
    {
        $id = $request->routeParam('id');
        $sortRaw = $request->body('sort_order');
        $sort = is_numeric($sortRaw) ? (int) $sortRaw : 0;

        if (null !== $id) {
            CardboardServices::menu()->updateSortOrder($id, $sort);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.admin-nav.sort', 'settings', 'Reordered admin nav item ' . $id . ' to ' . $sort, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.settings'));
    }

    public function deleteAdminNav(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null !== $id) {
            CardboardServices::menu()->delete($id);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.admin-nav.delete', 'settings', 'Deleted admin nav item ' . $id, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.settings'));
    }

    public function reorderAdminNav(Request $request): Response
    {
        $pairs = $this->reorderPairs($request);

        if ([] !== $pairs) {
            CardboardServices::menu()->batchUpdateSortOrder($pairs);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.admin-nav.reorder', 'settings', 'Reordered admin nav (' . count($pairs) . ' items)', $request->getClientIp());
        }

        return Response::html('ok');
    }

    /**
     * Reorder the PUBLIC navigation — the header menu the site renders.
     *
     * Same wire shape as the admin table (one JSON array of {id, sort_order}),
     * because both tables are the same widget; what differs is which rows were
     * rendered, and the table names its own endpoint so neither can post into
     * the other's order.
     */
    public function reorderNav(Request $request): Response
    {
        $pairs = $this->reorderPairs($request);

        if ([] !== $pairs) {
            CardboardServices::menu()->batchUpdateSortOrder($pairs);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.nav.reorder', 'settings', 'Reordered header nav (' . count($pairs) . ' items)', $request->getClientIp());
        }

        return Response::html('ok');
    }

    /**
     * The id => sort_order pairs a reorder POST carried.
     *
     * Shared by both nav tables: they post the same JSON and differ only in
     * which rows were on the page, so parsing it twice would be two places for
     * the shape to drift. Anything that is not a list of {id, sort_order} pairs
     * yields an empty map, and an empty map writes nothing — a malformed body
     * must not renumber the menu.
     *
     * @return array<string, int>
     */
    private function reorderPairs(Request $request): array
    {
        $json = $request->bodyString('items_json');
        $items = '' !== $json ? json_decode($json, true) : null;

        if (!is_array($items)) {
            return [];
        }

        $pairs = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = is_string($item['id'] ?? null) ? $item['id'] : '';
            if ('' !== $id) {
                $pairs[$id] = is_numeric($item['sort_order'] ?? null) ? (int) $item['sort_order'] : 0;
            }
        }

        return $pairs;
    }

    public function updateAdminNavIcon(Request $request): Response
    {
        $id = $request->routeParam('id');
        $icon = $request->bodyString('icon');

        if (null !== $id && IconRegistry::isValid($icon)) {
            CardboardServices::menu()->updateIcon($id, $icon);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.admin-nav.icon', 'settings', 'Updated icon for admin nav item ' . $id, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.settings'));
    }
}
