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
use Modules\RestAdmin\Domain\Model\ActionSet;
use Modules\RestAdmin\Domain\Model\FieldSpec;
use Modules\RestAdmin\Domain\Model\FieldType;
use Modules\RestAdmin\Domain\Model\PaginationSpec;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Domain\Model\ServiceSpec;
use Modules\RestAdmin\Interfaces\HTTP\Panel;
use Modules\RestAdmin\Interfaces\HTTP\PanelHtml;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * ResourceFormAction — one collection's contract with the panel.
 *
 * `path` and `item_path` are relative to the service, `actions` is the write
 * opt-in and `pagination` names the strategy the reader uses. Fields are edited
 * as a JSON document: they are a list of small typed records, and a JSON
 * textarea keeps the whole list visible in one place instead of a repeater that
 * would need its own script. The document is decoded into `FieldSpec` objects
 * before `SpecValidator` sees it, so a bad type is refused at the form rather
 * than at the first list request.
 *
 * Relations are carried through unchanged on edit — v1 edits them through the
 * snapshot, not through this form.
 */
#[Route(path: '/mark/rest/services/{id}/resources', method: 'GET', name: 'mark.rest.resource.create', handlerMethod: 'createForm')]
#[Route(path: '/mark/rest/services/{id}/resources', method: 'POST', name: 'mark.rest.resource.create.submit', handlerMethod: 'createSubmit')]
#[Route(path: '/mark/rest/services/{id}/resources/{resource}', method: 'GET', name: 'mark.rest.resource.edit', handlerMethod: 'editForm')]
#[Route(path: '/mark/rest/services/{id}/resources/{resource}', method: 'POST', name: 'mark.rest.resource.edit.submit', handlerMethod: 'editSubmit')]
#[Route(path: '/mark/rest/services/{id}/resources/{resource}/delete', method: 'POST|DELETE', name: 'mark.rest.resource.delete', handlerMethod: 'delete')]
final class ResourceFormAction
{
    use ResolvesRouteUrls;

    public function createForm(Request $request): Response
    {
        $spec = Panel::service((string) $request->routeParam('id'));

        if (null === $spec) {
            return Panel::notFound($this->router(), 'That REST service does not exist.');
        }

        return Panel::render(
            $this->router(),
            'New resource',
            $this->form($spec, null, $this->blankValues(), [], $this->url('mark.rest.resource.create.submit', ['id' => $spec->service->id]), 'Create resource'),
        );
    }

    public function createSubmit(Request $request): Response
    {
        $spec = Panel::service((string) $request->routeParam('id'));

        if (null === $spec) {
            return Panel::notFound($this->router(), 'That REST service does not exist.');
        }

        $values = $this->values($request);
        [$document, $errors] = $this->document($spec, null, $values);

        if ([] !== $errors) {
            return $this->formResponse($spec, null, $values, $errors, $this->url('mark.rest.resource.create.submit', ['id' => $spec->service->id]), 'Create resource', 422);
        }

        try {
            RestAdminServices::save(RestAdminServices::validator()->validate($document));
        } catch (SpecValidationException $exception) {
            return $this->formResponse($spec, null, $values, [$exception->getMessage()], $this->url('mark.rest.resource.create.submit', ['id' => $spec->service->id]), 'Create resource', 422);
        }

        Audit::log('restadmin.resource.create', $spec->service->id . '/' . (string) $values['name'], 'Created resource ' . (string) $values['name'], $request->getClientIp());

        return Response::redirect($this->url('mark.rest.service', ['id' => $spec->service->id]) . '?toast=' . rawurlencode('Resource created.'));
    }

