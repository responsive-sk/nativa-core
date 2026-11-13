<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP\Actions;

use Modules\Cardboard\Application\Services\ModuleTreeState;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Tooling\ModuleCatalog;
use Modules\Tooling\ModuleInstaller;
use Modules\Tooling\ModuleRegistry;

/**
 * ModulesAction — the module list, and the three lifecycle steps the panel may
 * take on it: install, uninstall and reinstall.
 *
 * Exists because `src/Modules/<Name>` is gitignored operator state. The
 * catalogue (`modules/`) is committed; the slice (`src/Modules/`) is a copy
 * `module:add` makes. Nothing in git compares them, and `module:show` asks
 * `isInstalled()` — whether a slice exists, not whether it is the right one — so
 * a tree can run code that was deleted three commits ago while every gate is
 * green. Measured 2026-10-17: Cardboard's installed slice still held the
 * permission table that had been deleted from the catalogue.
 *
 * ## Why every lifecycle step is a POST here and not a link
 *
 * `ModuleInstaller::add()` refuses to overwrite an installed slice
 * (`cms: Module 'X' is already installed — refusing to overwrite the installed
 * slice`), so the only way to close drift is to remove and re-add, which
 * discards operator edits made in `src/Modules/`. An uninstall deletes the
 * slice the running site boots from. Both are destructive and irreversible, so
 * both are POSTs behind the admin's CSRF token rather than links a stray click
 * can follow — and each button only appears for a module in the state it
 * applies to, so the ordinary page has nothing to confirm.
 *
 * ## What the panel deliberately does NOT do
 *
 * `uninstall` calls `ModuleInstaller::remove($name)` WITHOUT `--force`. A
 * forced removal leaves the modules that import the slice with a class they can
 * no longer load, which is a broken site rather than a lifecycle step; the
 * refusal names the holders, and the operator removes those first. `--force`
 * stays a CLI decision.
 *
 * ## Where the result goes
 *
 * Each step redirects back to the list with `?ok=` or `?error=`, and the page
 * renders both through `AdminLayout::messages()`. The installer's own message is
 * the one shown (incomplete closure, still imported, already installed), because
 * a second wording for the same refusal is exactly the copy this tree counts as
 * a defect.
 *
 * The action re-reads the tree after every step rather than trusting that the
 * copy landed: the card and this page both read `ModuleTreeState`, and a green
 * row here is the same green the card counts.
 */
// The attributes sit on the CLASS, not on `handle()`.
//
// That is not a style choice: `Router::discover()` reads
// `$reflection->getAttributes(Route::class)` — the class's own attributes, one
// level up — and the method's attributes are never looked at. Written on
// `handle()` they compile, they import cleanly, `phpstan` and `cs-fixer` pass,
// and the route simply does not exist: `/mark/settings/modules` answered 404 with
// eight CI jobs green. Every other action in this directory puts them on the
// class, which is the tell.
#[Route(path: '/mark/settings/modules', method: 'GET', name: 'mark.settings.modules')]
#[Route(
    path: '/mark/settings/modules/{name}/install',
    method: 'POST',
    name: 'mark.settings.modules.install',
    handlerMethod: 'install',
)]
#[Route(
    path: '/mark/settings/modules/{name}/install-with-dependencies',
    method: 'POST',
    name: 'mark.settings.modules.installDependencies',
    handlerMethod: 'installWithDependencies',
)]
#[Route(
    path: '/mark/settings/modules/{name}/install-from-registry',
    method: 'POST',
    name: 'mark.settings.modules.installFromRegistry',
    handlerMethod: 'installFromRegistry',
)]
#[Route(
    path: '/mark/settings/modules/{name}/uninstall',
    method: 'POST',
    name: 'mark.settings.modules.uninstall',
    handlerMethod: 'uninstall',
)]
#[Route(
    path: '/mark/settings/modules/{name}/reinstall',
    method: 'POST',
    name: 'mark.settings.modules.reinstall',
    handlerMethod: 'reinstall',
)]
final class ModulesAction
{
    use ResolvesRouteUrls;

    /**
     * The list: catalogued vs installed, drift, and the button each row earns.
     */
    public function handle(Request $request): Response
    {
        return Response::html($this->page(
            AdminLayout::csrfField(),
            $request->queryString('error'),
            $request->queryString('ok'),
        ));
    }

