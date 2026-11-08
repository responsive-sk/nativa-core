<?php

declare(strict_types = 1);

namespace Modules\Forms\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Forms\Application\FormsServices;

/**
 * Delete a submission — POST /mark/forms/{slug}/submissions/{id}/delete.
 *
 * The cache is flushed because the result bars are computed from stored
 * answers: a page rendered before the delete would otherwise keep showing the
 * old percentage with `?form-sent=1`.
 *
 * As with handling, the submission must belong to the form named in the path.
 */
#[Route(
    path: '/mark/forms/{slug}/submissions/{id}/delete',
    method: 'POST',
    name: 'mark.forms.submissions.delete',
)]
final readonly class SubmissionsDeleteAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $locale = ContentServices::mapper()->code($request->queryString('locale', 'sk'));
        $language = ContentServices::mapper()->languageCode($locale);
        $slug = (string) ($request->routeParam('slug') ?? '');
        $id = (string) ($request->routeParam('id') ?? '');
        $form = FormsServices::forms()->findBySlug($slug, $locale);
        $submission = FormsServices::submissions()->findById($id);

        if (null === $form || null === $submission || $submission->formId !== $form->id) {
            return Response::redirect($this->url('mark.forms') . '?locale=' . $language);
        }

        FormsServices::submissions()->delete($id);

        CacheInvalidator::flush();
        AuditServices::log(
            'form.submission.delete',
            'form',
            'Deleted a submission on ' . $form->slug,
            $request->getClientIp(),
        );

        return Response::redirect(
            $this->url('mark.forms.submissions', ['slug' => $form->slug]) . '?locale=' . $language,
        );
    }
}
