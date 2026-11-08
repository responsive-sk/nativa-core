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
use Modules\Forms\Domain\Entities\Form;

/**
 * Forms publish toggle — POST /mark/forms/{slug}/publish.
 *
 * POST, not a link: it changes what published pages render, and a prefetching
 * browser must not flip a definition. The cache is flushed in the same request
 * because the embedded markup is already in it.
 */
#[Route(path: '/mark/forms/{slug}/publish', method: 'POST', name: 'mark.forms.publish-toggle')]
final readonly class FormsPublishToggleAction
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

        $updated = new Form(
            $form->id,
            $form->slug,
            $form->locale,
            $form->title,
            $form->intro,
            $form->successMessage,
            $form->schema,
            !$form->published,
            $form->createdAt,
        );

        FormsServices::forms()->update($updated);

        CacheInvalidator::flush();
        AuditServices::log(
            'form.publish',
            'form',
            ($updated->published ? 'Published: ' : 'Unpublished: ') . $updated->title . ' (' . $updated->slug . ')',
            $request->getClientIp(),
        );

        return Response::redirect($this->url('mark.forms') . '?locale=' . $language);
    }

    private function locale(Request $request): string
    {
        return ContentServices::mapper()->code($request->queryString('locale', 'sk'));
    }
}
