<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Application\Services;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Interfaces\HTTP\Middleware\CsrfMiddleware;

/**
 * BulkActions — reusable bulk-action UI helpers for admin tables.
 *
 * Renders the checkbox column, select-all header, and the floating
 * bulk-action bar that appears when items are selected.
 */
final class BulkActions
{
    /**
     * Publish/Draft switch — the row's state control AND indicator in one.
     *
     * Shared by the Articles and Pages admin tables. The track is a submit
     * button inside a POST form to the entity's publish-toggle endpoint: a
     * click submits natively without JS, with HTMX the row is swapped in
     * place. The state changes only after the server round-trip, so the UI
     * can never diverge from the stored front matter. The `to` hidden field
     * carries the NEW state (opposite of the rendered one). While the HTMX
     * request runs the track dims and the thumb pulses (mark-switch.css).
     *
     * Labels/tooltips arrive translated — the callers (Interfaces layer)
     * resolve them through the Locales t() helper with mark.* keys and EN
     * defaults; this Application-layer service stays locale-agnostic.
     *
     * The labels are user-editable locale data (editable in /mark/locales),
     * so they are HTML-escaped here at the render boundary (AdminLayout::e(),
     * ENT_QUOTES) before reaching attributes or element content — the same
     * convention the callers' $slug/$locale already follow.
     *
     * @param string $publishAction The entity's publish-toggle URL, built by
     *                              the caller through the Router (task 2.9)
     * @param array{draft: string, published: string, tooltip_publish: string, tooltip_unpublish: string} $labels
     */
    public static function markSwitch(string $publishAction, string $locale, string $csrf, bool $published, array $labels): string
    {
        $labels = self::escapeLabels($labels);
        $action = AdminLayout::e($publishAction . '?locale=' . $locale);
        $state = $published ? 'on' : 'off';
        $tooltip = $published ? $labels['tooltip_unpublish'] : $labels['tooltip_publish'];
        $aria = $published
            ? $labels['published'] . ' — ' . $labels['tooltip_unpublish']
            : $labels['draft'] . ' — ' . $labels['tooltip_publish'];
        $to = $published ? '0' : '1';

        return '<form method="POST" action="' . $action . '"'
            . ' hx-post="' . $action . '"'
            . ' hx-target="closest tr"'
            . ' hx-swap="outerHTML"'
            . ' class="m-pubswitch m-pubswitch--' . $state . '">'
            . $csrf
            . '<input type="hidden" name="to" value="' . $to . '">'
            . '<button type="submit" class="m-pubswitch__track" data-tooltip="' . $tooltip . '" aria-label="' . $aria . '">'
            . '<span class="m-pubswitch__labels" aria-hidden="true">'
            . '<span class="m-pubswitch__label">' . ($published ? $labels['published'] : $labels['draft']) . '</span>'
            . '</span>'
            . '<span class="m-pubswitch__thumb" aria-hidden="true"></span>'
            . '</button>'
            . '</form>';
    }

    /**
     * HTML-escape the translated switch labels at the render boundary —
     * they are user-editable locale data and land in attributes and
     * element content.
     *
     * @param array{draft: string, published: string, tooltip_publish: string, tooltip_unpublish: string} $labels
     *
     * @return array{draft: string, published: string, tooltip_publish: string, tooltip_unpublish: string}
     */
    private static function escapeLabels(array $labels): array
    {
        return [
            'draft'             => AdminLayout::e($labels['draft']),
            'published'         => AdminLayout::e($labels['published']),
            'tooltip_publish'   => AdminLayout::e($labels['tooltip_publish']),
            'tooltip_unpublish' => AdminLayout::e($labels['tooltip_unpublish']),
        ];
    }

    /**
     * Render a single-row checkbox cell.
     */
    public static function checkboxCell(string $id): string
    {
        $escaped = AdminLayout::e($id);

        return '<td class="m-bulk__cell">'
            . '<input type="checkbox" name="ids[]" value="' . $escaped . '"'
            . ' class="m-bulk__check">'
            . '</td>';
    }

    /**
     * Render the select-all checkbox in the table header.
     */
    public static function headerCell(): string
    {
        return '<th class="m-bulk__cell">'
            . '<input type="checkbox" class="m-bulk__check"'
            . ' aria-label="Select all">'
            . '</th>';
    }

