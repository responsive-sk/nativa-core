<?php

declare(strict_types = 1);

namespace Modules\Forms\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Forms\Application\FormsServices;

/**
 * Mark handled — POST /mark/forms/{slug}/submissions/{id}/handled.
 *
 * Queue depth, not unread state: this is the action that clears one from the
 * nav badge. Idempotent, so a double submit changes nothing.
 *
 * The submission is checked against the form BEFORE it is touched: an `id`
 * from another definition must not be moderatable through this slug's URL.
 * Both belong to the same database, so the check is one lookup rather than a
 * second table.
 */
#[Route(
    path: '/mark/forms/{slug}/submissions/{id}/handled',
    method: 'POST',
    name: 'mark.forms.submissions.handled',
)]
final readonly class SubmissionsHandledAction
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

        FormsServices::submissions()->markHandled($id);

        AuditServices::log(
            'form.submission.handled',
            'form',
            'Handled a submission on ' . $form->slug,
            $request->getClientIp(),
        );

        return Response::redirect(
            $this->url('mark.forms.submissions', ['slug' => $form->slug]) . '?locale=' . $language,
        );
    }
}
