<?php

declare(strict_types = 1);

namespace Modules\Locales\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Locales\Application\DTOs\TranslationDTO;
use Modules\Locales\Domain\Model\Locale;
use Modules\Locales\Interfaces\HTTP\LocalesServices;
use Modules\Validation\Validation\Validator;

/**
 * Locales admin — translation CRUD at /mark/locales.
 *
 * Routes:
 * - GET  /mark/locales                list with locale switch, group filter, search
 * - GET  /mark/locales/create         create form
 * - POST /mark/locales/create         create submit
 * - GET  /mark/locales/{id}/edit      edit form
 * - POST /mark/locales/{id}/edit      edit submit
 * - POST /mark/locales/{id}/delete    delete
 * - POST /mark/locales/import         JSON import
 * - POST /mark/locales/export         JSON export (database rows → the locale file)
 *
 * All state changes are CSRF-protected by CsrfMiddleware and validated
 * before any write (invalid locale/key re-render with an error).
 */
#[Route(path: '/mark/locales', method: 'GET', name: 'mark.locales')]
#[Route(path: '/mark/locales/create', method: 'GET', name: 'mark.locales.create', handlerMethod: 'createForm')]
#[Route(path: '/mark/locales/create', method: 'POST', name: 'mark.locales.create.submit', handlerMethod: 'createSubmit')]
#[Route(path: '/mark/locales/{id}/edit', method: 'GET', name: 'mark.locales.edit', handlerMethod: 'editForm')]
#[Route(path: '/mark/locales/{id}/edit', method: 'POST', name: 'mark.locales.edit.submit', handlerMethod: 'editSubmit')]
#[Route(path: '/mark/locales/{id}/delete', method: 'POST', name: 'mark.locales.delete', handlerMethod: 'delete')]
#[Route(path: '/mark/locales/import', method: 'POST', name: 'mark.locales.import', handlerMethod: 'import')]
#[Route(path: '/mark/locales/export', method: 'POST', name: 'mark.locales.export', handlerMethod: 'export')]
final class LocalesAction
{
    use ResolvesRouteUrls;

    /**
     * Audit emission guarded by class_exists, never imported: translations
     * are manageable without the Audit module; with it installed every
     * write here is recorded. A use-statement would close an
     * install-order cycle (Locales -> Audit -> Users -> Locales).
     */
    private static function audit(string $action, string $entity, string $detail, string $ip): void
    {
        if (!class_exists("Modules\\Audit\\Interfaces\\HTTP\\AuditServices")) {
            return;
        }

        \Modules\Audit\Interfaces\HTTP\AuditServices::log($action, $entity, $detail, $ip);
    }

    public function handle(Request $request): Response
    {
        $service = LocalesServices::translations();

        $activeLocale = $this->activeLocale($request);
        $group = $this->stringParam($request->query('group', ''));
        $search = $this->stringParam($request->query('q', ''));

        // `list()` rather than a repository call: it merges the database rows
        // with the keys only the locale file carries, so a key that renders
        // before it has a row is visible (and editable) in the panel.
        $translations = $service->list($activeLocale, $group, $search);

        $imported = $this->importedCount($request);
        $exported = $this->exportedCount($request);

        $content = $this->listHtml($activeLocale, $group, $search, $translations, $imported, $exported);

        return Response::html(AdminLayout::render(
            'Translations',
            $content,
            'locales',
            [['label' => 'New translation', 'href' => $this->url('mark.locales.create')]],
        router: $this->router()));
    }

    public function createForm(Request $request): Response
    {
        return Response::html($this->form(
            'New translation',
            $this->url('mark.locales.create'),
            'Create translation',
            [],
        ));
    }

    public function createSubmit(Request $request): Response
    {
        $key = $this->stringParam($request->body('key', ''));
        $localeCode = $this->stringParam($request->body('locale', ''));
        $value = $this->stringParam($request->body('value', ''));
        $group = $this->stringParam($request->body('group', 'general'));

        $errors = $this->validate($key, $localeCode, $value);

        if ([] === $errors) {
            LocalesServices::translations()->set($key, $localeCode, $value, $group);
            CacheInvalidator::flush();
            self::audit('locales.create', 'translation:' . $key, 'Created translation ' . $key, $request->getClientIp());

            return Response::redirect($this->url('mark.locales') . '?locale=' . urlencode($localeCode));
        }

        return Response::html($this->form(
            'New translation',
            $this->url('mark.locales.create'),
            'Create translation',
            $errors,
            ['key' => $key, 'locale' => $localeCode, 'value' => $value, 'group' => $group],
        ));
    }

