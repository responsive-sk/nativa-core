<?php

declare(strict_types = 1);

namespace Modules\Forms\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Forms\Application\FormsServices;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Model\FormSchema;
use Modules\Forms\Interfaces\HTTP\FormEditor;
use Modules\Forms\Interfaces\HTTP\FormEditorInput;

/**
 * Forms edit — GET|POST /mark/forms/{slug}/edit.
 *
 * The locale comes from the query toggle, exactly like the index, so the
 * editor always opens the definition of the language being viewed.
 *
 * A rename keeps the definition's id, which is what keeps its submissions
 * attached: `form_submissions.form_id` is the relation, and `form_slug` on a
 * submission is a snapshot of the address at the time it was sent.
 */
#[Route(path: '/mark/forms/{slug}/edit', method: 'GET|POST', name: 'mark.forms.edit')]
final readonly class FormsEditAction
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

        if (!$request->isMethod('POST')) {
            return Response::html($this->page(FormEditor::data($form), $form, $locale, $language));
        }

        $data = FormEditorInput::extract($request);
        $schema = FormSchema::fromRows($data['fields']);
        $errors = FormEditorInput::errors($data, $schema);
        $nextSlug = FormEditorInput::slug($data);

        if ('' === $nextSlug) {
            $errors[] = 'Slug is required.';
        }

        if ([] === $errors && FormsServices::forms()->exists($nextSlug, $locale, $form->id)) {
            $errors[] = 'That slug is already used by another form in this locale.';
        }

        if ([] !== $errors) {
            return Response::html(
                $this->page($data, $form, $locale, $language, $errors, $schema->errors()),
                400,
            );
        }

        $updated = new Form(
            $form->id,
            $nextSlug,
            $locale,
            $data['title'],
            $data['intro'],
            $data['success_message'],
            $schema,
            $data['published'],
            $form->createdAt,
        );

        FormsServices::forms()->update($updated);

        CacheInvalidator::flush();
        AuditServices::log(
            'form.update',
            'form',
            'Updated: ' . $updated->title . ' (' . $updated->slug . ')',
            $request->getClientIp(),
        );

        return Response::redirect(
            $this->url('mark.forms') . '?locale=' . $language . '&toast=Form+saved',
        );
    }

    /**
     * @param array{slug: string, title: string, intro: string, success_message: string, published: bool, fields: list<array<string, mixed>>} $data
     * @param list<string>          $errors
     * @param array<string, string> $fieldErrors
     */
    private function page(
        array $data,
        Form $form,
        string $locale,
        string $language,
        array $errors = [],
        array $fieldErrors = [],
    ): string {
        // The editor renders its own <form>; the action carries the locale so a
        // failed POST reopens the same definition in the same language.
        $content = FormEditor::render(
            $this->url('mark.forms.edit', ['slug' => $form->slug]) . '?locale=' . $language,
            $data,
            $locale,
            $errors,
            $fieldErrors,
        );

        return AdminLayout::render('Edit form', $content, 'forms', router: $this->router());
    }

    private function locale(Request $request): string
    {
        $submitted = $request->bodyString('locale');

        return ContentServices::mapper()->code('' !== $submitted ? $submitted : $request->queryString('locale', 'sk'));
    }
}
