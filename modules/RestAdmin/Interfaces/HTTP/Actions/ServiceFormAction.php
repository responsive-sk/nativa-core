<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\RestAdmin\Application\Services\Audit;
use Modules\RestAdmin\Application\Spec\SpecValidationException;
use Modules\RestAdmin\Domain\Model\ServiceSpec;
use Modules\RestAdmin\Interfaces\HTTP\Panel;
use Modules\RestAdmin\Interfaces\HTTP\PanelHtml;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * ServiceFormAction — the service's own configuration, not its resources.
 *
 * The form collects the four things a service is: an id, a display name, the
 * base URL resource paths resolve against, and the *name* of the environment
 * variable that holds its token. There is no password field, because the spec
 * never stores a secret (plan D5).
 *
 * Every submit goes through `SpecValidator` before anything is written, and the
 * existing resources are carried into the document unchanged: editing a base
 * URL must not be able to silently drop a resource.
 */
#[Route(path: '/mark/rest/services', method: 'GET', name: 'mark.rest.services.create', handlerMethod: 'createForm')]
#[Route(path: '/mark/rest/services', method: 'POST', name: 'mark.rest.services.create.submit', handlerMethod: 'createSubmit')]
#[Route(path: '/mark/rest/services/{id}/edit', method: 'GET', name: 'mark.rest.service.edit', handlerMethod: 'editForm')]
#[Route(path: '/mark/rest/services/{id}/edit', method: 'POST', name: 'mark.rest.service.edit.submit', handlerMethod: 'editSubmit')]
#[Route(path: '/mark/rest/services/{id}/delete', method: 'POST|DELETE', name: 'mark.rest.service.delete', handlerMethod: 'delete')]
final class ServiceFormAction
{
    use ResolvesRouteUrls;

    public function createForm(Request $request): Response
    {
        return Panel::render(
            $this->router(),
            'New REST service',
            $this->form('New REST service', $this->url('mark.rest.services.create.submit'), [], [], 'Create service'),
        );
    }

    public function createSubmit(Request $request): Response
    {
        $values = $this->values($request);
        [$document, $errors] = $this->document($values, null);

        if ([] !== $errors) {
            return $this->formResponse('New REST service', $values, $errors, $this->url('mark.rest.services.create.submit'), 'Create service', 422);
        }

        $id = $this->uniqueId(self::slug($values['name']));
        $document['service']['id'] = $id;

        try {
            $spec = RestAdminServices::validator()->validate($document);
        } catch (SpecValidationException $exception) {
            return $this->formResponse('New REST service', $values, [$exception->getMessage()], $this->url('mark.rest.services.create.submit'), 'Create service', 422);
        }

        RestAdminServices::save($spec);
        Audit::log('restadmin.service.create', $id, 'Registered REST service ' . $spec->service->name, $request->getClientIp());

        return Response::redirect($this->url('mark.rest.service', ['id' => $id]) . '?toast=' . rawurlencode('Service created.'));
    }

