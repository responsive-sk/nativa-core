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
use Modules\Forms\Domain\Entities\FormSubmission;

/**
 * Submissions inbox — GET /mark/forms/{slug}/submissions.
 *
 * Newest first, with the raw payload rendered as escaped text: a reader's own
 * words are data, never markup. A `handled` row stays in the list, because the
 * inbox is also the record of what was already dealt with; only the badge is a
 * queue.
 */
#[Route(path: '/mark/forms/{slug}/submissions', method: 'GET', name: 'mark.forms.submissions')]
final readonly class SubmissionsIndexAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $locale = $this->locale($request);
        $language = ContentServices::mapper()->languageCode($locale);
        $slug = (string) ($request->routeParam('slug') ?? '');
        $form = FormsServices::forms()->findBySlug($slug, $locale);

        if (null === $form) {
            return Response::html(
                AdminLayout::render('Form not found', AdminLayout::emptyState(
                    'Form not found',
                    'The definition was removed or moved to another locale.',
                    $this->url('mark.forms') . '?locale=' . $language,
                    'Back to forms',
                ), 'forms', router: $this->router()),
                404,
            );
        }

        $submissions = FormsServices::submissions()->listForForm($form->id);
        $csrf = AdminLayout::csrfField();
        $localeQuery = '?locale=' . $language;

        $content = '<div class="m-filter-bar">'
            . '<a class="m-btn m-btn-ghost" href="' . AdminLayout::e($this->url('mark.forms') . '?locale=' . $language) . '">Back to forms</a>'
            . '<a class="m-btn m-btn-ghost" href="' . AdminLayout::e($this->url('mark.forms.edit', ['slug' => $form->slug]) . '?locale=' . $language) . '">Edit definition</a>'
            . '</div>';

        if ([] === $submissions) {
            $content .= AdminLayout::emptyState('No submissions yet', 'A reader has not sent this form.');
        } else {
            $content .= '<div class="m-card"><table class="m-table"><thead><tr>'
                . '<th>Received</th><th>Answer</th><th>State</th><th>Actions</th>'
                . '</tr></thead><tbody>';

            foreach ($submissions as $submission) {
                $content .= $this->row($submission, $form->schema->fields(), $form->slug, $localeQuery, $csrf);
            }

            $content .= '</tbody></table></div>';
        }

        return Response::html(AdminLayout::render(
            'Submissions: ' . $form->title,
            $content,
            'forms',
            router: $this->router(),
        ));
    }

    /**
     * @param list<\Modules\Forms\Domain\Entities\FormField> $fields
     */
    private function row(FormSubmission $submission, array $fields, string $formSlug, string $localeQuery, string $csrf): string
    {
        $answers = '';
        $labels = [];

        foreach ($fields as $field) {
            $labels[$field->key] = $field->label;
        }

        foreach ($submission->payload as $key => $value) {
            $label = $labels[$key] ?? $key;
            $answers .= '<div><strong>' . AdminLayout::e($label) . '</strong>: '
                . nl2br(AdminLayout::e($value)) . '</div>';
        }

        $received = $submission->createdAt?->format('Y-m-d H:i') ?? '';
        $handled = $submission->isNew() ? '' : '<span class="m-badge m-badge-ok">' . AdminLayout::e($submission->status) . '</span>';

        $actions = '';

        if ($submission->isNew()) {
            $actions .= '<form method="POST" action="' . AdminLayout::e($this->url('mark.forms.submissions.handled', ['slug' => $formSlug, 'id' => $submission->id]) . $localeQuery) . '" style="display:inline">'
                . $csrf
                . '<button class="m-btn m-btn-ghost" type="submit">Mark handled</button>'
                . '</form> ';
        }

        $actions .= '<form method="POST" action="' . AdminLayout::e($this->url('mark.forms.submissions.delete', ['slug' => $formSlug, 'id' => $submission->id]) . $localeQuery) . '" style="display:inline" onsubmit="return confirm(\'Delete this submission?\')">'
            . $csrf
            . '<button class="m-btn m-btn-ghost" type="submit">Delete</button>'
            . '</form>';

        return '<tr>'
            . '<td>' . AdminLayout::e($received) . '</td>'
            . '<td>' . $answers . '</td>'
            . '<td>' . AdminLayout::e($submission->status) . $handled . '</td>'
            . '<td>' . $actions . '</td>'
            . '</tr>';
    }

    private function locale(Request $request): string
    {
        return ContentServices::mapper()->code($request->queryString('locale', 'sk'));
    }
}