    public function editForm(Request $request): Response
    {
        $id = $request->routeParam('id');
        $translation = null === $id ? null : LocalesServices::translations()->findById($id);

        if (null === $translation) {
            return Response::redirect($this->url('mark.locales'));
        }

        return Response::html($this->form(
            'Edit translation',
            $this->url('mark.locales.edit', ['id' => $translation->id()]),
            'Save changes',
            [],
            [
                'key'    => $translation->key(),
                'locale' => $translation->locale()->code(),
                'value'  => $translation->value(),
                'group'  => $translation->group(),
            ],
            true,
        ));
    }

    public function editSubmit(Request $request): Response
    {
        $id = $request->routeParam('id');
        $service = LocalesServices::translations();
        $translation = null === $id ? null : $service->findById($id);

        if (null === $translation) {
            return Response::redirect($this->url('mark.locales'));
        }

        $value = $this->stringParam($request->body('value', ''));
        $group = $this->stringParam($request->body('group', 'general'));

        $valueErrors = $this->validateValue($value);

        if ([] !== $valueErrors) {
            return Response::html($this->form(
                'Edit translation',
                $this->url('mark.locales.edit', ['id' => $translation->id()]),
                'Save changes',
                $valueErrors,
                [
                    'key'    => $translation->key(),
                    'locale' => $translation->locale()->code(),
                    'value'  => $value,
                    'group'  => $group,
                ],
                true,
            ));
        }

        $translation->updateValue($value);
        $translation->updateGroup($group);
        $service->save($translation);
        CacheInvalidator::flush();
        self::audit('locales.update', 'translation:' . $translation->key(), 'Updated translation ' . $translation->key(), $request->getClientIp());

        return Response::redirect($this->url('mark.locales') . '?locale=' . urlencode($translation->locale()->code()));
    }

    public function delete(Request $request): Response
    {
        $id = $request->routeParam('id');
        $service = LocalesServices::translations();
        $translation = null === $id ? null : $service->findById($id);

        if (null !== $translation) {
            $locale = $translation->locale()->code();
            $service->delete($translation->key(), $locale);
            CacheInvalidator::flush();
            self::audit('locales.delete', 'translation:' . $translation->key(), 'Deleted translation ' . $translation->key(), $request->getClientIp());

            return Response::redirect($this->url('mark.locales') . '?locale=' . urlencode($locale));
        }

        return Response::redirect($this->url('mark.locales'));
    }

    /**
     * Export the locale's database rows to its JSON file — the other half of
     * the read chain, so an operator can seed the fallback source from the
     * panel instead of hand-writing it.
     *
     * No cache flush: exporting writes a file the fallback reads; no page in
     * the page cache renders differently because of it.
     */
    public function export(Request $request): Response
    {
        $candidate = $this->stringParam($request->body('locale', ''));
        $locale = LocalesServices::locale()->isAvailable($candidate) ? $candidate : Locale::default()->code();

        $exported = LocalesServices::translations()->export($locale);
        self::audit('locales.export', 'locale:' . $locale, 'Exported ' . $exported . ' translations', $request->getClientIp());

        return Response::redirect($this->url('mark.locales') . '?locale=' . urlencode($locale) . '&exported=' . $exported);
    }

