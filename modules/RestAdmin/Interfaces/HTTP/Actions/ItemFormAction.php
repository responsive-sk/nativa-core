<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\RestAdmin\Application\Mapping\ApiError;
use Modules\RestAdmin\Application\Mapping\ApiException;
use Modules\RestAdmin\Application\Mapping\FieldMapper;
use Modules\RestAdmin\Application\Mapping\MappingException;
use Modules\RestAdmin\Application\Services\Audit;
use Modules\RestAdmin\Domain\Model\FieldSpec;
use Modules\RestAdmin\Domain\Model\FieldType;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Domain\Model\ServiceSpec;
use Modules\RestAdmin\Interfaces\HTTP\FieldControl;
use Modules\RestAdmin\Interfaces\HTTP\Panel;
use Modules\RestAdmin\Interfaces\HTTP\PanelHtml;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * ItemFormAction — the one panel path that writes an item upstream.
 *
 * The form's fields come from the resource's `FieldSpec` list, so the panel
 * never invents a key: a field the spec does not name cannot be posted, and a
 * `read_only` field is not sent back even if a browser submits it.
 *
 * A 422 is not a page failure. `ErrorMapper` puts the service's messages under
 * the API's field names, the form re-renders with those fields marked, and the
 * operator's input is kept — retyping a form because the title was one character
 * short is how a panel loses trust. Any other non-2xx is the service refusing
 * the whole operation and gets the error page, not a field beside a guess.
 */
#[Route(path: '/mark/rest/services/{id}/resources/{resource}/items/create', method: 'GET', name: 'mark.rest.item.create', handlerMethod: 'createForm')]
#[Route(path: '/mark/rest/services/{id}/resources/{resource}/items/create', method: 'POST', name: 'mark.rest.item.create.submit', handlerMethod: 'createSubmit')]
#[Route(path: '/mark/rest/services/{id}/resources/{resource}/items/{item}/edit', method: 'GET', name: 'mark.rest.item.edit', handlerMethod: 'editForm')]
#[Route(path: '/mark/rest/services/{id}/resources/{resource}/items/{item}/edit', method: 'POST', name: 'mark.rest.item.edit.submit', handlerMethod: 'editSubmit')]
final class ItemFormAction
{
    use ResolvesRouteUrls;

    public function createForm(Request $request): Response
    {
        $spec = Panel::service((string) $request->routeParam('id'));
        $resource = null === $spec ? null : $this->resource($spec, (string) $request->routeParam('resource'));

        if (null === $spec || null === $resource) {
            return Panel::notFound($this->router(), 'That resource does not exist.');
        }

        if (!$resource->actions->allows('create')) {
            return Panel::error($this->router(), 'Creating is not allowed', 'This resource is read-only. Add "create" to its actions to allow it.', 403);
        }

        return Panel::render(
            $this->router(),
            'New ' . $resource->name,
            $this->form($spec, $resource, null, [], [], [], $this->url('mark.rest.item.create.submit', ['id' => $spec->service->id, 'resource' => $resource->name]), 'Create item'),
        );
    }

    public function createSubmit(Request $request): Response
    {
        $spec = Panel::service((string) $request->routeParam('id'));
        $resource = null === $spec ? null : $this->resource($spec, (string) $request->routeParam('resource'));

        if (null === $spec || null === $resource) {
            return Panel::notFound($this->router(), 'That resource does not exist.');
        }

        if (!$resource->actions->allows('create')) {
            return Panel::error($this->router(), 'Creating is not allowed', 'This resource is read-only. Add "create" to its actions to allow it.', 403);
        }

        [$payload, $errors] = $this->payload($resource, $request);

        if ([] !== $errors) {
            return $this->formResponse($spec, $resource, null, $request, $errors, [], $this->url('mark.rest.item.create.submit', ['id' => $spec->service->id, 'resource' => $resource->name]), 'Create item', 422);
        }

        try {
            $response = RestAdminServices::transport()->send(
                (new \Modules\RestAdmin\Application\Mapping\RequestFactory())->create($spec->service, $resource, $payload),
            );
        } catch (\Throwable $exception) {
            return Panel::error($this->router(), 'The request could not be sent', $exception->getMessage());
        }

        if (!$response->isSuccess()) {
            $error = (new \Modules\RestAdmin\Application\Mapping\ErrorMapper())->map($response);

            if ($error->isValidation()) {
                return $this->formResponse($spec, $resource, null, $request, $this->messages($error), $error->fieldErrors, $this->url('mark.rest.item.create.submit', ['id' => $spec->service->id, 'resource' => $resource->name]), 'Create item', 422);
            }

            return Panel::error($this->router(), 'The service refused the write', $error->message);
        }

        Audit::log('restadmin.item.create', $spec->service->id . '/' . $resource->name, 'Created an item through the panel', $request->getClientIp());

        return Response::redirect($this->url('mark.rest.items', ['id' => $spec->service->id, 'resource' => $resource->name]) . '?toast=' . rawurlencode('Item created.'));
    }

