<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP;

use Modules\Cardboard\Application\Services\BulkActions;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Locales\Interfaces\HTTP\LocalesServices;

/**
 * ArticlesTableRenderer — renders the Articles admin table (thead + rows).
 *
 * Shared between ArticlesIndexAction (list view) and BulkDeleteAction (post-delete re-render).
 */
final class ArticlesTableRenderer
{
    /**
     * Render a single table row — public so the publish-toggle action can
     * return the updated row for an HTMX swap.
     *
     * @param array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool} $article
     */
    public static function row(array $article, string $locale, RouterInterface $router): string
    {
        return self::buildRow($article, $locale, $router);
    }

    /**
     * Render the table HTML (without wrapper div or bulk bar).
     *
     * @param list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}> $articles
     * @param array<string, string>                                                                                                                                                   $baseParams Query params preserved by the sort links (locale, q, period)
     */
    public static function table(array $articles, string $locale, string $sort = 'date', string $dir = 'desc', array $baseParams = [], ?RouterInterface $router = null): string
    {
        $rows = '';

        foreach ($articles as $article) {
            $rows .= self::row($article, $locale, $router ?? throw new \LogicException('Router required'));
        }

        $headers = BulkActions::headerCell()
            . AdminLayout::sortableHeader('Title', 'title', $sort, $dir, $baseParams)
            . AdminLayout::sortableHeader('Date', 'date', $sort, $dir, $baseParams)
            . '<th>Author</th>'
            . AdminLayout::sortableHeader('Status', 'published', $sort, $dir, $baseParams)
            . '<th>Badges</th><th>Actions</th>';

        return '<div class="m-table-scroll">'
            . '<table class="m-table"><thead><tr>'
            . $headers
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '</div>';
    }

    /**
     * Render the complete table with bulk bar wrapper — for BulkDeleteAction re-render.
     *
     * @param list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}> $articles
     */
    public static function render(array $articles, string $locale, RouterInterface $router): string
    {
        $bulkBar = BulkActions::actionBarWithPublish(
            'articles',
            $router->url('mark.bulk-publish'),
            $router->url('mark.bulk-delete'),
            $locale,
        );

        return $bulkBar
            . '<div id="articles-content">'
            . self::table($articles, $locale, router: $router)
            . '</div>';
    }

    /**
     * @param array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool} $article
     */
    private static function buildRow(array $article, string $locale, RouterInterface $router): string
    {
        // Defense in depth beside the actions' boundary allowlist: a future
        // caller passing a raw param must not get attribute breakout.
        $locale = AdminLayout::e($locale);
        $slug = AdminLayout::e($article['slug']);
        $title = AdminLayout::e($article['title']);
        $date = AdminLayout::e($article['date']);
        $author = AdminLayout::e($article['author']);
        $editHref = $router->url('articles.edit', ['slug' => $slug]) . '?locale=' . $locale;
        $checkbox = BulkActions::checkboxCell($article['slug']);
        $csrf = AdminLayout::csrfField();
        $iconView = AdminLayout::iconView();
        $iconEdit = AdminLayout::iconEdit();
        $iconDelete = AdminLayout::iconDelete();
        $mapper = ContentLocaleServices::mapper();
        $viewHref = $mapper->urlPrefix($mapper->code($locale)) . '/blog/' . $slug;

        $statusBadges = self::statusBadges($article);
        $slugUrl = '/blog/' . $slug;
        $copyIcon = '<svg class="m-copy__icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';
        $deleteForm = self::deleteForm($slug, $locale, $csrf, $iconDelete, $article['published'], $router);
        $switch = self::markSwitch(
            $router->url('articles.publish-toggle', ['slug' => $slug]) . '?locale=' . $locale,
            $locale,
            $csrf,
            $article['published'],
        );

        return '<tr>'
            . $checkbox
            . '<td><strong>' . $title . '</strong><br><span class="m-copy m-muted m-hint" data-copy="'
            . AdminLayout::e($slugUrl) . '">' . $slug . $copyIcon . '</span></td>'
            . '<td>' . $date . '</td>'
            . '<td>' . $author . '</td>'
            . '<td>' . $switch . '</td>'
            . '<td style="max-width:260px;">' . $statusBadges . '</td>'
            . '<td style="white-space:nowrap;">'
            . '<div class="m-btn-group">'
            . '<a class="m-btn m-btn-sm m-btn-icon" href="' . AdminLayout::e($viewHref) . '"'
            . ' target="_blank" rel="noopener" data-tooltip="View article" title="View">' . $iconView . '</a>'
            . '<a class="m-btn m-btn-sm m-btn-icon" href="' . $editHref . '"'
            . ' data-tooltip="Edit article" title="Edit">' . $iconEdit . '</a>'
            . $deleteForm
            . '</div>'
            . '</td>'
            . '</tr>';
    }

    /**
     * Publish/Draft switch — delegates to the shared BulkActions builder
     * wired to the articles publish-toggle endpoint, with labels resolved
     * through the Locales translation system (mark.* keys, EN defaults).
     *
     * The URL is built here through the Router; BulkActions only renders it.
     */
    private static function markSwitch(string $publishAction, string $locale, string $csrf, bool $published): string
    {
        return BulkActions::markSwitch($publishAction, $locale, $csrf, $published, self::switchLabels());
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

    /**
     * @param array{members_only: bool, category: string, tags: list<string>} $article
     */
    private static function statusBadges(array $article): string
    {
        $badges = '';
        if ($article['members_only']) {
            $badges .= '<span class="m-badge m-badge-purple">Members</span> ';
        }
        if ('' !== $article['category']) {
            $badges .= '<span class="m-badge m-badge-info">' . AdminLayout::e($article['category']) . '</span> ';
        }
        foreach ($article['tags'] as $tag) {
            $badges .= '<span class="m-badge m-badge-gray">' . AdminLayout::e($tag) . '</span> ';
        }

        return $badges;
    }

    private static function deleteForm(string $slug, string $locale, string $csrf, string $iconDelete, bool $published, RouterInterface $router): string
    {
        $confirm = $published
            ? 'Delete this article? It will be unpublished first, then deleted.'
            : 'Delete this article?';

        return '<form method="POST" action="' . AdminLayout::e($router->url('articles.delete', ['slug' => $slug]) . '?locale=' . $locale) . '"'
            . ' hx-delete="' . AdminLayout::e($router->url('articles.delete', ['slug' => $slug]) . '?locale=' . $locale) . '"'
            . ' data-confirm="' . $confirm . '"'
            . ' hx-target="closest tr"'
            . ' hx-swap="outerHTML swap:500ms"'
            . ' hx-indicator="#del-' . $slug . '"'
            . ' class="m-inline-form">'
            . $csrf
            . '<button class="m-btn m-btn-error m-btn-sm m-btn-icon" type="submit"'
            . ' data-tooltip="Delete article" title="Delete">'
            . $iconDelete
            . '<span class="htmx-indicator" id="del-' . $slug . '" style="margin-left:4px;">⏳</span>'
            . '</button>'
            . '</form>';
    }
}
