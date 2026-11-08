<?php

declare(strict_types = 1);

namespace Modules\Pages\Interfaces\HTTP;

use Modules\Cardboard\Application\Services\BulkActions;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Locales\Interfaces\HTTP\LocalesServices;

/**
 * PagesTableRenderer — renders the Pages admin table (thead + rows).
 *
 * Shared between PagesIndexAction (list view) and BulkDeleteAction (post-delete re-render).
 */
final class PagesTableRenderer
{
    /**
     * Render the table HTML (without wrapper div or bulk bar).
     *
     * @param list<array{slug: string, title: string, published: bool}> $pages
     * @param array<string, string>                                     $baseParams Query params preserved by the sort links (locale, q)
     * @param string                                                    $sort       Active sort column
     * @param string                                                    $dir        Active sort direction
     */
    public static function table(array $pages, string $locale, string $sort = 'date', string $dir = 'desc', array $baseParams = [], ?RouterInterface $router = null): string
    {
        $router ??= throw new \LogicException('PagesTableRenderer::table() needs a Router.');
        $rows = '';

        foreach ($pages as $page) {
            $rows .= self::row($page, $locale, $router);
        }

        $headers = BulkActions::headerCell()
            . AdminLayout::sortableHeader('Title', 'title', $sort, $dir, $baseParams)
            . '<th>Slug</th>'
            . AdminLayout::sortableHeader('Status', 'published', $sort, $dir, $baseParams)
            . '<th>Actions</th>';

        return '<div class="m-table-scroll">'
            . '<table class="m-table"><thead><tr>'
            . $headers
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '</div>';
    }

    /**
     * Render the complete table with bulk bar wrapper — for BulkDeleteAction re-render.
     *
     * @param list<array{slug: string, title: string, published: bool}> $pages
     */
    public static function render(array $pages, string $locale, RouterInterface $router): string
    {
        $bulkBar = BulkActions::actionBarWithPublish(
            'pages',
            $router->url('mark.bulk-publish'),
            $router->url('mark.bulk-delete'),
            $locale,
        );

        return $bulkBar
            . '<div id="pages-content">'
            . self::table($pages, $locale, router: $router)
            . '</div>';
    }

    /**
     * Render a single table row — public so the publish-toggle action can
     * return the updated row for an HTMX swap.
     *
     * @param array{slug: string, title: string, published: bool, translation?: ?string} $page
     */
    public static function row(array $page, string $locale, ?RouterInterface $router = null): string
    {
        $router ??= throw new \LogicException('PagesTableRenderer::row() needs a Router.');

        // Defense in depth beside the actions' boundary allowlist: a future
        // caller passing a raw param must not get attribute breakout.
        $locale = AdminLayout::e($locale);
        $slug = AdminLayout::e($page['slug']);
        $title = AdminLayout::e($page['title']);
        $editHref = $router->url('pages.edit', ['slug' => $slug]) . '?locale=' . $locale;
        $switch = BulkActions::markSwitch(
            $router->url('pages.publish-toggle', ['slug' => $slug]) . '?locale=' . $locale,
            $locale,
            AdminLayout::csrfField(),
            $page['published'],
            self::switchLabels(),
        );

        // The public URL is PER LOCALE — /o-nas in sk, /en/about in en. The
        // raw admin param is canonicalized through the mapper first, so 'sk'
        // and 'sk_SK' cannot disagree about where the page lives.
        $mapper = ContentServices::mapper();
        $slugUrl = $mapper->urlPrefix($mapper->code($locale)) . '/' . $page['slug'];
        $pair = is_string($page['translation'] ?? null) && '' !== $page['translation']
            ? ' &rarr; <code>' . AdminLayout::e($page['translation']) . '</code>'
            : '';
        $copyIcon = '<svg class="m-copy__icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';
        $checkbox = BulkActions::checkboxCell($page['slug']);
        $csrf = AdminLayout::csrfField();
        $iconView = AdminLayout::iconView();
        $iconEdit = AdminLayout::iconEdit();
        $iconDelete = AdminLayout::iconDelete();

        return '<tr>'
            . $checkbox
            . '<td><strong>' . $title . '</strong></td>'
            . '<td><span class="m-copy" data-copy="' . AdminLayout::e($slugUrl) . '"><code>' . $slug . '</code>' . $copyIcon . $pair . '</span></td>'
            . '<td>' . $switch . '</td>'
            . '<td style="white-space:nowrap;">'
            . '<div class="m-btn-group">'
            . '<a class="m-btn m-btn-sm m-btn-icon" href="' . AdminLayout::e($slugUrl) . '"'
            . ' target="_blank" rel="noopener" data-tooltip="View page" title="View">' . $iconView . '</a>'
            . '<a class="m-btn m-btn-sm m-btn-icon" href="' . $editHref . '"'
            . ' data-tooltip="Edit page" title="Edit">' . $iconEdit . '</a>'
            . '<form method="POST" action="' . AdminLayout::e($router->url('pages.delete', ['slug' => $slug]) . '?locale=' . $locale) . '"'
            . ' hx-delete="' . AdminLayout::e($router->url('pages.delete', ['slug' => $slug]) . '?locale=' . $locale) . '"'
            . ' data-confirm="' . ($page['published'] ? 'Delete this page? It will be unpublished first, then deleted.' : 'Delete this page?') . '"'
            . ' hx-target="closest tr"'
            . ' hx-swap="outerHTML swap:500ms"'
            . ' hx-indicator="#del-' . $slug . '"'
            . ' class="m-inline-form">'
            . $csrf
            . '<button class="m-btn m-btn-error m-btn-sm m-btn-icon" type="submit"'
            . ' data-tooltip="Delete page" title="Delete">'
            . $iconDelete
            . '<span class="htmx-indicator" id="del-' . $slug . '" style="margin-left:4px;">⏳</span>'
            . '</button>'
            . '</form>'
            . '</div>'
            . '</td>'
            . '</tr>';
    }

    /**
     * Translated switch labels — mark.* keys resolved through the active
     * admin locale; EN defaults keep the UI intact with an empty locales DB.
     *
     * @return array{draft: string, published: string, tooltip_publish: string, tooltip_unpublish: string}
     */
    private static function switchLabels(): array
    {
        return [
            'draft'             => LocalesServices::t('mark.switch_draft', 'Draft'),
            'published'         => LocalesServices::t('mark.switch_published', 'Published'),
            'tooltip_publish'   => LocalesServices::t('mark.switch_publish_tooltip', 'Click to publish'),
            'tooltip_unpublish' => LocalesServices::t('mark.switch_unpublish_tooltip', 'Click to unpublish'),
        ];
    }
}
