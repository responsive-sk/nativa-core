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
 * Category management — GET /mark/categories, POST /mark/categories/create,
 * POST /mark/categories/rename, POST /mark/categories/delete.
 *
 * Lists all categories (registry + front-matter derived) with article counts.
 * New categories can be pre-created without any article using them yet;
 * renaming updates all articles that use the category and deleting removes
 * it from all articles.
 */
#[Route(path: '/mark/categories', method: 'GET', name: 'categories.manage')]
#[Route(path: '/mark/categories/create', method: 'POST', name: 'categories.create', handlerMethod: 'create')]
#[Route(path: '/mark/categories/rename', method: 'POST', name: 'categories.rename', handlerMethod: 'rename')]
#[Route(path: '/mark/categories/delete', method: 'POST', name: 'categories.delete', handlerMethod: 'delete')]
final class CategoriesManageAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $counts = $this->buildCategoryIndex();
        $categories = BlogServices::taxonomy()->allCategories();

        $csrf = AdminLayout::csrfField();
        $rows = $this->categoryRows($categories, $counts, $csrf);

        $table = '' === $rows
            ? AdminLayout::emptyState('No categories yet', 'Create a category here or add one to an article.')
            : '<table class="m-table"><thead><tr><th>Category</th><th>Articles</th><th>Rename</th><th></th></tr></thead><tbody>'
            . $rows
            . '</tbody></table>';

        $content = '<div class="m-card">'
            . '<h2 class="m-heading">Blog Categories</h2>'
            . '<p class="m-card-intro">Categories can be pre-created here before any article '
            . 'uses them. Renaming updates all articles that use the category; deleting removes the category '
            . 'from all articles.</p>'
            . $this->createForm($csrf)
            . $table
            . '</div>'
            . $this->toast($request);

        return Response::html(AdminLayout::render(
            'Categories',
            $content,
            'categories',
            [['label' => 'New category', 'href' => $this->url('categories.manage') . '#create-form']],
        router: $this->router()));
    }

    public function create(Request $request): Response
    {
        $category = trim($request->bodyString('category'));

        if ('' === $category) {
            return Response::redirect($this->url('categories.manage'));
        }

        $created = BlogServices::taxonomy()->createCategory($category);

        if ($created) {
            BlogPageCache::invalidate();
            AuditServices::log(
                'categories.create',
                'categories',
                'Created category "' . $category . '"',
                $request->getClientIp(),
            );
        }

        return Response::redirect($this->url('categories.manage') . '?toast=' . ($created ? 'Category+created' : 'Category+already+exists'));
    }

    public function rename(Request $request): Response
    {
        $oldCategory = trim($request->bodyString('old_category'));
        $newCategory = trim($request->bodyString('new_category'));

        if ('' === $oldCategory || '' === $newCategory || $oldCategory === $newCategory) {
            return Response::redirect($this->url('categories.manage'));
        }

        // Both halves matter, and neither implies the other: the registry holds
        // a pre-created category (zero articles use it), the articles hold the
        // rest. Renaming only the articles left the registry-only entry in the
        // list under its old name — the button looked like a no-op.
        $renamed = BlogServices::taxonomy()->renameCategory($oldCategory, $newCategory);
        $count = $this->replaceCategoryInAllArticles($oldCategory, $newCategory);

        BlogPageCache::invalidate();
        AuditServices::log(
            'categories.rename',
            'categories',
            sprintf(
                'Renamed category "%s" to "%s" (%d articles, registry: %s)',
                $oldCategory,
                $newCategory,
                $count,
                $renamed ? 'renamed' : 'not registered',
            ),
            $request->getClientIp(),
        );

        return Response::redirect($this->url('categories.manage'));
    }

    public function delete(Request $request): Response
    {
        $category = trim($request->bodyString('category'));

        if ('' === $category) {
            return Response::redirect($this->url('categories.manage'));
        }

        // Same two halves as rename(): without the registry edit a pre-created
        // category stayed in the list after "delete" answered 302.
        $removed = BlogServices::taxonomy()->deleteCategory($category);
        $count = $this->removeCategoryFromAllArticles($category);

        BlogPageCache::invalidate();
        AuditServices::log(
            'categories.delete',
            'categories',
            sprintf(
                'Deleted category "%s" from %d articles (registry: %s)',
                $category,
                $count,
                $removed ? 'removed' : 'not registered',
            ),
            $request->getClientIp(),
        );

        return Response::redirect($this->url('categories.manage'));
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
     * Inline create form — anchor target for the "New category" header button.
     */
    private function createForm(string $csrf): string
    {
        return '<div id="create-form" class="m-filter-bar m-create-panel">'
            . '<form method="POST" action="' . AdminLayout::e($this->url('categories.create')) . '" class="m-inline-form">'
            . $csrf
            . '<input type="text" name="category" class="m-input" placeholder="New category name" required>'
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
     * @param list<string>       $categories
     * @param array<string, int> $counts     lowercase category => article count
     */
    private function categoryRows(array $categories, array $counts, string $csrf): string
    {
        $rows = '';

        foreach ($categories as $category) {
            $catEsc = AdminLayout::e($category);
            $count = $counts[strtolower($category)] ?? 0;
            $rows .= '<tr>'
                . "<td><strong>{$catEsc}</strong></td>"
                . "<td>{$count}</td>"
                . '<td>'
                . '<div class="m-btn-group">'
                . '<form method="POST" action="' . AdminLayout::e($this->url('categories.rename')) . '" class="m-inline-form">'
                . "{$csrf}"
                . "<input type=\"hidden\" name=\"old_category\" value=\"{$catEsc}\">"
                . '<input type="text" name="new_category" class="m-input m-input--compact" placeholder="New name" required>'
                . ' <button class="m-btn m-btn-sm">Rename</button>'
                . '</form>'
                . '</div>'
                . '</td>'
                . '<td>'
                . '<div class="m-btn-group">'
                . '<form method="POST" action="' . AdminLayout::e($this->url('categories.delete')) . '" class="m-inline-form">'
                . "{$csrf}"
                . "<input type=\"hidden\" name=\"category\" value=\"{$catEsc}\">"
                . '<button class="m-btn m-btn-error m-btn-sm m-btn-icon" '
                . "\"data-confirm=\\\"Remove category &quot;{$catEsc}&quot; from all articles?\\\" title=\\\"Delete category\\\">" . AdminLayout::iconDelete() . '</button>'
                . '</form>'
                . '</div>'
                . '</td>'
                . '</tr>';
        }

        return $rows;
    }

    /**
     * Build a category index: normalized category name → article count
     * across both locales.
     *
     * @return array<string, int>
     */
    private function buildCategoryIndex(): array
    {
        $counts = [];

        foreach (ContentLocaleServices::mapper()->locales() as $locale) {
            $service = BlogServices::crud();
            $articles = $service->list($locale);

            foreach ($articles as $article) {
                $category = trim($article['category']);
                if ('' !== $category) {
                    $counts[strtolower($category)] = ($counts[strtolower($category)] ?? 0) + 1;
                }
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Replace a category in all articles across both locales. Returns the number
     * of articles modified.
     */
    private function replaceCategoryInAllArticles(string $oldCategory, string $newCategory): int
    {
        $count = 0;

        foreach (ContentLocaleServices::mapper()->locales() as $locale) {
            $service = BlogServices::crud();
            $articles = $service->list($locale);

            foreach ($articles as $article) {
                $currentCategory = trim($article['category']);
                if ('' === $currentCategory || strtolower($currentCategory) !== strtolower($oldCategory)) {
                    continue;
                }

                $found = $service->find($article['slug'], $locale);
                if (null === $found) {
                    continue;
                }

                $meta = $found['meta'];
                $meta['category'] = $newCategory;
                $service->save($article['slug'], $meta, $found['body'], $locale);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Remove a category from all articles across both locales. Returns the
     * number of articles modified.
     */
    private function removeCategoryFromAllArticles(string $category): int
    {
        $count = 0;

        foreach (ContentLocaleServices::mapper()->locales() as $locale) {
            $service = BlogServices::crud();
            $articles = $service->list($locale);

            foreach ($articles as $article) {
                $currentCategory = trim($article['category']);
                if ('' === $currentCategory || strtolower($currentCategory) !== strtolower($category)) {
                    continue;
                }

                $found = $service->find($article['slug'], $locale);
                if (null === $found) {
                    continue;
                }

                $meta = $found['meta'];
                $meta['category'] = '';
                $service->save($article['slug'], $meta, $found['body'], $locale);
                $count++;
            }
        }

        return $count;
    }
}