    public function editForm(Request $request): Response
    {
        $spec = Panel::service((string) $request->routeParam('id'));

        if (null === $spec) {
            return Panel::notFound($this->router(), 'That REST service does not exist.');
        }

        $values = [
            'name'            => $spec->service->name,
            'base_url'        => $spec->service->baseUrl,
            'token_env'       => $spec->service->tokenEnv() ?? '',
            'auth_header'     => $spec->service->authHeader() ?? '',
            'timeout_seconds' => (string) $spec->service->timeoutSeconds,
            'headers'         => json_encode($spec->service->headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];

        return Panel::render(
            $this->router(),
            'Edit ' . $spec->service->name,
            $this->form(
                'Edit ' . $spec->service->name,
                $this->url('mark.rest.service.edit.submit', ['id' => $spec->service->id]),
                $values,
                [],
                'Save changes',
            ),
        );
    }

    public function editSubmit(Request $request): Response
    {
        $id = (string) $request->routeParam('id');
        $spec = Panel::service($id);

        if (null === $spec) {
            return Panel::notFound($this->router(), 'That REST service does not exist.');
        }

        $values = $this->values($request);
        [$document, $errors] = $this->document($values, $spec);

        if ([] !== $errors) {
            return $this->formResponse('Edit ' . $spec->service->name, $values, $errors, $this->url('mark.rest.service.edit.submit', ['id' => $id]), 'Save changes', 422);
        }

        try {
            RestAdminServices::save(RestAdminServices::validator()->validate($document));
        } catch (SpecValidationException $exception) {
            return $this->formResponse('Edit ' . $spec->service->name, $values, [$exception->getMessage()], $this->url('mark.rest.service.edit.submit', ['id' => $id]), 'Save changes', 422);
        }

        Audit::log('restadmin.service.update', $id, 'Updated REST service ' . $spec->service->name, $request->getClientIp());

        return Response::redirect($this->url('mark.rest.service', ['id' => $id]) . '?toast=' . rawurlencode('Service saved.'));
    }

    public function delete(Request $request): Response
    {
        $id = (string) $request->routeParam('id');
        $spec = Panel::service($id);

        if (null === $spec) {
            return Panel::notFound($this->router(), 'That REST service does not exist.');
        }

        RestAdminServices::forget($spec);
        Audit::log('restadmin.service.delete', $id, 'Removed REST service ' . $spec->service->name, $request->getClientIp());

        return Response::redirect($this->url('mark.rest') . '?toast=' . rawurlencode('Service removed.'));
    }

    /**
     * @param array<string, string> $values
     *
     * @return array{array<string, mixed>, list<string>}
     */
    private function document(array $values, ?ServiceSpec $existing): array
    {
        $errors = [];
        $headers = $this->headers($values['headers'], $errors);

        $service = [
            'name'            => $values['name'],
            'base_url'        => $values['base_url'],
            'auth'            => [
                'header'    => '' === $values['auth_header'] ? null : $values['auth_header'],
                'token_env' => '' === $values['token_env'] ? null : $values['token_env'],
            ],
            'timeout_seconds' => (int) $values['timeout_seconds'],
            'headers'         => $headers,
        ];

        if (null !== $existing) {
            $service['id'] = $existing->service->id;
        }

        $document = [
            'service'   => $service,
            'resources' => null === $existing
                ? []
                : array_map(static fn ($resource): array => $resource->toArray(), $existing->resources),
        ];

        return [$document, $errors];
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, string>
     */
    private function headers(string $json, array &$errors): array
    {
        $decoded = json_decode('' === trim($json) ? '{}' : $json, true);

        if (!is_array($decoded)) {
            $errors[] = 'Headers must be a JSON object, for example {"Accept": "application/json"}.';

            return [];
        }

        $headers = [];

        foreach ($decoded as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                $errors[] = 'Every header needs a name and a string value.';

                return [];
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function values(Request $request): array
    {
        return [
            'name'            => trim($request->bodyString('name')),
            'base_url'        => trim($request->bodyString('base_url')),
            'token_env'       => trim($request->bodyString('token_env')),
            'auth_header'     => trim($request->bodyString('auth_header')),
            'timeout_seconds' => trim($request->bodyString('timeout_seconds', '10')),
            'headers'         => $request->bodyString('headers', '{}'),
        ];
    }

    /**
     * @param array<string, string> $values
     * @param list<string>          $errors
     */
    private function formResponse(
        string $title,
        array $values,
        array $errors,
        string $action,
        string $submit,
        int $status,
    ): Response {
        return Response::html(
            AdminLayout::render($title, $this->form($title, $action, $values, $errors, $submit), 'rest', [], [], $this->router()),
            $status,
        );
    }

    /**
     * @param array<string, string> $values
     * @param list<string>          $errors
     */
    private function form(string $title, string $action, array $values, array $errors, string $submit): string
    {
        $fields = PanelHtml::input('name', 'Name', $values['name'] ?? '', true)
            . PanelHtml::input('base_url', 'Base URL', $values['base_url'] ?? '', true, 'url', 'Requests resolve resource paths against this URL. The transport refuses hosts outside RESTADMIN_ALLOWED_HOSTS.')
            . PanelHtml::input('token_env', 'Token environment variable', $values['token_env'] ?? '', false, 'text', 'The NAME of the variable, never the token. Leave empty for a public API.')
            . PanelHtml::input('auth_header', 'Auth header', $values['auth_header'] ?? '', false, 'text', 'Defaults to Authorization (Bearer scheme). Set X-API-Key for an API-key header.')
            . PanelHtml::input('timeout_seconds', 'Timeout (seconds)', $values['timeout_seconds'] ?? '10', false, 'number')
            . PanelHtml::textarea('headers', 'Extra headers (JSON)', $values['headers'] ?? '{}', false, 'A JSON object: {"Accept": "application/json"}.');

        return '<h1 class="m-heading">' . AdminLayout::e($title) . '</h1>'
            . '<p><a href="' . AdminLayout::e($this->url('mark.rest')) . '">Back to services</a></p>'
            . PanelHtml::errors($errors)
            . PanelHtml::csrfForm($action, $fields, $submit);
    }

    private function uniqueId(string $base): string
    {
        $id = '' === $base ? 'service' : $base;
        $candidate = $id;
        $suffix = 2;

        while (null !== RestAdminServices::repository()->find($candidate)) {
            $candidate = $id . '-' . $suffix;
            ++$suffix;
        }

        return $candidate;
    }

    private static function slug(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? ''));

        return trim($slug, '-');
    }
}
