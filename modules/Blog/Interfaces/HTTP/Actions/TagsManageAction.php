<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogPageCache;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;

/**
 * Tag management — GET /mark/tags, POST /mark/tags/create, POST /mark/tags/rename,
 * POST /mark/tags/delete.
 *
 * Lists all tags (registry + front-matter derived) with article counts. New
 * tags can be pre-created without any article using them yet; renaming
 * updates all articles that use the tag and deleting removes it from all
 * articles.
 */
#[Route(path: '/mark/tags', method: 'GET', name: 'tags.manage')]
#[Route(path: '/mark/tags/create', method: 'POST', name: 'tags.create', handlerMethod: 'create')]
#[Route(path: '/mark/tags/rename', method: 'POST', name: 'tags.rename', handlerMethod: 'rename')]
#[Route(path: '/mark/tags/delete', method: 'POST', name: 'tags.delete', handlerMethod: 'delete')]
final class TagsManageAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $counts = $this->buildTagIndex();
        $tags = BlogServices::taxonomy()->allTags();

        $csrf = AdminLayout::csrfField();
        $rows = $this->tagRows($tags, $counts, $csrf);

        $table = '' === $rows
            ? AdminLayout::emptyState('No tags yet', 'Create a tag here or add one to an article.')
            : '<table class="m-table"><thead><tr><th>Tag</th><th>Articles</th><th>Rename</th><th></th></tr></thead><tbody>'
            . $rows
            . '</tbody></table>';

        $content = '<div class="m-card">'
            . '<h2 class="m-heading">Blog Tags</h2>'
            . '<p class="m-card-intro">Tags can be pre-created here before any article '
            . 'uses them. Renaming updates all articles that use the tag; deleting removes the tag from all '
            . 'articles.</p>'
            . $this->createForm($csrf)
            . $table
            . '</div>'
            . $this->toast($request);

        return Response::html(AdminLayout::render(
            'Tags',
            $content,
            'tags',
            [['label' => 'New tag', 'href' => $this->url('tags.manage') . '#create-form']],
        router: $this->router()));
    }

    public function create(Request $request): Response
    {
        $tag = trim($request->bodyString('tag'));

        if ('' === $tag) {
            return Response::redirect($this->url('tags.manage'));
        }

        $created = BlogServices::taxonomy()->createTag($tag);

        if ($created) {
            BlogPageCache::invalidate();
            AuditServices::log(
                'tags.create',
                'tags',
                'Created tag "' . $tag . '"',
                $request->getClientIp(),
            );
        }

        return Response::redirect($this->url('tags.manage') . '?toast=' . ($created ? 'Tag+created' : 'Tag+already+exists'));
    }

    public function rename(Request $request): Response
    {
        $oldTag = trim($request->bodyString('old_tag'));
        $newTag = trim($request->bodyString('new_tag'));

        if ('' === $oldTag || '' === $newTag || $oldTag === $newTag) {
            return Response::redirect($this->url('tags.manage'));
        }

        $count = $this->replaceTagInAllArticles($oldTag, $newTag);

        BlogPageCache::invalidate();
        AuditServices::log(
            'tags.rename',
            'tags',
            "Renamed tag \"{$oldTag}\" to \"{$newTag}\" ({$count} articles)",
            $request->getClientIp(),
        );

        return Response::redirect($this->url('tags.manage'));
    }

    public function delete(Request $request): Response
    {
        $tag = trim($request->bodyString('tag'));

        if ('' === $tag) {
            return Response::redirect($this->url('tags.manage'));
        }

        $count = $this->removeTagFromAllArticles($tag);

        BlogPageCache::invalidate();
        AuditServices::log(
            'tags.delete',
            'tags',
            "Deleted tag \"{$tag}\" from {$count} articles",
            $request->getClientIp(),
        );

        return Response::redirect($this->url('tags.manage'));
    }

    /**
     * Toast message from the ?toast= query param (after create).
     */
    private function toast(Request $request): string
    {
        $message = $request->queryString('toast');

        return '' !== $message ? AdminLayout::toast(urldecode($message)) : '';
    }

    /**
     * Inline create form — anchor target for the "New tag" header button.
     */
    private function createForm(string $csrf): string
    {
        return '<div id="create-form" class="m-filter-bar m-create-panel">'
            . '<form method="POST" action="' . AdminLayout::e($this->url('tags.create')) . '" class="m-inline-form">'
            . $csrf
            . '<input type="text" name="tag" class="m-input" placeholder="New tag name" required>'
            . '<button class="m-btn m-btn-primary m-btn-sm">Create</button>'
            . '</form>'
            . '</div>'
            . AdminLayout::script(
                '(function(){var p=document.getElementById("create-form");if(!p)return;'
                . 'if(location.hash==="#create-form"){var f=p.querySelector("input[type=text]");if(f)f.focus();return;}'
                . 'p.hidden=true;'
                . 'var b=document.querySelector(\'.cba__actions a[href$="#create-form"]\');'
                . 'if(!b)return;'
                . 'b.addEventListener("click",function(e){e.preventDefault();p.hidden=false;var f=p.querySelector("input[type=text]");if(f)f.focus();});'
                . '})();',
            );
    }

    /**
     * @param list<string>       $tags
     * @param array<string, int> $counts lowercase tag => article count
     */
    private function tagRows(array $tags, array $counts, string $csrf): string
    {
        $rows = '';

        foreach ($tags as $tag) {
            $tagEsc = AdminLayout::e($tag);
            $count = $counts[strtolower($tag)] ?? 0;
            $rows .= '<tr>'
                . "<td><strong>{$tagEsc}</strong></td>"
                . "<td>{$count}</td>"
                . '<td>'
                . '<div class="m-btn-group">'
                . '<form method="POST" action="' . AdminLayout::e($this->url('tags.rename')) . '" class="m-inline-form">'
                . "{$csrf}"
                . "<input type=\"hidden\" name=\"old_tag\" value=\"{$tagEsc}\">"
                . '<input type="text" name="new_tag" class="m-input m-input--compact" placeholder="New name" required>'
                . ' <button class="m-btn m-btn-sm">Rename</button>'
                . '</form>'
                . '</div>'
                . '</td>'
                . '<td>'
                . '<div class="m-btn-group">'
                . '<form method="POST" action="' . AdminLayout::e($this->url('tags.delete')) . '" class="m-inline-form">'
                . "{$csrf}"
                . "<input type=\"hidden\" name=\"tag\" value=\"{$tagEsc}\">"
                . '<button class="m-btn m-btn-error m-btn-sm m-btn-icon" '
                . "\"data-confirm=\\\"Remove tag &quot;{$tagEsc}&quot; from all articles?\\\" title=\\\"Delete tag\\\">" . AdminLayout::iconDelete() . '</button>'
                . '</form>'
                . '</div>'
                . '</td>'
                . '</tr>';
        }

        return $rows;
    }

    /**
     * Build a tag index: normalized tag name → article count across both
     * locales.
     *
     * @return array<string, int>
     */
    private function buildTagIndex(): array
    {
        $counts = [];

        foreach (ContentLocaleServices::mapper()->locales() as $locale) {
            $service = BlogServices::crud();
            $articles = $service->list($locale);

            foreach ($articles as $article) {
                foreach ($article['tags'] as $tag) {
                    $normalized = strtolower(trim($tag));
                    if ('' !== $normalized) {
                        $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
                    }
                }
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Replace a tag in all articles across both locales. Returns the number
     * of articles modified.
     */
    private function replaceTagInAllArticles(string $oldTag, string $newTag): int
    {
        $count = 0;

        foreach (ContentLocaleServices::mapper()->locales() as $locale) {
            $service = BlogServices::crud();
            $articles = $service->list($locale);

            foreach ($articles as $article) {
                if (!in_array(strtolower($oldTag), array_map(strtolower(...), $article['tags']), true)) {
                    continue;
                }

                $found = $service->find($article['slug'], $locale);
                if (null === $found) {
                    continue;
                }

                $meta = $found['meta'];
                $rawTags = is_array($meta['tags'] ?? null) ? $meta['tags'] : [];
                $oldTags = array_values(array_filter(array_map(
                    static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                    $rawTags,
                ), static fn (string $v): bool => '' !== $v));
                $newTags = array_map(
                    static fn (string $t): string => strtolower($t) === strtolower($oldTag) ? $newTag : $t,
                    $oldTags,
                );

                $meta['tags'] = $newTags;
                $service->save($article['slug'], $meta, $found['body'], $locale);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Remove a tag from all articles across both locales. Returns the
     * number of articles modified.
     */
    private function removeTagFromAllArticles(string $tag): int
    {
        $count = 0;

        foreach (ContentLocaleServices::mapper()->locales() as $locale) {
            $service = BlogServices::crud();
            $articles = $service->list($locale);

            foreach ($articles as $article) {
                if (!in_array(strtolower($tag), array_map(strtolower(...), $article['tags']), true)) {
                    continue;
                }

                $found = $service->find($article['slug'], $locale);
                if (null === $found) {
                    continue;
                }

                $meta = $found['meta'];
                $rawTags = is_array($meta['tags'] ?? null) ? $meta['tags'] : [];
                $currentTags = array_values(array_filter(array_map(
                    static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                    $rawTags,
                ), static fn (string $v): bool => '' !== $v));
                $meta['tags'] = array_values(array_filter(
                    $currentTags,
                    static fn (string $t): bool => strtolower($t) !== strtolower($tag),
                ));

                $service->save($article['slug'], $meta, $found['body'], $locale);
                $count++;
            }
        }

        return $count;
    }
}