    /**
     * Import translations from an uploaded JSON file (flat or grouped format).
     */
    public function import(Request $request): Response
    {
        $file = $_FILES['translation_file'] ?? null;

        if (!is_array($file) || UPLOAD_ERR_OK !== ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            return Response::html(AdminLayout::render(
                'Translations',
                $this->messageBox('Import failed — no valid file uploaded.'),
                'locales',
            router: $this->router()));
        }

        $tmpPath = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $contents = '' !== $tmpPath && is_file($tmpPath) ? file_get_contents($tmpPath) : false;

        if (false === $contents) {
            return Response::html(AdminLayout::render(
                'Translations',
                $this->messageBox('Import failed — could not read the uploaded file.'),
                'locales',
            router: $this->router()));
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            return Response::html(AdminLayout::render(
                'Translations',
                $this->messageBox('Import failed — the file is not valid JSON.'),
                'locales',
            router: $this->router()));
        }

        [$locale, $entries] = $this->parseImport($data);

        if (!Locale::fromString($locale)->isAvailable()) {
            return Response::html(AdminLayout::render(
                'Translations',
                $this->messageBox('Import failed — unknown locale "' . AdminLayout::e($locale) . '".'),
                'locales',
            router: $this->router()));
        }

        $imported = $this->importEntries($locale, $entries);
        CacheInvalidator::flush();
        self::audit('locales.import', 'locale:' . $locale, 'Imported ' . $imported . ' translations', $request->getClientIp());

        return Response::redirect($this->url('mark.locales') . '?locale=' . urlencode($locale) . '&imported=' . $imported);
    }

    /**
     * @param list<string>          $errors
     * @param array<string, string> $values
     */
    private function form(
        string $title,
        string $action,
        string $submitLabel,
        array $errors,
        array $values = [],
        bool $editing = false,
    ): string {
        $errorHtml = AdminLayout::messages($errors, 'err');
        $csrf = AdminLayout::csrfField();

        $key = AdminLayout::e($values['key'] ?? '');
        $value = AdminLayout::e($values['value'] ?? '');
        $group = AdminLayout::e($values['group'] ?? 'general');
        $localeLabel = AdminLayout::e(Locale::fromString($values['locale'] ?? 'sk_SK')->name());

        // Key and locale are immutable on edit — shown as read-only info so
        // an admin can never change them silently (they are not submitted).
        $keyField = $editing
            ? '<p class="m-mono cba-field-hint">' . $key . '</p>'
            : '<input type="text" id="key" name="key" value="' . $key . '" required>';
        $localeField = $editing
            ? '<p class="m-mono cba-field-hint">' . $localeLabel . '</p>'
            : '<select id="locale" name="locale">' . $this->localeOptions($values['locale'] ?? '') . '</select>';

        $content = ViewRenderer::render('Modules/Locales/Interfaces/HTTP/templates/locales-list.phtml', ['url' => $this->urlBuilder(), 'action' => $action, 'csrf' => $csrf, 'errorHtml' => $errorHtml, 'group' => $group, 'keyField' => $keyField, 'localeField' => $localeField, 'submitLabel' => $submitLabel, 'value' => $value]);

        return AdminLayout::render($title, $content, 'locales', router: $this->router());
    }

    /**
     * @param list<TranslationDTO> $translations
     */
    private function listHtml(
        string $activeLocale,
        string $group,
        string $search,
        array $translations,
        int $imported,
        ?int $exported = null,
    ): string {
        $rows = '';
        $csrf = AdminLayout::csrfField();

        foreach ($translations as $translation) {
            $id = AdminLayout::e($translation->id);
            $key = AdminLayout::e($translation->key);
            $value = AdminLayout::e($translation->value);
            $rowGroup = AdminLayout::e($translation->group);
            $iconEdit = AdminLayout::iconEdit();
            $iconDelete = AdminLayout::iconDelete();
            $rows .= ViewRenderer::render('Modules/Locales/Interfaces/HTTP/templates/locales-edit.phtml', ['url' => $this->urlBuilder(), 'csrf' => $csrf, 'iconDelete' => $iconDelete, 'iconEdit' => $iconEdit, 'id' => $id, 'key' => $key, 'rowGroup' => $rowGroup, 'value' => $value, 'source' => $translation->source]);
        }

        $table = '' === $rows
            ? '<p class="m-muted">No translations match.</p>'
            : '<table class="m-table"><thead><tr><th>Key</th><th>Value</th><th>Group</th><th></th></tr></thead><tbody>'
                . $rows . '</tbody></table>';

        return $this->filterBar($activeLocale, $group, $search, $imported, $exported)
            . $table
            . $this->importForm()
            . $this->exportForm($activeLocale);
    }