    /**
     * Copy a catalogued, absent module into `src/Modules/`. The closure rule is
     * the installer's: a module whose dependencies are missing is refused before
     * anything is copied, and the message says which.
     */
    public function install(Request $request): Response
    {
        $name = $this->cataloguedName($request);

        if (null === $name) {
            return $this->back($request, error: 'That is not a catalogued module name.');
        }

        try {
            $result = ModuleInstaller::fromAppPaths()->add($name);
        } catch (\Throwable $e) {
            return $this->back($request, error: $name . ': ' . $e->getMessage());
        }

        $this->audit('module.install', $name, sprintf(
            'Installed %s: %d file(s) copied, %d cached page(s) flushed.',
            $name,
            $result['copied'],
            $result['flushed'],
        ), $request);

        return $this->back($request, ok: sprintf('Installed %s: %d file(s) copied.', $name, $result['copied']));
    }

    /**
     * Copy the requested module and every catalogued dependency it needs, in
     * closure order. The installer preflights the whole plan before copying, so
     * a cycle or a non-catalogued dependency leaves the tree untouched.
     */
    public function installWithDependencies(Request $request): Response
    {
        $name = $this->cataloguedName($request);

        if (null === $name) {
            return $this->back($request, error: 'That is not a catalogued module name.');
        }

        try {
            $result = ModuleInstaller::fromAppPaths()->addWithDependencies($name);
        } catch (\Throwable $e) {
            return $this->back($request, error: $name . ': ' . $e->getMessage());
        }

        $installed = implode(', ', $result['installed']);

        $this->audit('module.install', $name, sprintf(
            'Installed %s with dependencies: %s (%d file(s) copied, %d cached page(s) flushed).',
            $name,
            $installed,
            $result['copied'],
            $result['flushed'],
        ), $request);

        return $this->back($request, ok: sprintf(
            'Installed %s with dependencies: %s (%d file(s) copied).',
            $name,
            $installed,
            $result['copied'],
        ));
    }

    /**
     * Install a module that is not in the local catalogue from the configured
     * registry. The registry stages and verifies the whole closure before it
     * copies anything, then hands the local catalogue to the same installer.
     */
    public function installFromRegistry(Request $request): Response
    {
        $name = (string) $request->routeParam('name', '');

        if (!ModuleCatalog::isValidName($name)) {
            return $this->back($request, error: 'That is not a valid module name.');
        }

        if (in_array($name, ModuleCatalog::fromAppPaths()->catalogued(), true)) {
            return $this->back($request, error: $name . ': already in the local catalogue — use Install.');
        }

        $registry = ModuleRegistry::fromEnv();

        if (null === $registry) {
            return $this->back($request, error: 'No module registry is configured (MODULE_REGISTRY_URL is empty).');
        }

        try {
            $result = $registry->install($name);
        } catch (\Throwable $e) {
            return $this->back($request, error: $name . ': ' . $e->getMessage());
        }

        $installed = implode(', ', $result['installed']);

        $this->audit('module.install', $name, sprintf(
            'Installed %s from the registry with dependencies: %s (%d file(s) copied, %d cached page(s) flushed).',
            $name,
            $installed,
            $result['copied'],
            $result['flushed'],
        ), $request);

        return $this->back($request, ok: sprintf(
            'Installed %s from the registry with dependencies: %s (%d file(s) copied).',
            $name,
            $installed,
            $result['copied'],
        ));
    }

    /**
     * Delete the slice. Module data is never touched — a database, uploads and
     * exports stay where they are, and the page reports what was left behind.
     */
    public function uninstall(Request $request): Response
    {
        $name = $this->cataloguedName($request);

        if (null === $name) {
            return $this->back($request, error: 'That is not a catalogued module name.');
        }

        try {
            $result = ModuleInstaller::fromAppPaths()->remove($name);
        } catch (\Throwable $e) {
            return $this->back($request, error: $name . ': ' . $e->getMessage());
        }

        $this->audit('module.uninstall', $name, sprintf(
            'Uninstalled %s: %d cached page(s) flushed, %d leftovers kept.',
            $name,
            $result['flushed'],
            count($result['leftovers']),
        ), $request);

        return $this->back($request, ok: [] === $result['leftovers']
            ? sprintf('Uninstalled %s. No module data was left on disk.', $name)
            : sprintf('Uninstalled %s. Kept: %s', $name, implode(', ', $result['leftovers'])));
    }

    /**
     * Remove and re-add, closing drift at the cost of operator edits inside the
     * slice. The installer's overwrite refusal is the reason this is one step.
     */
    public function reinstall(Request $request): Response
    {
        $name = $this->cataloguedName($request);

        if (null === $name) {
            return $this->back($request, error: 'That is not a catalogued module name.');
        }

        try {
            $catalog = ModuleCatalog::fromAppPaths();

            if ($catalog->isInstalled($name)) {
                // The refusal the installer issues is the point, not an obstacle:
                // a slice exists, and overwriting it would drop operator edits.
                ModuleInstaller::fromAppPaths()->remove($name, true);
            }

            $result = ModuleInstaller::fromAppPaths()->add($name);
        } catch (\Throwable $e) {
            return $this->back($request, error: $name . ': ' . $e->getMessage());
        }

        $this->audit('module.reinstall', $name, sprintf(
            'Reinstalled %s: %d file(s) copied.',
            $name,
            $result['copied'],
        ), $request);

        return $this->back($request, ok: sprintf('Reinstalled %s: %d file(s) copied.', $name, $result['copied']));
    }

