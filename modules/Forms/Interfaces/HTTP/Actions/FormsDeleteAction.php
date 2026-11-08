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
 * Forms delete — POST /mark/forms/{slug}/delete.
 *
 * Deletes the definition AND its submissions in one repository transaction.
 * The operator is warned in the confirmation text: the inbox is the only copy
 * of what a reader sent, and a form that is merely unwanted can be
 * unpublished instead.
 */
#[Route(path: '/mark/forms/{slug}/delete', method: 'POST', name: 'mark.forms.delete')]
final readonly class FormsDeleteAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $locale = $this->locale($request);
        $language = ContentServices::mapper()->languageCode($locale);
        $slug = (string) ($request->routeParam('slug') ?? '');
        $form = FormsServices::forms()->findBySlug($slug, $locale);

        if (null === $form) {
            return Response::redirect($this->url('mark.forms') . '?locale=' . $language);
        }

        FormsServices::forms()->delete($form->id);

        CacheInvalidator::flush();
        AuditServices::log(
            'form.delete',
            'form',
            'Deleted: ' . $form->title . ' (' . $form->slug . ') with its submissions',
            $request->getClientIp(),
        );

        return Response::redirect(
            $this->url('mark.forms') . '?locale=' . $language . '&toast=Form+deleted',
        );
    }

    private function locale(Request $request): string
    {
        return ContentServices::mapper()->code($request->queryString('locale', 'sk'));
    }
}