    /**
     * Locale switcher + group filter + search bar, with the import/export
     * notices above it.
     */
    private function filterBar(string $activeLocale, string $group, string $search, int $imported, ?int $exported = null): string
    {
        $localeTabs = '';

        foreach (Locale::available() as $code => $locale) {
            $active = $code === $activeLocale ? ' layout-sidebar__link--active' : '';
            $label = AdminLayout::e($locale->name());
            // Inactive tabs inherit the page bg (transparent nav) — use muted
            // ink (≥ 4.5:1 in both themes) instead of the nav-white `inherit`.
            $style = '' === $active ? ' m-pill--muted' : '';
            $localeTabs .= '<a class="m-pill' . $active . $style . '" href="' . AdminLayout::e($this->url('mark.locales') . '?locale=' . $code) . '">' . $label . '</a>';
        }

        $groupValue = AdminLayout::e($group);
        $searchValue = AdminLayout::e($search);
        $importNotice = $imported > 0
            ? '<div class="m-alert m-alert-success">Import completed: ' . $imported . ' translations.</div>'
            : '';
        $exportNotice = match (true) {
            null === $exported => '',
            0 === $exported => '<div class="m-alert m-alert-warning">Nothing to export — this locale has no database rows yet.</div>',
            default => '<div class="m-alert m-alert-success">Exported ' . $exported . ' translations to <code>storage/locales/' . AdminLayout::e($activeLocale) . '.json</code>.</div>',
        };

        return $importNotice
            . $exportNotice
            . '<div class="m-filter-row">'
            . '<div class="m-filter-bar">' . $localeTabs . '</div>'
            . '<form method="GET" action="' . AdminLayout::e($this->url('mark.locales')) . '" class="m-filter-form">'
            . '<input type="hidden" name="locale" value="' . AdminLayout::e($activeLocale) . '">'
            . '<input type="text" name="group" class="m-input" placeholder="group (nav, home…)" value="' . $groupValue . '">'
            . '<input type="text" name="q" class="m-input" placeholder="Search…" value="' . $searchValue . '">'
            . '<button class="m-btn m-btn-sm" type="submit">Filter</button>'
            . '</form></div>';
    }

    /**
     * JSON export form — writes the locale's rows to `storage/locales/<locale>.json`,
     * the file the read chain falls back to for a key with no row.
     */
    private function exportForm(string $locale): string
    {
        $csrf = AdminLayout::csrfField();

        return '<div class="m-card">'
            . '<h2 class="m-heading">Export JSON</h2>'
            . '<p class="m-muted m-hint">Writes this locale\'s database rows to <code>storage/locales/' . AdminLayout::e($locale) . '.json</code>. That file is the fallback for a key the database does not carry — a fresh install, a restored database, or a key shipped before its migration — so a missing row renders words instead of the raw key.</p>'
            . '<form class="m-form" method="POST" action="' . AdminLayout::e($this->url('mark.locales.export')) . '">'
            . $csrf
            . '<input type="hidden" name="locale" value="' . AdminLayout::e($locale) . '">'
            . '<div><button class="m-btn" type="submit">Export to JSON</button></div>'
            . '</form></div>';
    }