    /**
     * The route's module name, or `null` when it is not a catalogued module.
     *
     * `isValidName()` runs before any path is built from it, so a traversal in
     * the path segment is a refusal rather than a directory operation, and the
     * catalogue check keeps the installer from being asked about a name that is
     * not part of this tree at all.
     */
    private function cataloguedName(Request $request): ?string
    {
        $name = (string) $request->routeParam('name', '');

        if (!ModuleCatalog::isValidName($name)) {
            return null;
        }

        return in_array($name, ModuleCatalog::fromAppPaths()->catalogued(), true) ? $name : null;
    }

    /**
     * Where a step's outcome goes: the panel itself for an htmx request, a
     * redirect back to the list for a plain form post.
     *
     * The partial is the SAME `panel()` the full page renders, so a swapped row
     * cannot disagree with a reloaded one, and the swapped buttons keep their
     * `hx-post` attributes because the markup is built by the same `row()`.
     */
    private function back(Request $request, ?string $error = null, ?string $ok = null): Response
    {
        if (AdminLayout::isHtmx($request)) {
            return Response::html($this->panel(new ModuleTreeState(), AdminLayout::csrfField(), (string) $error, (string) $ok));
        }

        $query = [];

        if (null !== $error && '' !== $error) {
            $query['error'] = $error;
        }

        if (null !== $ok && '' !== $ok) {
            $query['ok'] = $ok;
        }

        $url = $this->url('mark.settings.modules');

        return Response::redirect([] === $query ? $url : $url . '?' . http_build_query($query));
    }

    /**
     * A lifecycle step is an admin write, so it lands in the audit log beside
     * the content edits. `AuditServices` is addressed FQ-inline (the pattern the
     * other Cardboard writes use) because the Audit module is optional: a tree
     * without it must still install and remove modules.
     */
    private function audit(string $action, string $target, string $detail, Request $request): void
    {
        if (!class_exists(\Modules\Audit\Interfaces\HTTP\AuditServices::class)) {
            return;
        }

        \Modules\Audit\Interfaces\HTTP\AuditServices::log($action, $target, $detail, $request->getClientIp());
    }

    private function page(string $csrf, string $error = '', string $ok = ''): string
    {
        // One read of the tree per request. The page needs the counts for its
        // intro and the rows for its table, and building a second
        // `ModuleTreeState` for the second half hashed every module twice
        // (measured 2026-11-07: 42 ms per pass, so 84 ms per view).
        $state = new ModuleTreeState();

        $content = ViewRenderer::render(
            'Modules/Cardboard/Interfaces/HTTP/templates/modules-list.phtml',
            [
                // The same bar the settings screen renders, with this page's
                // entry active — the two screens are one navigation.
                'settingsTabs' => AdminLayout::settingsTabs($this->urlBuilder(), 'modules'),
                'intro' => AdminLayout::e($this->intro($state->counts())),
                'panel' => $this->panel($state, $csrf, $error, $ok),
            ],
        );

        return AdminLayout::render('Modules', $content, 'settings', router: $this->router());
    }