    public function editForm(Request $request): Response
    {
        $spec = Panel::service((string) $request->routeParam('id'));
        $resource = null === $spec ? null : $this->resource($spec, (string) $request->routeParam('resource'));

        if (null === $spec || null === $resource) {
            return Panel::notFound($this->router(), 'That resource does not exist.');
        }

        $values = [
            'name'       => $resource->name,
            'path'       => $resource->path,
            'item_path'  => $resource->itemPath,
            'id_field'   => $resource->idField,
            'pagination' => $resource->pagination->strategy,
            'actions'    => $resource->actions->toArray(),
            'fields'     => json_encode(array_map(static fn (FieldSpec $field): array => $field->toArray(), $resource->fields), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
        ];

        return Panel::render(
            $this->router(),
            'Edit ' . $resource->name,
            $this->form($spec, $resource, $values, [], $this->url('mark.rest.resource.edit.submit', ['id' => $spec->service->id, 'resource' => $resource->name]), 'Save resource'),
        );
    }

    public function editSubmit(Request $request): Response
    {
        $spec = Panel::service((string) $request->routeParam('id'));
        $resource = null === $spec ? null : $this->resource($spec, (string) $request->routeParam('resource'));

        if (null === $spec || null === $resource) {
            return Panel::notFound($this->router(), 'That resource does not exist.');
        }

        $values = $this->values($request);
        $values['name'] = $resource->name;
        [$document, $errors] = $this->document($spec, $resource, $values);

        if ([] !== $errors) {
            return $this->formResponse($spec, $resource, $values, $errors, $this->url('mark.rest.resource.edit.submit', ['id' => $spec->service->id, 'resource' => $resource->name]), 'Save resource', 422);
        }

        try {
            RestAdminServices::save(RestAdminServices::validator()->validate($document));
        } catch (SpecValidationException $exception) {
            return $this->formResponse($spec, $resource, $values, [$exception->getMessage()], $this->url('mark.rest.resource.edit.submit', ['id' => $spec->service->id, 'resource' => $resource->name]), 'Save resource', 422);
        }

        Audit::log('restadmin.resource.update', $spec->service->id . '/' . $resource->name, 'Updated resource ' . $resource->name, $request->getClientIp());

        return Response::redirect($this->url('mark.rest.service', ['id' => $spec->service->id]) . '?toast=' . rawurlencode('Resource saved.'));
    }

    public function delete(Request $request): Response
    {
        $spec = Panel::service((string) $request->routeParam('id'));
        $resource = null === $spec ? null : $this->resource($spec, (string) $request->routeParam('resource'));

        if (null === $spec || null === $resource) {
            return Panel::notFound($this->router(), 'That resource does not exist.');
        }

        $remaining = array_values(array_filter(
            $spec->resources,
            static fn (ResourceSpec $candidate): bool => $candidate->name !== $resource->name,
        ));

        $document = ['service' => $spec->service->toArray(), 'resources' => array_map(static fn (ResourceSpec $candidate): array => $candidate->toArray(), $remaining)];

        try {
            $updated = RestAdminServices::validator()->validate($document);
        } catch (SpecValidationException $exception) {
            return Panel::error($this->router(), 'Resource not removed', $exception->getMessage(), 422);
        }

        RestAdminServices::save($updated);
        Audit::log('restadmin.resource.delete', $spec->service->id . '/' . $resource->name, 'Removed resource ' . $resource->name, $request->getClientIp());

        return Response::redirect($this->url('mark.rest.service', ['id' => $spec->service->id]) . '?toast=' . rawurlencode('Resource removed.'));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{array<string, mixed>, list<string>}
     */
    private function document(ServiceSpec $spec, ?ResourceSpec $existing, array $values): array
    {
        $errors = [];
        $fields = $this->fields((string) $values['fields'], $errors);
        $actions = $values['actions'];

        if (!is_array($actions) || [] === $actions) {
            $actions = ['list', 'read'];
        }

        $actions = array_values(array_filter($actions, 'is_string'));

        // Both constructors own their vocabulary and throw on a word they do
        // not know. The form is a text input, so a tampered or stale POST can
        // carry one; that is the operator's mistake to see beside the field,
        // not a 500. The defaults keep the page renderable and the errors keep
        // the document from being saved.
        try {
            $actionSet = new ActionSet($actions);
        } catch (\InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
            $actionSet = new ActionSet();
        }

        try {
            $pagination = new PaginationSpec((string) $values['pagination']);
        } catch (\InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
            $pagination = new PaginationSpec();
        }

        $resource = new ResourceSpec(
            serviceId: $spec->service->id,
            name: (string) $values['name'],
            path: (string) $values['path'],
            itemPath: (string) $values['item_path'],
            idField: '' === (string) $values['id_field'] ? 'id' : (string) $values['id_field'],
            fields: $fields,
            actions: $actionSet,
            pagination: $pagination,
            relations: null === $existing ? [] : $existing->relations,
        );

        $resources = [];

        foreach ($spec->resources as $candidate) {
            if (null !== $existing && $candidate->name === $existing->name) {
                $resources[] = $resource->toArray();

                continue;
            }

            $resources[] = $candidate->toArray();
        }

        if (null === $existing) {
            $resources[] = $resource->toArray();
        }

        return [
            ['service' => $spec->service->toArray(), 'resources' => $resources],
            $errors,
        ];
    }

    /**
     * @param list<string> $errors
     *
     * @return list<FieldSpec>
     */
    private function fields(string $json, array &$errors): array
    {
        $decoded = json_decode('' === trim($json) ? '[]' : $json, true);

        if (!is_array($decoded)) {
            $errors[] = 'Fields must be a JSON list.';

            return [];
        }

        $fields = [];

        foreach ($decoded as $entry) {
            if (!is_array($entry) || !isset($entry['name'], $entry['label'])) {
                $errors[] = 'Every field needs at least a name and a label.';

                return [];
            }

            $type = FieldType::tryFrom((string) ($entry['type'] ?? 'text'));

            if (null === $type) {
                $errors[] = sprintf('Field "%s" has an unknown type "%s".', (string) $entry['name'], (string) ($entry['type'] ?? ''));

                return [];
            }

            $fields[] = new FieldSpec(
                name: (string) $entry['name'],
                label: (string) $entry['label'],
                type: $type,
                required: true === ($entry['required'] ?? false),
                readOnly: true === ($entry['read_only'] ?? false),
                inList: false !== ($entry['in_list'] ?? true),
            );
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Request $request): array
    {
        return [
            'name'       => trim($request->bodyString('name')),
            'path'       => trim($request->bodyString('path')),
            'item_path'  => trim($request->bodyString('item_path')),
            'id_field'   => trim($request->bodyString('id_field', 'id')),
            'pagination' => $request->bodyString('pagination', 'page'),
            'actions'    => $request->body('actions', ['list', 'read']),
            'fields'     => $request->bodyString('fields', '[]'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blankValues(): array
    {
        return [
            'name'       => '',
            'path'       => '',
            'item_path'  => '',
            'id_field'   => 'id',
            'pagination' => 'page',
            'actions'    => ['list', 'read'],
            'fields'     => "[\n  {\"name\": \"id\", \"label\": \"ID\", \"type\": \"text\", \"in_list\": true}\n]",
        ];
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

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $errors
     */
    private function formResponse(
        ServiceSpec $spec,
        ?ResourceSpec $resource,
        array $values,
        array $errors,
        string $action,
        string $submit,
        int $status,
    ): Response {
        return Response::html(
            AdminLayout::render($resource->name ?? 'New resource', $this->form($spec, $resource, $values, $errors, $action, $submit), 'rest', [], [], $this->router()),
            $status,
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $errors
     */
    private function form(
        ServiceSpec $spec,
        ?ResourceSpec $resource,
        array $values,
        array $errors,
        string $action,
        string $submit,
    ): string {
        $actions = is_array($values['actions']) ? $values['actions'] : [];
        $checks = '';

        foreach (['list' => 'list', 'read' => 'read', 'create' => 'create', 'update' => 'update', 'delete' => 'delete'] as $value => $label) {
            $id = 'action-' . $value;
            $checks .= '<label class="m-check" for="' . $id . '"><input id="' . $id . '" type="checkbox" name="actions[]" value="'
                . $value . '"' . (in_array($value, $actions, true) ? ' checked' : '') . '> ' . $label . '</label>';
        }

        $nameField = null === $resource
            ? PanelHtml::input('name', 'Resource name', (string) $values['name'], true, 'text', 'The name the panel addresses this collection by, e.g. posts.')
            : PanelHtml::input('name', 'Resource name', $resource->name, false, 'text', 'A resource\'s name is its identity; create a new resource to rename it.');

        $fields = $nameField
            . PanelHtml::input('path', 'Collection path', (string) $values['path'], true, 'text', 'Relative to the service base URL, e.g. /posts.')
            . PanelHtml::input('item_path', 'Item path', (string) $values['item_path'], true, 'text', 'Must contain {id}, e.g. /posts/{id}.')
            . PanelHtml::input('id_field', 'Id field', (string) $values['id_field'], false, 'text', 'The API key the item path fills {id} from.')
            . PanelHtml::select('pagination', 'Pagination', ['page' => 'page (?page=, meta)', 'link' => 'link (Link header)', 'cursor' => 'cursor (next cursor)', 'none' => 'none (whole collection)'], (string) $values['pagination'])
            . '<fieldset class="m-field"><legend class="m-field__label">Actions</legend>' . $checks
            . '<p class="m-field__help">Read-only unless create, update or delete is checked.</p></fieldset>'
            . PanelHtml::textarea('fields', 'Fields (JSON)', (string) $values['fields'], false, 'name, label and type (text, textarea, number, checkbox, select, date, json, relation), plus required, read_only and in_list.', 12);

        return '<h1 class="m-heading">' . AdminLayout::e($resource->name ?? 'New resource') . '</h1>'
            . '<p><a href="' . AdminLayout::e($this->url('mark.rest.service', ['id' => $spec->service->id])) . '">Back to ' . AdminLayout::e($spec->service->name) . '</a></p>'
            . PanelHtml::errors($errors)
            . PanelHtml::csrfForm($action, $fields, $submit);
    }
}
