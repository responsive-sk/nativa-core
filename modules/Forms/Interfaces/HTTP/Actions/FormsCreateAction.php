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
 * Forms create — GET|POST /mark/forms/create.
 *
 * GET renders one blank field row; POST normalises the definition through
 * `FormSchema`, refuses a slug that already exists in this locale, writes the
 * definition and flushes the page cache, because the form's markup is embedded
 * in cached HTML.
 */
#[Route(path: '/mark/forms/create', method: 'GET|POST', name: 'mark.forms.create')]
final readonly class FormsCreateAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $locale = $this->locale($request);
        $language = ContentServices::mapper()->languageCode($locale);

        if (!$request->isMethod('POST')) {
            return Response::html($this->page(FormEditor::blank(), $locale, $language));
        }

        $data = FormEditorInput::extract($request);
        $schema = FormSchema::fromRows($data['fields']);
        $errors = FormEditorInput::errors($data, $schema);
        $slug = FormEditorInput::slug($data);

        if ('' === $slug) {
            $errors[] = 'Slug is required.';
        }

        if ([] === $errors && FormsServices::forms()->exists($slug, $locale)) {
            $errors[] = 'That slug is already used by another form in this locale.';
        }

        if ([] !== $errors) {
            return Response::html($this->page($data, $locale, $language, $errors, $schema->errors()), 400);
        }

        $form = Form::create(
            $slug,
            $locale,
            $data['title'],
            $data['intro'],
            $data['success_message'],
            $schema,
            $data['published'],
        );

        FormsServices::forms()->insert($form);

        CacheInvalidator::flush();
        AuditServices::log(
            'form.create',
            'form',
            'Created: ' . $form->title . ' (' . $form->slug . ')',
            $request->getClientIp(),
        );

        return Response::redirect(
            $this->url('mark.forms') . '?locale=' . $language . '&toast=Form+created+successfully',
        );
    }

    /**
     * @param array{slug: string, title: string, intro: string, success_message: string, published: bool, fields: list<array<string, mixed>>} $data
     * @param list<string>          $errors
     * @param array<string, string> $fieldErrors
     */
    private function page(array $data, string $locale, string $language, array $errors = [], array $fieldErrors = []): string
    {
        $content = FormEditor::render(
            $this->url('mark.forms.create') . '?locale=' . $language,
            $data,
            $locale,
            $errors,
            $fieldErrors,
        );

        return AdminLayout::render('New form', $content, 'forms', router: $this->router());
    }

    private function locale(Request $request): string
    {
        $created = $request->bodyString('locale');

        return ContentServices::mapper()->code('' !== $created ? $created : $request->queryString('locale', 'sk'));
    }
}