    /**
     * The swappable half of the page: messages, the table and the reinstall
     * note. `#modules-panel` is the htmx target, so a step's result replaces
     * exactly this and never the tab bar or the intro.
     */
    private function panel(ModuleTreeState $state, string $csrf, string $error = '', string $ok = ''): string
    {
        $rows = '';
        $fixable = [];

        $catalog = ModuleCatalog::fromAppPaths();

        foreach ($state->all() as $module) {
            $missing = [];

            if ('not-installed' === $module['state']) {
                try {
                    $missing = $catalog->missingDependencies($catalog->manifest($module['name']));
                } catch (\RuntimeException) {
                    // The row still renders; Install reports the real refusal.
                    $missing = [];
                }
            }

            $rows .= $this->row($module, $csrf, $missing);

            if ('drifted' === $module['state']) {
                $fixable[] = $module['name'];
            }
        }

        $messages = AdminLayout::messages([] === $ok ? [] : [$ok])
            . AdminLayout::messages([] === $error ? [] : [$error], 'err');

        return '<div id="modules-panel">' . $messages
            . '<div class="m-table-scroll"><table class="m-table m-table--wide" id="modules-table">'
            . '<thead><tr><th>Module</th><th>State</th><th>Drifted files</th><th></th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . ([] === $fixable
                ? ''
                : '<div class="m-table-toolbar"><p class="m-muted">'
                    . AdminLayout::e('Reinstall replaces the slice with the catalogue and drops any edit made inside it: ' . implode(', ', $fixable))
                    . '</p></div>')
            . $this->registryPanel($csrf)
            . '</div>';
    }

    /**
     * @param array{total: int, identical: int, drifted: int, missing: int} $counts
     */
    private function intro(array $counts): string
    {
        return sprintf(
            '%d catalogued · %d match the installed slice · %d drifted · %d not installed.'
            . ' The slice is copied from the catalogue by module:add and is not tracked by git,'
            . ' so this page is the only place the two are compared.',
            $counts['total'],
            $counts['identical'],
            $counts['drifted'],
            $counts['missing'],
        );
    }

    /**
     * @param array{name: string, installed: bool, state: 'identical'|'drifted'|'not-installed', drifted: list<string>} $module
     * @param list<string>                                                                                                      $missingDependencies
     */
    private function row(array $module, string $csrf, array $missingDependencies = []): string
    {
        [$badge, $label] = match ($module['state']) {
            'identical' => ['success', 'in sync'],
            'drifted' => ['warning', 'drift'],
            default => ['gray', 'not installed'],
        };

        $drifted = [] === $module['drifted']
            ? '<span class="m-muted">—</span>'
            : '<code>' . AdminLayout::e(implode('</code>, <code>', $module['drifted'])) . '</code>';

        $action = 'not-installed' === $module['state']
            ? $this->postButton('mark.settings.modules.install', $module['name'], $csrf, 'Install')
            : $this->postButton('mark.settings.modules.uninstall', $module['name'], $csrf, 'Uninstall');

        if ('not-installed' === $module['state'] && [] !== $missingDependencies) {
            // One meaningful action for an incomplete closure: plain Install
            // would only repeat the refusal this button resolves.
            $action = $this->postButton(
                'mark.settings.modules.installDependencies',
                $module['name'],
                $csrf,
                'Install with dependencies',
            );
        }

        if ('drifted' === $module['state']) {
            $action = $this->postButton('mark.settings.modules.reinstall', $module['name'], $csrf, 'Reinstall')
                . ' ' . $action;
        }

        return '<tr><td>' . AdminLayout::e($module['name']) . '</td>'
            . '<td><span class="m-badge m-badge--' . $badge . '">' . $label . '</span></td>'
            . '<td>' . $drifted . '</td>'
            . '<td>' . $action . '</td></tr>';
    }

    /**
     * The opt-in half of the page. An empty MODULE_REGISTRY_URL removes the
     * section entirely; a registry that cannot be reached is a message inside
     * the panel, not a 500 on the whole Modules screen.
     */
    private function registryPanel(string $csrf): string
    {
        $registry = ModuleRegistry::fromEnv();

        if (null === $registry) {
            return '';
        }

        try {
            $available = $registry->available();
        } catch (\Throwable $e) {
            return '<div class="m-table-toolbar"><p class="m-muted">'
                . AdminLayout::e('Registry unavailable: ' . $e->getMessage())
                . '</p></div>';
        }

        if ([] === $available) {
            return '';
        }

        $rows = '';

        foreach ($available as $name => $entry) {
            $requires = [] === $entry['requires']
                ? '<span class="m-muted">—</span>'
                : AdminLayout::e(implode(', ', $entry['requires']));

            $rows .= '<tr><td>' . AdminLayout::e($name) . '</td>'
                . '<td>' . $requires . '</td>'
                . '<td>' . $this->postButton('mark.settings.modules.installFromRegistry', $name, $csrf, 'Install from registry') . '</td></tr>';
        }

        return '<h2 class="m-heading">Registry</h2>'
            . '<div class="m-table-toolbar"><p class="m-muted">'
            . AdminLayout::e('Modules available from the configured registry, not in the local catalogue.')
            . '</p></div>'
            . '<div class="m-table-scroll"><table class="m-table m-table--wide" id="registry-table">'
            . '<thead><tr><th>Module</th><th>Requires</th><th></th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }

    /**
     * A lifecycle step as a form that works with and without htmx: the `hx-*`
     * attributes swap the panel in place, and a browser without them still
     * submits the same POST to the same route and lands on the redirect.
     */
    private function postButton(string $route, string $name, string $csrf, string $label): string
    {
        $url = AdminLayout::e($this->url($route, ['name' => $name]));

        return '<form method="post" action="' . $url . '" style="display:inline"'
            . ' hx-post="' . $url . '" hx-target="#modules-panel" hx-swap="innerHTML"'
            . ' hx-disabled-elt="find button">' . $csrf
            . '<button type="submit" class="m-btn m-btn-sm">' . $label . '</button></form>';
    }
}
