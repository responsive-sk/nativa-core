<?php

declare(strict_types = 1);

namespace Modules\Forms\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Forms\Application\FormsServices;
use Modules\Forms\Domain\Entities\Form;

/**
 * Forms index — GET /mark/forms.
 *
 * The address the nav item, the route policy, the `menu_items` seed and the
 * `forms.*` copy have always pointed at, and which answered 404 until this
 * module existed.
 *
 * The locale toggle mirrors Pages: `?locale=sk|en`, canonicalised through
 * `ContentServices::mapper()->code()`. A definition belongs to one locale, so
 * the list never mixes two languages.
 */
#[Route(path: '/mark/forms', method: 'GET', name: 'mark.forms')]
final readonly class FormsIndexAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $locale = $this->locale($request);
        $language = ContentServices::mapper()->languageCode($locale);
        $forms = FormsServices::forms()->listForLocale($locale);
        $csrf = AdminLayout::csrfField();

        $content = '<div class="m-filter-bar">'
            . '<a class="m-btn m-btn-primary" href="' . AdminLayout::e($this->url('mark.forms.create') . '?locale=' . $language) . '">New form</a>'
            . $this->localeLinks($language, $locale)
            . '</div>';

        if ([] === $forms) {
            $content .= AdminLayout::emptyState(
                'No forms in this locale',
                'Create a form, then embed it on a page with {{form:slug}} or in a page front matter with form: slug.',
            );
        } else {
            $content .= '<div class="m-card"><table class="m-table"><thead><tr>'
                . '<th>Title</th><th>Slug</th><th>Fields</th><th>State</th><th>New</th><th>Actions</th>'
                . '</tr></thead><tbody>';

            foreach ($forms as $form) {
                $content .= $this->row($form, $language, $csrf);
            }

            $content .= '</tbody></table></div>';
        }

        return Response::html(AdminLayout::render('Forms', $content, 'forms', router: $this->router()));
    }

    private function row(Form $form, string $language, string $csrf): string
    {
        $localeQuery = '?locale=' . $language;

        $new = FormsServices::submissions()->countNewForForm($form->id);

        return '<tr>'
            . '<td>' . AdminLayout::e($form->title) . '</td>'
            . '<td><code>{{form:' . AdminLayout::e($form->slug) . '}}</code></td>'
            . '<td>' . \count($form->schema->fields()) . '</td>'
            . '<td>' . ($form->published ? 'Published' : 'Draft') . '</td>'
            . '<td>' . ($new > 0 ? (string) $new : '0') . '</td>'
            . '<td>'
            . '<a class="m-btn m-btn-ghost" href="' . AdminLayout::e($this->url('mark.forms.edit', ['slug' => $form->slug]) . $localeQuery) . '">Edit</a> '
            . '<a class="m-btn m-btn-ghost" href="' . AdminLayout::e($this->url('mark.forms.submissions', ['slug' => $form->slug]) . $localeQuery) . '">Submissions</a> '
            . '<form method="POST" action="' . AdminLayout::e($this->url('mark.forms.publish-toggle', ['slug' => $form->slug]) . $localeQuery) . '" style="display:inline">'
            . $csrf
            . '<button class="m-btn m-btn-ghost" type="submit">' . ($form->published ? 'Unpublish' : 'Publish') . '</button>'
            . '</form> '
            . '<form method="POST" action="' . AdminLayout::e($this->url('mark.forms.delete', ['slug' => $form->slug]) . $localeQuery) . '" style="display:inline" onsubmit="return confirm(\'Delete this form and its submissions?\')">'
            . $csrf
            . '<button class="m-btn m-btn-ghost" type="submit">Delete</button>'
            . '</form>'
            . '</td></tr>';
    }

    private function localeLinks(string $language, string $locale): string
    {
        $html = '';

        foreach (ContentServices::mapper()->locales() as $code) {
            $lang = ContentServices::mapper()->languageCode($code);
            $html .= '<a class="m-btn m-btn-ghost" href="' . AdminLayout::e($this->url('mark.forms') . '?locale=' . $lang) . '">'
                . ($code === $locale ? '[' . AdminLayout::e($lang) . ']' : AdminLayout::e($lang)) . '</a> ';
        }

        return $html;
    }

    private function locale(Request $request): string
    {
        return ContentServices::mapper()->code($request->queryString('locale', 'sk'));
    }
}