    public function editForm(Request $request): Response
    {
        $spec = Panel::service((string) $request->routeParam('id'));
        $resource = null === $spec ? null : $this->resource($spec, (string) $request->routeParam('resource'));

        if (null === $spec || null === $resource) {
            return Panel::notFound($this->router(), 'That resource does not exist.');
        }

        if (!$resource->actions->allows('update')) {
            return Panel::error($this->router(), 'Updating is not allowed', 'This resource does not allow updates. Add "update" to its actions to allow them.', 403);
        }

        $id = (string) $request->routeParam('item');

        try {
            $item = RestAdminServices::itemReader()->read($spec->service, $resource, $id);
        } catch (ApiException $exception) {
            return 'not_found' === $exception->error->kind
                ? Panel::notFound($this->router(), $exception->error->message)
                : Panel::error($this->router(), 'The service refused the request', $exception->error->message);
        } catch (MappingException $exception) {
            return Panel::error($this->router(), 'The answer is not an item', $exception->getMessage());
        }

        $values = [];
        $mapper = new FieldMapper();

        foreach ($resource->fields as $field) {
            $values[$field->name] = $mapper->has($item, $field->name) ? $mapper->read($item, $field->name) : null;
        }

        return Panel::render(
            $this->router(),
            'Edit ' . $resource->name . ' ' . $id,
            $this->form($spec, $resource, $id, [], $values, [], $this->url('mark.rest.item.edit.submit', ['id' => $spec->service->id, 'resource' => $resource->name, 'item' => $id]), 'Save changes'),
        );
    }

    public function editSubmit(Request $request): Response
    {
        $spec = Panel::service((string) $request->routeParam('id'));
        $resource = null === $spec ? null : $this->resource($spec, (string) $request->routeParam('resource'));

        if (null === $spec || null === $resource) {
            return Panel::notFound($this->router(), 'That resource does not exist.');
        }

        if (!$resource->actions->allows('update')) {
            return Panel::error($this->router(), 'Updating is not allowed', 'This resource does not allow updates. Add "update" to its actions to allow them.', 403);
        }

        $id = (string) $request->routeParam('item');
        [$payload, $errors] = $this->payload($resource, $request);

        if ([] !== $errors) {
            return $this->formResponse($spec, $resource, $id, $request, $errors, [], $this->url('mark.rest.item.edit.submit', ['id' => $spec->service->id, 'resource' => $resource->name, 'item' => $id]), 'Save changes', 422);
        }

        try {
            $response = RestAdminServices::transport()->send(
                (new \Modules\RestAdmin\Application\Mapping\RequestFactory())->update($spec->service, $resource, $id, $payload),
            );
        } catch (\Throwable $exception) {
            return Panel::error($this->router(), 'The request could not be sent', $exception->getMessage());
        }

        if (!$response->isSuccess()) {
            $error = (new \Modules\RestAdmin\Application\Mapping\ErrorMapper())->map($response);

            if ($error->isValidation()) {
                return $this->formResponse($spec, $resource, $id, $request, $this->messages($error), $error->fieldErrors, $this->url('mark.rest.item.edit.submit', ['id' => $spec->service->id, 'resource' => $resource->name, 'item' => $id]), 'Save changes', 422);
            }

            return Panel::error($this->router(), 'The service refused the write', $error->message);
        }

        Audit::log('restadmin.item.update', $spec->service->id . '/' . $resource->name, 'Updated item ' . $id . ' through the panel', $request->getClientIp());

        return Response::redirect($this->url('mark.rest.items', ['id' => $spec->service->id, 'resource' => $resource->name]) . '?toast=' . rawurlencode('Item saved.'));
    }