    /**
     * Render the floating bulk-action bar.
     *
     * @param string $entity Entity type (e.g. "users", "roles", "pages")
     * @param string $action Form action URL
     */
    public static function actionBar(string $entity, string $action, string $locale = ''): string
    {
        $escapedAction = AdminLayout::e($action);
        $escapedEntity = AdminLayout::e($entity);
        $token = CsrfMiddleware::token();
        $localeInput = '' !== $locale
            ? '<input type="hidden" name="locale" value="' . AdminLayout::e($locale) . '">'
            : '';

        return '<div class="m-bulk-bar" id="bulk-bar">'
            . '<span class="m-bulk-bar__count" id="bulk-count">0 selected</span>'
            // Plain POST — row checkboxes live outside this form; the ids[]
            // are injected at submit time (AdminLayout mBulkInject) and the
            // server redirects back to the index with a toast message.
            . '<form method="POST" action="' . $escapedAction . '"'
            . ' id="bulk-form">'
            . '<input type="hidden" name="_token" value="' . AdminLayout::e($token) . '">'
            . '<input type="hidden" name="entity" value="' . $escapedEntity . '">'
            . $localeInput
            . '<button type="submit" class="m-btn m-btn-error m-btn-sm"'
            . ' data-confirm="Delete selected items?">'
            . 'Delete selected'
            . '</button>'
            . '</form>'
            . '</div>';
    }

    /**
     * Render the floating bulk-action bar with publish/unpublish + delete.
     *
     * Used by Articles and Pages tables.
     *
     * @param string $entity        Entity type (e.g. "articles", "pages")
     * @param string $publishAction URL for bulk publish endpoint
     * @param string $deleteAction  URL for bulk delete endpoint
     */
    public static function actionBarWithPublish(string $entity, string $publishAction, string $deleteAction, string $locale = ''): string
    {
        $escapedEntity = AdminLayout::e($entity);
        $escapedPublish = AdminLayout::e($publishAction);
        $escapedDelete = AdminLayout::e($deleteAction);
        $token = CsrfMiddleware::token();
        $localeInput = '' !== $locale
            ? '<input type="hidden" name="locale" value="' . AdminLayout::e($locale) . '">'
            : '';

        return '<div class="m-bulk-bar" id="bulk-bar">'
            . '<span class="m-bulk-bar__count" id="bulk-count">0 selected</span>'
            . '<div style="display:flex;gap:6px;">'
            // Publish — plain POST: the row checkboxes live outside this
            // form, so a full-page submit + redirect-back is the only flow
            // that can carry them (see AdminLayout mBulkInject).
            . '<form method="POST" action="' . $escapedPublish . '"'
            . ' style="display:inline;">'
            . '<input type="hidden" name="_token" value="' . AdminLayout::e($token) . '">'
            . '<input type="hidden" name="entity" value="' . $escapedEntity . '">'
            . '<input type="hidden" name="published" value="1">'
            . $localeInput
            . '<button type="submit" class="m-btn m-btn-success m-btn-sm"'
            . ' data-confirm="Publish selected items?">'
            . 'Publish selected'
            . '</button>'
            . '</form>'
            // Unpublish — plain POST, same ids[]-injection flow as publish.
            . '<form method="POST" action="' . $escapedPublish . '"'
            . ' style="display:inline;">'
            . '<input type="hidden" name="_token" value="' . AdminLayout::e($token) . '">'
            . '<input type="hidden" name="entity" value="' . $escapedEntity . '">'
            . '<input type="hidden" name="published" value="0">'
            . $localeInput
            . '<button type="submit" class="m-btn m-btn-warning m-btn-sm"'
            . ' data-confirm="Unpublish selected items?">'
            . 'Unpublish selected'
            . '</button>'
            . '</form>'
            // Delete — plain POST, same ids[]-injection flow as publish.
            . '<form method="POST" action="' . $escapedDelete . '"'
            . ' style="display:inline;" id="bulk-delete-form">'
            . '<input type="hidden" name="_token" value="' . AdminLayout::e($token) . '">'
            . '<input type="hidden" name="entity" value="' . $escapedEntity . '">'
            . $localeInput
            . '<button type="submit" class="m-btn m-btn-error m-btn-sm" id="bulk-delete-btn">'
            . 'Delete selected'
            . '</button>'
            . '</form>'
            . '</div>'
            . '</div>'
            . self::deleteConfirmScript();
    }

    /**
     * JS script for smart delete confirmation.
     *
     * Reads published badges from selected rows and shows a confirmation
     * dialog with item names when published items are selected.
     */
    private static function deleteConfirmScript(): string
    {
        return ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/bulk-actions.phtml', []);
    }
}
