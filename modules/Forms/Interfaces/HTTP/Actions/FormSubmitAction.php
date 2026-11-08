<?php

declare(strict_types = 1);

namespace Modules\Forms\Interfaces\HTTP\Actions;

use Modules\Blog\Interfaces\HTTP\Actions\BlogArticleAction;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Forms\Application\FormsServices;
use Modules\Forms\Application\Services\ReturnTargetResolver;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Entities\FormSubmission;
use Modules\Forms\Domain\Model\FormFieldType;
use Modules\Forms\Domain\Validation\FormValidator;
use Modules\Pages\Interfaces\HTTP\Actions\PageViewAction;

/**
 * FormSubmitAction — POST /forms/{slug}.
 *
 * The same posture as `QuestionSubmitAction`, because the same attacks apply:
 *
 *  1. CSRF is enforced globally by `CsrfMiddleware` (the form carries its
 *     field), before this action runs.
 *  2. The honeypot is checked BEFORE the definition lookup, so a bot cannot
 *     probe which slugs exist by timing a 404 against a redirect.
 *  3. The per-IP, per-form window is checked before any write.
 *  4. Undeclared keys are dropped and stored values are raw; escaping happens
 *     at render time.
 *  5. A refusal carries NO `Location` header. `Response::send()` rewrites any
 *     302 carrying one, so a refusal that also redirects leaves the browser
 *     on the unchanged page with the refusal discarded.
 *
 * PRG: success redirects to the embedding subject with `form-sent=1` and the
 * form's anchor. A validation failure RE-RENDERS that subject through its own
 * action, so the reader's words survive and there is one renderer per surface.
 * With no usable return target there is nothing to re-render, and guessing the
 * locale home would silently drop the text: that case answers 400.
 */
#[Route(path: '/forms/{slug}', method: 'POST', name: 'forms.submit')]
final readonly class FormSubmitAction
{
    private const int RATE_LIMIT = 5;

    private const int RATE_WINDOW = 300;

    public function handle(Request $request): Response
    {
        $slug = (string) ($request->routeParam('slug') ?? '');

        if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $slug)) {
            return $this->refusal('Bad request', 'Invalid form address.', 400);
        }

        $locale = $this->locale($request);
        $resolver = new ReturnTargetResolver();
        $return = $resolver->resolve($request->bodyString('form_return'), $locale);

        // Honeypot before the lookup: a bot learns nothing from the response.
        if ('' !== trim($request->bodyString('form_website'))) {
            return $this->redirectTo($return, $locale, $slug);
        }

        $form = $this->resolve($slug, $locale);

        if (null === $form || !$form->schema->isValid()) {
            return $this->refusal('Not found', 'There is no published form at that address.', 404);
        }

        $ip = $request->getClientIp();

        if (!FormsServices::limiter()->isAllowed('form:' . $slug . ':' . $ip, self::RATE_LIMIT, self::RATE_WINDOW)) {
            return $this->refusal('Too many submissions', 'Please wait a few minutes before sending again.', 429);
        }

        $payload = $this->payload($request, $form);
        $result = (new FormValidator())->validate($form->schema, $payload);

        if ([] !== $result['errors']) {
            if (null === $return) {
                return $this->refusal(
                    'Could not send the form',
                    'The page this form belongs to could not be found, so the submission was not sent.',
                    400,
                );
            }

            return $this->reRenderWithErrors($request, $return, $result['errors'], $result['invalid'], $payload);
        }

        FormsServices::submissions()->insert(FormSubmission::create(
            $form->id,
            $form->slug,
            $form->locale,
            $payload,
            $ip,
        ));

        return $this->redirectTo($return, $locale, $slug);
    }

    /**
     * The declared keys only. A checkbox answers `1` or nothing; every other
     * type answers a trimmed string. Unknown keys never reach storage, which
     * is what keeps the payload equal to the schema.
     *
     * @return array<string, string>
     */
    private function payload(Request $request, Form $form): array
    {
        $payload = [];

        foreach ($form->schema->fields() as $field) {
            if (FormFieldType::CHECKBOX === $field->type) {
                $payload[$field->key] = '1' === $request->bodyString($field->key) ? '1' : '';

                continue;
            }

            $payload[$field->key] = trim($request->bodyString($field->key));
        }

        return $payload;
    }

    /**
     * Exact locale, then the configured default, mirroring the block.
     */
    private function resolve(string $slug, string $locale): ?Form
    {
        $form = FormsServices::forms()->findPublished($slug, $locale);

        if (null !== $form) {
            return $form;
        }

        $default = ContentServices::mapper()->defaultLocale();

        if ($default === $locale) {
            return null;
        }

        return FormsServices::forms()->findPublished($slug, $default);
    }

    /**
     * Dispatch to the embedding surface, the same hand-off
     * `QuestionSubmitAction::reRenderWithErrors()` makes: the page action reads
     * `routeParam('slug')` and `getPathInfo()`, and passes the three attributes
     * into `MarkdownBlockContext`.
     *
     * @param list<string>          $errors
     * @param list<string>          $invalid
     * @param array<string, string> $old
     */
    private function reRenderWithErrors(
        Request $request,
        string $return,
        array $errors,
        array $invalid,
        array $old,
    ): Response {
        $request->setAttribute('form_errors', $errors);
        $request->setAttribute('form_invalid', $invalid);
        $request->setAttribute('form_old', $old);

        if (str_starts_with($return, '/blog/')) {
            $request->setRouteParams(['slug' => substr($return, \strlen('/blog/'))]);
            $request->setPathInfo($return);

            return (new BlogArticleAction())->handle($request);
        }

        $request->setRouteParams(['slug' => trim($return, '/')]);
        $request->setPathInfo('/' === $return ? '/' : $return);

        return (new PageViewAction())->handle($request);
    }

    private function redirectTo(?string $target, string $locale, string $slug): Response
    {
        $path = $target ?? '/';

        return Response::redirect(
            ContentServices::mapper()->urlPrefix($locale) . $path . '?form-sent=1#form-' . rawurlencode($slug),
        );
    }

    /**
     * A bare refusal page, deliberately without a `Location` header.
     */
    private function refusal(string $title, string $message, int $status): Response
    {
        return Response::html(
            '<!doctype html><html lang="en"><head><meta charset="UTF-8"><title>' . $title . '</title></head>'
            . '<body><h1>' . $title . '</h1><p>' . $message . '</p></body></html>',
            $status,
        );
    }

    private function locale(Request $request): string
    {
        $locale = $request->getAttribute('locale');

        return ContentServices::mapper()->code(is_string($locale) ? $locale : '');
    }
}