    /**
     * JSON import upload form.
     */
    private function importForm(): string
    {
        $csrf = AdminLayout::csrfField();

        return '<div class="m-card">'
            . '<h2 class="m-heading">Import JSON</h2>'
            . '<form class="m-form" method="POST" action="' . AdminLayout::e($this->url('mark.locales.import')) . '" enctype="multipart/form-data">'
            . $csrf
            . '<input type="file" name="translation_file" accept="application/json,.json" required>'
            . '<p class="m-muted m-hint">Flat {"nav.home": "Home"} or grouped {"locale": "en_US", "translations": {...}}</p>'
            . '<div><button class="m-btn m-btn-primary" type="submit">Import</button></div>'
            . '</form></div>';
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{0: string, 1: array<string, mixed>} [locale, entries]
     */
    private function parseImport(array $data): array
    {
        if (isset($data['locale']) && is_string($data['locale']) && isset($data['translations']) && is_array($data['translations'])) {
            /** @var array<string, mixed> $entries */
            $entries = $data['translations'];

            return [$data['locale'], $entries];
        }

        /** @var array<string, mixed> $flat */
        $flat = $data;

        return ['en_US', $flat];
    }

    /**
     * @param array<string, mixed> $entries
     */
    private function importEntries(string $locale, array $entries): int
    {
        $service = LocalesServices::translations();
        $imported = 0;

        foreach ($entries as $key => $value) {
            if ('' === $key) {
                continue;
            }

            $valueStr = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
            $group = str_contains($key, '.') ? explode('.', $key, 2)[0] : 'general';

            try {
                $service->set($key, $locale, $valueStr, $group);
                $imported++;
            } catch (\InvalidArgumentException) {
                // Skip invalid keys/locales silently — counted via redirect total.
                // @mago-expect lint:no-empty-catch-clause
            }
        }

        return $imported;
    }

    /**
     * @return list<string>
     */
    private function validate(string $key, string $localeCode, string $value): array
    {
        // Raw, untrimmed inputs reach this method, so `required` also rejects
        // whitespace-only keys/values the old `=== ''` checks stored — the
        // same deliberate tightening as UsersAction. Availability is not a
        // validation rule (it reads the locale catalogue), so it keeps its
        // position after the locale-format errors, like AuthService's
        // email-taken check.
        $failures = (new Validator())->validate(
            ['key' => $key, 'locale' => $localeCode, 'value' => $value],
            [
                'key'    => 'required|regex:/^[a-zA-Z0-9._-]+$/',
                'locale' => 'required',
                'value'  => 'required',
            ],
            [
                'key.required'    => 'Translation key is required.',
                'key.regex'       => 'Key must match [a-zA-Z0-9._-]+ (e.g. nav.home).',
                'locale.required' => 'Locale is required.',
                'value.required'  => 'Value is required.',
            ],
        );

        $unavailable = [] === $failures->forField('locale') && !LocalesServices::locale()->isAvailable($localeCode)
            ? ['The selected locale is not available.']
            : [];

        return array_merge(
            $failures->forField('key'),
            $failures->forField('locale'),
            $unavailable,
            $failures->forField('value'),
        );
    }

    /**
     * Edit only touches the value (key/locale are immutable), so it gets
     * its own single-field check with the same legacy string.
     *
     * @return list<string>
     */
    private function validateValue(string $value): array
    {
        // Raw input, so `required` also rejects a whitespace-only value
        // the old `=== ''` check stored — the same deliberate tightening
        // as validate().
        return (new Validator())->validate(
            ['value' => $value],
            ['value' => 'required'],
            ['value.required' => 'Value is required.'],
        )->all();
    }

    private function stringParam(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    private function activeLocale(Request $request): string
    {
        $candidate = $this->stringParam($request->query('locale', ''));

        return LocalesServices::locale()->isAvailable($candidate) ? $candidate : Locale::default()->code();
    }

    private function importedCount(Request $request): int
    {
        $search = $this->stringParam($request->query('q', ''));
        $imported = $request->query('imported');

        return '' === $search && is_numeric($imported) ? (int) $imported : 0;
    }

    /**
     * The export notice's number, or null when this request is not an export
     * response — which is what separates "exported nothing" from "just
     * listing".
     */
    private function exportedCount(Request $request): ?int
    {
        $exported = $request->query('exported');

        return is_numeric($exported) ? (int) $exported : null;
    }

    private function messageBox(string $message): string
    {
        return '<div class="cba-msg cba-msg--err">' . AdminLayout::e($message) . '</div>'
            . '<p><a class="m-btn m-btn-ghost" href="' . AdminLayout::e($this->url('mark.locales')) . '">Back to translations</a></p>';
    }

    private function localeOptions(string $selected): string
    {
        $options = '';

        foreach (Locale::available() as $code => $locale) {
            $isSelected = $code === $selected ? ' selected' : '';
            $options .= "<option value=\"{$code}\"{$isSelected}>" . AdminLayout::e($locale->name()) . '</option>';
        }

        return $options;
    }
}