    /**
     * The submitted values, typed by the field's declaration.
     *
     * @return array{array<string, mixed>, list<string>}
     */
    private function payload(ResourceSpec $resource, Request $request): array
    {
        $payload = [];
        $errors = [];

        foreach ($resource->fields as $field) {
            if ($field->readOnly) {
                continue;
            }

            $raw = $request->body($field->name);
            $value = $this->value($field, $raw, $errors);

            if ($field->required && $this->missing($value, $field->type)) {
                $errors[] = $field->label . ' is required.';

                continue;
            }

            self::setPath($payload, $field->name, $value);
        }

        return [$payload, $errors];
    }

    /**
     * @param list<string> $errors
     */
    private function value(FieldSpec $field, mixed $raw, array &$errors): mixed
    {
        return match ($field->type) {
            FieldType::Checkbox => FieldControl::truthy($raw),
            FieldType::Number => $this->number($raw, $field, $errors),
            FieldType::Json => $this->json($raw, $field, $errors),
            default => is_scalar($raw) ? trim((string) $raw) : '',
        };
    }

    /**
     * @param list<string> $errors
     */
    private function number(mixed $raw, FieldSpec $field, array &$errors): int|float|null
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        if (!is_numeric($raw)) {
            $errors[] = $field->label . ' must be a number.';

            return null;
        }

        return str_contains((string) $raw, '.') ? (float) $raw : (int) $raw;
    }

    /**
     * @param list<string> $errors
     */
    private function json(mixed $raw, FieldSpec $field, array &$errors): mixed
    {
        if (null === $raw || '' === trim((string) $raw)) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            $errors[] = $field->label . ' must be valid JSON.';

            return null;
        }

        return $decoded;
    }

    private function missing(mixed $value, FieldType $type): bool
    {
        if (FieldType::Checkbox === $type) {
            return false;
        }

        return null === $value || '' === $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function setPath(array &$payload, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $cursor = &$payload;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;

                break;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }

    /**
     * @return list<string>
     */
    private function messages(ApiError $error): array
    {
        $messages = [];

        foreach ($error->fieldErrors as $fieldMessages) {
            foreach ($fieldMessages as $message) {
                $messages[] = $message;
            }
        }

        return [] === $messages ? [$error->message] : $messages;
    }

    /**
     * @param array<string, list<string>> $fieldErrors API field => messages
     * @param list<string>                $errors
     */
    private function formResponse(
        ServiceSpec $spec,
        ResourceSpec $resource,
        ?string $id,
        Request $request,
        array $errors,
        array $fieldErrors,
        string $action,
        string $submit,
        int $status,
    ): Response {
        $values = [];

        foreach ($resource->fields as $field) {
            $values[$field->name] = $request->body($field->name);
        }

        return Response::html(
            AdminLayout::render(
                'New ' . $resource->name,
                $this->form($spec, $resource, $id, $errors, $values, $fieldErrors, $action, $submit),
                'rest',
                [],
                [],
                $this->router(),
            ),
            $status,
        );
    }

    /**
     * @param list<string>                $errors
     * @param array<string, mixed>        $values
     * @param array<string, list<string>> $fieldErrors
     */
    private function form(
        ServiceSpec $spec,
        ResourceSpec $resource,
        ?string $id,
        array $errors,
        array $values,
        array $fieldErrors,
        string $action,
        string $submit,
    ): string {
        $controls = '';

        foreach ($resource->fields as $field) {
            $fieldError = implode(' ', $fieldErrors[$field->name] ?? []);
            $controls .= FieldControl::render($field, $values[$field->name] ?? null, $fieldError);
        }

        $title = null === $id ? 'New ' . $resource->name : 'Edit ' . $resource->name . ' ' . $id;
        $back = $this->url('mark.rest.items', ['id' => $spec->service->id, 'resource' => $resource->name]);

        return '<h1 class="m-heading">' . AdminLayout::e($title) . '</h1>'
            . '<p><a href="' . AdminLayout::e($back) . '">Back to the collection</a></p>'
            . PanelHtml::errors($errors)
            . PanelHtml::fieldErrors($fieldErrors)
            . PanelHtml::csrfForm($action, $controls, $submit);
    }

    private function resource(ServiceSpec $spec, string $name): ?ResourceSpec
    {
        foreach ($spec->resources as $resource) {
            if ($resource->name === $name) {
                return $resource;
            }
        }

        return null;
    }
}
