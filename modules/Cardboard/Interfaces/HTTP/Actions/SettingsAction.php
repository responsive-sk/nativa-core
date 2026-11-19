<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP\Actions;

use Modules\Cardboard\Application\Services\IconRegistry;
use Modules\Cardboard\Interfaces\HTTP\CardboardLayout;
use Modules\Cardboard\Interfaces\HTTP\CardboardServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Database\Infrastructure\Persistence\ConnectionSummary;
use Modules\PageCache\Application\Services\CacheInvalidator;
use Modules\Settings\Application\DTOs\MenuItemDTO;
use Themes\ThemeKernel;
use Themes\ThemeModule;
use Themes\ThemePreference;
use Themes\ThemeRegistry;
use Themes\ThemeResolver;
use Themes\ThemeSetting;
use Themes\ThemeSettings;
use Themes\ThemeSettingValues;
use View\ViewRenderer;

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
#[Route(path: '/mark/settings/panel-nav', method: 'POST', name: 'mark.settings.panel-nav', handlerMethod: 'savePanelNav')]
#[Route(path: '/mark/settings/panel-nav/{id}/toggle', method: 'POST', name: 'mark.settings.panel-nav.toggle', handlerMethod: 'togglePanelNav')]
#[Route(path: '/mark/settings/panel-nav/{id}/sort', method: 'POST', name: 'mark.settings.panel-nav.sort', handlerMethod: 'sortPanelNav')]
#[Route(path: '/mark/settings/panel-nav/{id}/delete', method: 'POST', name: 'mark.settings.panel-nav.delete', handlerMethod: 'deletePanelNav')]
#[Route(path: '/mark/settings/panel-nav/reorder', method: 'POST', name: 'mark.settings.panel-nav.reorder', handlerMethod: 'reorderPanelNav')]
#[Route(path: '/mark/settings/panel-nav/{id}/icon', method: 'POST', name: 'mark.settings.panel-nav.icon', handlerMethod: 'updatePanelNavIcon')]
#[Route(path: '/mark/settings/panel-nav/{id}/parent', method: 'POST', name: 'mark.settings.panel-nav.parent', handlerMethod: 'movePanelNav')]
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
        $csrf = CardboardLayout::csrfField();
        $errorHtml = CardboardLayout::messages($errors, 'err');

        $siteName = CardboardLayout::e(is_string($all['site_name'] ?? null) ? $all['site_name'] : '');
        $siteDescription = CardboardLayout::e(is_string($all['site_description'] ?? null) ? $all['site_description'] : '');
        $adminEmail = CardboardLayout::e(is_string($all['admin_email'] ?? null) ? $all['admin_email'] : '');
        $timezone = CardboardLayout::e(is_string($all['timezone'] ?? null) ? $all['timezone'] : 'UTC');
        $locale = CardboardLayout::e(is_string($all['locale'] ?? null) ? $all['locale'] : 'en_US');
        $regRaw = $all['registration_enabled'] ?? '1';
        $registrationEnabled = is_string($regRaw) ? '1' === $regRaw : true;
        $regChecked = $registrationEnabled ? ' checked' : '';

        $layoutSelect = $this->layoutSelect($all);
        $themeSelect = $this->themeSelect($all);
        $themeSettingsCard = $this->themeSettingsCard($all, $csrf);
        $tokenTable = $this->designTokensTable($settings->designTokens());
        $navTable = $this->headerNavTable($csrf);

        $panelNavItems = CardboardServices::panelNav()->allItems();
        $storedNavItems = CardboardServices::panelNav()->storedItems();
        $iconOptions = IconRegistry::optionsHtml();
        $parentOptions = $this->panelParentOptions($panelNavItems);
        $panelNavTable = $this->panelNavTable($panelNavItems, $storedNavItems, $csrf);
        $declaredNavTable = self::declaredNavTable(
            CardboardServices::panelNav()->declaredItems(),
            $storedNavItems,
            $csrf,
            $this->url('mark.settings.panel-nav'),
        );

        $content = ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/settings-form.phtml', ['url' => $this->urlBuilder(), 'settingsTabs' => CardboardLayout::settingsTabs($this->urlBuilder(), 'general'), 'databaseCard' => self::renderDatabaseCard(ConnectionSummary::fromEnvironment()), 'adminEmail' => $adminEmail, 'panelNavTable' => $panelNavTable, 'declaredNavTable' => $declaredNavTable, 'csrf' => $csrf, 'errorHtml' => $errorHtml, 'iconOptions' => $iconOptions, 'layoutSelect' => $layoutSelect, 'locale' => $locale, 'navTable' => $navTable, 'parentOptions' => $parentOptions, 'regChecked' => $regChecked, 'siteDescription' => $siteDescription, 'siteName' => $siteName, 'themeSelect' => $themeSelect, 'themeSettingsCard' => $themeSettingsCard, 'timezone' => $timezone, 'tokenTable' => $tokenTable]);

        return CardboardLayout::render(
            'Settings',
            $content,
            'settings',
            router: $this->router(),
        );
    }

    /**
     * The Database tab: which engine this process talks to, and the DSN it
     * talks to it with.
     *
     * Read-only on purpose, and not a form with a disabled input: the engine is
     * chosen by the environment (`DB_DRIVER`, `DB_DSN`, `DB_DATABASE_PREFIX`)
     * and read on every connection, so a value saved here could never be the
     * one the panel boots with — reading the setting would need the connection
     * the setting picks. The card says so in words, because a screen that looks
     * editable and silently is not is worse than no screen.
     *
     * The DSN arrives redacted (`ConnectionSummary::redact()`); this renderer
     * never sees the password.
     */
    public static function renderDatabaseCard(ConnectionSummary $summary): string
    {
        $engine = CardboardLayout::e($summary->driver)
            . ($summary->driverIsDefault ? ' <span class="m-muted">(default — DB_DRIVER is not set)</span>' : '');

        $dsn = '' === $summary->dsn
            ? '<span class="m-muted">Not set — SQLite reads a file per module from <code>storage/data/</code>.</span>'
            : '<code>' . CardboardLayout::e($summary->dsn) . '</code>';

        $moduleDatabases = match (true) {
            $summary->usesPrefix() => 'One database per module: <code>'
                . CardboardLayout::e($summary->prefix) . '&lt;module&gt;</code>',
            'pgsql' === $summary->driver => 'One schema per module, inside the database the DSN names',
            default                      => 'One file per module under <code>storage/data/</code> (<code>DB_&lt;MODULE&gt;</code> overrides one path)',
        };

        return '<div class="m-card">'
            . '<h2 class="m-heading">Database</h2>'
            . '<p class="m-muted m-card-intro">Where this panel keeps its data. Read-only: the engine is configured in'
            . ' the environment and cannot be changed from here — the panel needs a database before it can read a setting.</p>'
            . '<table class="m-table"><thead><tr><th>Setting</th><th>Value</th></tr></thead><tbody>'
            . '<tr><td>Engine</td><td>' . $engine . '</td></tr>'
            . '<tr><td><code>DB_DSN</code></td><td>' . $dsn . '</td></tr>'
            . '<tr><td>Module databases</td><td>' . $moduleDatabases . '</td></tr>'
            . '</tbody></table>'
            . '<p class="m-muted m-hint m-hint--tight">Passwords are masked. To switch engines, set'
            . ' <code>DB_DRIVER</code> and <code>DB_DSN</code> in the environment, run'
            . ' <code>php bin/cms migrate</code>, and move the data with'
            . ' <code>db:export</code> / <code>db:import</code>.</p>'
            . '</div>';
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
     * @param array<string, string>                               $stored field => stored value
     * @param \Closure(string, array<string, string|int>): string $url    route-name resolver
     */
    public static function renderThemeSettingsCard(
        string $theme,
        ThemeModule $module,
        array $stored,
        string $csrf,
        \Closure $url,
    ): string {
        $themeName = CardboardLayout::e($theme);
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
            $fields .= '<label for="' . CardboardLayout::e($id) . '">' . CardboardLayout::e($field->label) . '</label>';
            $fields .= match ($field->type) {
                'select' => self::themeSettingSelect($field, $id, $name, $current),
                default  => '<input type="text" id="' . CardboardLayout::e($id) . '" name="' . CardboardLayout::e($name)
                    . '" value="' . CardboardLayout::e($current) . '">',
            };
        }

        return '<div class="m-card">'
            . '<h2 class="m-heading">Theme settings — ' . $themeName . '</h2>'
            . '<p class="m-muted m-card-intro">Options this theme declares. Stored per theme, so switching back restores them.</p>'
            . '<form class="m-form" method="POST" action="' . CardboardLayout::e($url('mark.settings.theme')) . '">'
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
            $options .= '<option value="' . CardboardLayout::e((string) $value) . '"' . $selected . '>'
                . CardboardLayout::e($label) . '</option>';
        }

        return '<select id="' . CardboardLayout::e($id) . '" name="' . CardboardLayout::e($name) . '">' . $options . '</select>';
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
            $tokenKey = CardboardLayout::e((string) $key);
            $json = json_encode($value);
            $tokenValue = CardboardLayout::e(is_string($value) ? $value : (false === $json ? '' : $json));
            $rows .= "<tr><td><code>{$tokenKey}</code></td><td>{$tokenValue}</td></tr>";
        }

        if ('' === $rows) {
            return '<p class="m-muted">No design tokens yet.</p>';
        }

        return "<table class=\"m-table\"><thead><tr><th>Token</th><th>Value</th></tr></thead><tbody>{$rows}</tbody></table>";
    }

    /**
     * Parent options for the panel-nav add form — top-level items only, because
     * a group cannot nest inside a group.
     *
     * @param list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string}> $items
     */
    private function panelParentOptions(array $items): string
    {
        $options = '<option value="">None (top-level)</option>';

        foreach ($items as $item) {
            if (null === ($item['parent_id'] ?? null)) {
                $options .= '<option value="' . CardboardLayout::e($item['id']) . '">' . CardboardLayout::e($item['label']) . '</option>';
            }
        }

        return $options;
    }

    /**
     * The parents a single row may be moved under — STORED ROWS only.
     *
     * `menu_items.parent_item_id` is a foreign key into `menu_items`, so an
     * option naming a declaration (`_blog`) is a value SQLite refuses: the
     * operator picks it and the panel answers 500. A declaration needs a row of
     * its own before anything can be nested under it, which is the rule the add
     * form already follows.
     *
     * Any depth is offered now that the sidebar draws a group inside a group,
     * but never the row itself or anything beneath it: that is a cycle, and the
     * tree it would build references itself.
     *
     * @param list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string}> $stored
     */
    private function moveParentOptions(array $stored, string $itemId, ?string $currentParent): string
    {
        $options = '<option value=""' . (null === $currentParent ? ' selected' : '') . '>Top level</option>';

        $parentOf = [];
        foreach ($stored as $row) {
            $parentOf[$row['id']] = $row['parent_id'] ?? null;
        }

        foreach ($stored as $candidate) {
            if ($candidate['id'] === $itemId || self::isInside($parentOf, $candidate['id'], $itemId)) {
                continue;
            }

            $selected = $candidate['id'] === $currentParent ? ' selected' : '';
            $options .= '<option value="' . CardboardLayout::e($candidate['id']) . '"' . $selected . '>'
                . CardboardLayout::e($candidate['label']) . '</option>';
        }

        return $options;
    }

    /**
     * Is `$id` somewhere under `$ancestor`? Walks the stored parent chain.
     *
     * The one shape no renderer survives is a cycle: moving an item under its
     * own descendant makes the tree reference itself, and the sidebar either
     * recurses until the request dies or loses the branch. The same walk keeps
     * the row's own subtree out of its "Move under" select.
     *
     * @param array<string, ?string> $parentOf
     */
    private static function isInside(array $parentOf, string $id, string $ancestor): bool
    {
        $guard = [];

        while (isset($parentOf[$id]) && !isset($guard[$id])) {
            $guard[$id] = true;
            $parent = $parentOf[$id];

            if (null === $parent) {
                return false;
            }

            if ($parent === $ancestor) {
                return true;
            }

            $id = $parent;
        }

        return false;
    }

    /**
     * The panel-navigation table: one row per item, plus the drag toolbar and
     * the "Save order" button the shared widget binds to.
     *
     * @param list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string}> $items
     * @param list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string}> $stored
     */
    private function panelNavTable(array $items, array $stored, string $csrf): string
    {
        $rows = '';

        foreach ($items as $item) {
            $itemId = CardboardLayout::e($item['id']);
            $label = CardboardLayout::e($item['label']);
            $href = CardboardLayout::e($item['href']);
            $isActive = $item['is_active'];
            $statusClass = $isActive ? 'm-nav-row__status m-nav-row__status--active' : 'm-nav-row__status m-nav-row__status--hidden';
            $statusText = $isActive ? 'Active' : 'Hidden';
            $iconDelete = CardboardLayout::iconDelete();
            $currentIcon = $item['icon'];
            $iconSelect = '<select name="icon" data-icon-select class="m-icon-select">'
                . IconRegistry::optionsHtml($currentIcon) . '</select>';
            // Indent children visually.
            $isChild = null !== ($item['parent_id'] ?? null);
            $iconUrl = $this->url('mark.settings.panel-nav.icon', ['id' => $itemId]);
            $toggleUrl = $this->url('mark.settings.panel-nav.toggle', ['id' => $itemId]);
            $deleteUrl = $this->url('mark.settings.panel-nav.delete', ['id' => $itemId]);
            $parentUrl = $this->url('mark.settings.panel-nav.parent', ['id' => $itemId]);
            $parentSelect = '<select name="parent_item_id">'
                . $this->moveParentOptions($stored, $item['id'], $item['parent_id'] ?? null) . '</select>';
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
                    <form method=\"POST\" action=\"{$parentUrl}\" class=\"m-inline-form\">
                        {$csrf}
                        {$parentSelect}
                        <button class=\"m-btn m-btn-sm\" title=\"Move under\">✓</button>
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

        // The add form accepts two rows with one name, and the sidebar then
        // resolves that name to neither of them. On the panel the row simply
        // stops looking active — which is what a correct page looks like too —
        // so the editor is the only place the mistake can be seen.
        $conflicts = CardboardServices::panelNav()->conflictingLabels();
        $warning = '';

        if ([] !== $conflicts) {
            $names = implode(', ', array_map(
                static fn (string $label): string => '"' . CardboardLayout::e($label) . '"',
                $conflicts,
            ));

            $warning .= '<div class="cba-msg cba-msg--err">Two or more nav items share a name: '
                . $names
                . '. A shared name marks neither item active — rename one of them.</div>';
        }

        // The sidebar drops a link no GET route serves — right, and silent: the
        // row is saved, the panel is opened, and the link is not there. Naming
        // it beside the table is the difference between a configured link and
        // a broken one.
        $unserved = CardboardServices::panelNav()->unservedStoredItems(
            CardboardLayout::reachablePaths($this->router),
        );

        foreach ($unserved as $row) {
            $warning .= '<div class="cba-msg cba-msg--err">No route serves this nav link, so the sidebar hides it: <code>'
                . CardboardLayout::e($row['href']) . '</code> (' . CardboardLayout::e($row['label']) . ').</div>';
        }

        return ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/settings-nav-table.phtml', ['url' => $this->urlBuilder(), 'panelNavRows' => $rows, 'panelNavWarning' => $warning]);
    }

    /**
     * The declarations, listed beside the editable table.
     *
     * A declared item has no row, and the table above can edit rows only — so
     * without this the operator sees a link in the sidebar and nothing on this
     * screen that accounts for it. Read-only on purpose: the add form above is
     * already the way to create the row that overrides a declaration, and a
     * second write path for the same table would be a second place for
     * `menu_items` to be written wrongly.
     *
     * Public and static so the listing is testable without the request and
     * auth stack the settings page needs (`selectableThemes()` precedent).
     *
     * @param list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string, permission: ?string}> $declared
     * @param list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string, permission: ?string}> $stored
     */
    public static function declaredNavTable(array $declared, array $stored, string $csrf = '', string $addUrl = ''): string
    {
        if ([] === $declared) {
            return '';
        }

        $rowsByHref = [];
        foreach ($stored as $row) {
            $rowsByHref[$row['href']] = ($rowsByHref[$row['href']] ?? 0) + 1;
        }

        $labelsById = [];
        foreach ($declared as $item) {
            $labelsById[$item['id']] = $item['label'];
        }

        $rows = '';
        foreach ($declared as $item) {
            $overridden = ($rowsByHref[$item['href']] ?? 0) > 0;
            $source = $overridden
                ? '<span class="m-badge m-badge-info">Overridden by a row</span>'
                : '<span class="m-badge m-badge-gray">Code only</span>';
            $parent = null === $item['parent_id']
                ? '<span class="m-muted">—</span>'
                : CardboardLayout::e($labelsById[$item['parent_id']] ?? $item['parent_id']);

            // A declaration cannot be a parent: `menu_items.parent_item_id`
            // is a foreign key, so the clever form of this control is to write
            // the row for the operator instead of asking them to retype the
            // label, the href, the order and the icon by hand.
            $addCell = '';

            if (!$overridden && '' !== $csrf && '' !== $addUrl) {
                $addCell = '<form method="POST" action="' . CardboardLayout::e($addUrl) . '" class="m-inline-form">'
                    . $csrf
                    . '<input type="hidden" name="label" value="' . CardboardLayout::e($item['label']) . '">'
                    . '<input type="hidden" name="href" value="' . CardboardLayout::e($item['href']) . '">'
                    . '<input type="hidden" name="sort_order" value="' . $item['sort_order'] . '">'
                    . '<input type="hidden" name="icon" value="' . CardboardLayout::e($item['icon']) . '">'
                    . '<button class="m-btn m-btn-sm">Add row</button>'
                    . '</form>';
            }

            $rows .= '<tr>'
                . '<td>' . CardboardLayout::e($item['label']) . '</td>'
                . '<td><code>' . CardboardLayout::e($item['href']) . '</code></td>'
                . '<td>' . $item['sort_order'] . '</td>'
                . '<td>' . CardboardLayout::e($item['icon']) . '</td>'
                . '<td>' . $parent . '</td>'
                . '<td>' . CardboardLayout::e($item['permission'] ?? '—') . '</td>'
                . '<td>' . $source . '</td>'
                . '<td>' . $addCell . '</td>'
                . '</tr>';
        }

        return '<h3 class="m-heading">Declared in code</h3>'
            . '<p class="m-muted m-card-intro">Each installed module declares its own entry. A stored row with the same href overrides the declaration — that is what the table above edits; add one below to relabel a declared item or change its order.</p>'
            . '<div class="m-table-scroll"><table class="m-table m-table--wide"><thead><tr>'
            . '<th>Label</th><th>Href</th><th>Order</th><th>Icon</th><th>Parent</th><th>Permission</th><th>Source</th><th>Row</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
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
            $itemId = CardboardLayout::e((string) $item['id']);
            $label = CardboardLayout::e((string) $item['label']);
            $href = CardboardLayout::e((string) $item['href']);
            $iconDelete = CardboardLayout::iconDelete();
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
            . ' data-reorder-url="' . CardboardLayout::e($this->url('mark.settings.nav.reorder')) . '">'
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
            $saved++;
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

    public function savePanelNav(Request $request): Response
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
                'Nav href must be a valid URL or path.',
            ]));
        }

        if ('' !== $label && '' !== $href) {
            CardboardServices::menu()->create($label, $href, 'mark', $sort, '' !== $parentId ? $parentId : null, false, null, $icon);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.panel-nav', 'settings', 'Added nav item ' . $label, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.settings'));
    }

    public function togglePanelNav(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null !== $id) {
            CardboardServices::menu()->toggleActive($id);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.panel-nav.toggle', 'settings', 'Toggled nav item ' . $id, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.settings'));
    }

    public function sortPanelNav(Request $request): Response
    {
        $id = $request->routeParam('id');
        $sortRaw = $request->body('sort_order');
        $sort = is_numeric($sortRaw) ? (int) $sortRaw : 0;

        if (null !== $id) {
            CardboardServices::menu()->updateSortOrder($id, $sort);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.panel-nav.sort', 'settings', 'Reordered nav item ' . $id . ' to ' . $sort, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.settings'));
    }

    /**
     * Move a stored nav row under a top-level item, or back to the top level.
     *
     * The add form can create a child and nothing could MOVE one, so fixing a
     * wrong parent meant delete and re-add — losing the row's icon, order and
     * audit trail. The three refusals are the shapes the sidebar cannot draw:
     * itself, a parent that does not exist, a parent that is itself a child
     * (the renderer emits a group's children as links, so a nested group would
     * silently drop its own), and a group with children, which would push its
     * children a level below what the sidebar renders.
     */
    public function movePanelNav(Request $request): Response
    {
        $id = $request->routeParam('id');
        $parentId = trim($request->bodyString('parent_item_id'));

        if (null === $id) {
            return Response::redirect($this->url('mark.settings'));
        }

        $items = CardboardServices::panelNav()->allItems();

        /** @var array<string, array{parent_id: ?string}> $byId */
        $byId = [];

        foreach ($items as $item) {
            $byId[$item['id']] = $item;
        }

        /** @var array<string, ?string> $parentOf */
        $parentOf = [];
        foreach ($items as $item) {
            $parentOf[$item['id']] = $item['parent_id'] ?? null;
        }

        if (!isset($byId[$id])) {
            return Response::html($this->settingsPage(['That nav item no longer exists.']));
        }

        if ('' !== $parentId) {
            if ($parentId === $id) {
                return Response::html($this->settingsPage(['A nav item cannot be moved under itself.']));
            }

            $storedIds = array_column(CardboardServices::panelNav()->storedItems(), 'id');

            if (!in_array($parentId, $storedIds, true)) {
                return Response::html($this->settingsPage(isset($byId[$parentId])
                    ? ['That parent is a declaration, not a stored row — add a row for it first.']
                    : ['The chosen parent no longer exists.']));
            }

            // A cycle is the one shape no renderer survives: walking down from
            // the item must never reach the parent it is being moved under.
            // Everything else nests now — the sidebar draws a group inside a
            // group, so a group with children is a legal passenger.
            if (self::isInside($parentOf, $parentId, $id)) {
                return Response::html($this->settingsPage(
                    ['A nav item cannot be moved under its own child.'],
                ));
            }
        }

        CardboardServices::menu()->updateParent($id, '' !== $parentId ? $parentId : null);
        CacheInvalidator::flush();
        \Modules\Audit\Interfaces\HTTP\AuditServices::log(
            'settings.panel-nav.parent',
            'settings',
            'Moved nav item ' . $id . ' under ' . ('' !== $parentId ? $parentId : 'top level'),
            $request->getClientIp(),
        );

        return Response::redirect($this->url('mark.settings'));
    }

    public function deletePanelNav(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null !== $id) {
            CardboardServices::menu()->delete($id);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.panel-nav.delete', 'settings', 'Deleted nav item ' . $id, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.settings'));
    }

    public function reorderPanelNav(Request $request): Response
    {
        $pairs = $this->reorderPairs($request);

        if ([] !== $pairs) {
            CardboardServices::menu()->batchUpdateSortOrder($pairs);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.panel-nav.reorder', 'settings', 'Reordered nav (' . count($pairs) . ' items)', $request->getClientIp());
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

    public function updatePanelNavIcon(Request $request): Response
    {
        $id = $request->routeParam('id');
        $icon = $request->bodyString('icon');

        if (null !== $id && IconRegistry::isValid($icon)) {
            CardboardServices::menu()->updateIcon($id, $icon);
            CacheInvalidator::flush();
            \Modules\Audit\Interfaces\HTTP\AuditServices::log('settings.panel-nav.icon', 'settings', 'Updated icon for nav item ' . $id, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.settings'));
    }
}
