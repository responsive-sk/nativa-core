<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Spec;

use Modules\RestAdmin\Domain\Model\ActionSet;
use Modules\RestAdmin\Domain\Model\AuthSpec;
use Modules\RestAdmin\Domain\Model\FieldSpec;
use Modules\RestAdmin\Domain\Model\FieldType;
use Modules\RestAdmin\Domain\Model\PaginationSpec;
use Modules\RestAdmin\Domain\Model\RelationSpec;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Domain\Model\ServiceConfig;
use Modules\RestAdmin\Domain\Model\ServiceSpec;

/**
 * SpecValidator — the only way a document becomes a `ServiceSpec`.
 *
 * A stored specification is interpreted by the panel and, later, by the MCP
 * server, so a key nobody reads is worse than a missing one: the operator
 * believes they configured something and nothing reports the typo. That is why
 * unknown keys are refused at every level instead of ignored, and why every
 * message names the field.
 *
 * ## What is checked, and the failure each rule prevents
 *
 * - **Unknown keys** — a silently ignored `timout_seconds` is a ten-second
 *   default nobody chose.
 * - **The service id is a file name** — the JSON snapshot is
 *   `<id>.json`, so the id is lower-case letters, digits, `-` and `_`; a
 *   `../` would write outside the directory.
 * - **The base URL is absolute http(s), credential-free, query-free** — the
 *   token travels as a header (D5), and a query in a base URL would be
 *   appended to every path.
 * - **Resource paths are relative and placeholder-free** — a resource path
 *   that is a full URL would leave the service, and `{slug}` has nothing to
 *   fill it: only `{id}` is resolved, from `id_field`.
 * - **Actions are the closed vocabulary** — a write action is an explicit
 *   operator decision (D6), so an unknown word must not be stored as a
 *   harmless-looking extra.
 * - **Fields have a known type and a unique name** — two columns called
 *   `title` would both render and one would win on save.
 * - **Relations point at a defined resource and do not cycle** — v1 resolves
 *   them recursively, so `posts → authors → posts` has no end.
 *
 * Types are strict (`true`, not `'on'`); parsing a form is the action layer's
 * job in F4. A JSON document — what `SpecStore` writes and the MCP tools send —
 * already has the right types.
 */
final class SpecValidator
{
    /**
     * @throws SpecValidationException
     */
    public function validate(mixed $document): ServiceSpec
    {
        if (!is_array($document) || array_is_list($document)) {
            throw new SpecValidationException('document', 'a specification must be an object with "service" and "resources".');
        }

        $this->rejectUnknownKeys($document, ['service', 'resources'], 'document');

        $service = $document['service'] ?? null;

        if (!is_array($service) || array_is_list($service)) {
            throw new SpecValidationException('service', 'a service object is required.');
        }

        $this->rejectUnknownKeys($service, ['id', 'name', 'base_url', 'auth', 'timeout_seconds', 'headers'], 'service');

        $id = $this->requiredString($service, 'id', 'service');

        if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $id)) {
            throw new SpecValidationException('service.id', 'is a snapshot file name: lower-case letters, digits, "-" or "_", at most 32 characters.');
        }

        $name = $this->requiredString($service, 'name', 'service');

        if (strlen($name) > 100) {
            throw new SpecValidationException('service.name', 'is longer than the 100 characters the store keeps.');
        }

        $baseUrl = $this->baseUrl($this->requiredString($service, 'base_url', 'service'));
        $auth = $this->auth($service['auth'] ?? []);
        $timeout = $service['timeout_seconds'] ?? 10;

        if (!is_int($timeout) || $timeout < 1 || $timeout > 120) {
            throw new SpecValidationException('service.timeout_seconds', 'must be a whole number of seconds between 1 and 120.');
        }

        $headers = $this->headers($service['headers'] ?? []);

        $resources = $document['resources'] ?? [];

        if (!is_array($resources) || !array_is_list($resources)) {
            throw new SpecValidationException('resources', 'must be a list of resource objects.');
        }

        $names = [];
        $built = [];

        foreach ($resources as $index => $resource) {
            $prefix = 'resources[' . $index . ']';
            $built[] = $this->resource($resource, $index, $prefix, $id, $names);
        }

        $spec = new ServiceSpec(
            new ServiceConfig(
                id: $id,
                name: $name,
                baseUrl: $baseUrl,
                auth: $auth,
                timeoutSeconds: $timeout,
                headers: $headers,
            ),
            $built,
        );

        $this->assertRelationTargets($spec, $names);
        $this->assertAcyclic($spec);

        return $spec;
    }

    /**
     * @param array<array-key, mixed> $resource
     * @param array<string, true>     $names    lower-cased resource names
     */
    private function resource(mixed $resource, int $index, string $prefix, string $serviceId, array &$names): ResourceSpec
    {
        if (!is_array($resource) || ([] !== $resource && array_is_list($resource))) {
            throw new SpecValidationException($prefix, 'must be a resource object.');
        }

        $this->rejectUnknownKeys(
            $resource,
            ['name', 'path', 'item_path', 'id_field', 'actions', 'pagination', 'fields', 'relations'],
            $prefix,
        );

        $name = $this->requiredString($resource, 'name', $prefix);

        if (strlen($name) > 100) {
            throw new SpecValidationException($prefix . '.name', 'is longer than the 100 characters the store keeps.');
        }

        if (isset($names[strtolower($name)])) {
            throw new SpecValidationException($prefix . '.name', sprintf(
                'duplicates "%s"; resource names address relation targets and must be unique.',
                $name,
            ));
        }

        $names[strtolower($name)] = true;

        $path = $this->resourcePath($this->requiredString($resource, 'path', $prefix), $prefix . '.path', false);
        $itemPath = $this->resourcePath($this->requiredString($resource, 'item_path', $prefix), $prefix . '.item_path', true);

        // `id_field` is optional: nearly every REST API calls its key `id`,
        // and the resource model already defaults to it. A spec that names it
        // still has to name a real key.
        $idField = $resource['id_field'] ?? 'id';

        if (!is_string($idField) || '' === trim($idField)) {
            throw new SpecValidationException($prefix . '.id_field', 'must be a non-empty string when it is present.');
        }

        $idField = trim($idField);

        if (strlen($idField) > 100) {
            throw new SpecValidationException($prefix . '.id_field', 'is longer than the 100 characters the store keeps.');
        }

        return new ResourceSpec(
            serviceId: $serviceId,
            name: $name,
            path: $path,
            itemPath: $itemPath,
            idField: $idField,
            fields: $this->fields($resource['fields'] ?? [], $prefix),
            actions: $this->actions($resource['actions'] ?? ['list', 'read'], $prefix),
            pagination: $this->pagination($resource['pagination'] ?? null, $prefix),
            relations: $this->relations($resource['relations'] ?? [], $prefix),
        );
    }

    private function baseUrl(string $url): string
    {
        if (strlen($url) > 2048) {
            throw new SpecValidationException('service.base_url', 'is longer than the 2048 characters the store keeps.');
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            throw new SpecValidationException('service.base_url', 'is not a URL.');
        }

        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new SpecValidationException('service.base_url', 'must be an absolute http or https URL.');
        }

        if ('' === ($parts['host'] ?? '')) {
            throw new SpecValidationException('service.base_url', 'must name a host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SpecValidationException('service.base_url', 'must not carry credentials; auth names the env variable that holds the token.');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new SpecValidationException('service.base_url', 'must not carry a query or fragment; resource paths say where each request goes.');
        }

        return rtrim($url, '/');
    }

    private function auth(mixed $auth): AuthSpec
    {
        if (!is_array($auth) || ([] !== $auth && array_is_list($auth))) {
            throw new SpecValidationException('service.auth', 'must be an object with "header" and "token_env".');
        }

        $this->rejectUnknownKeys($auth, ['header', 'token_env'], 'service.auth');

        $header = $this->nullableString($auth, 'header', 'service.auth');
        $tokenEnv = $this->nullableString($auth, 'token_env', 'service.auth');

        if (null === $tokenEnv) {
            if (null !== $header) {
                throw new SpecValidationException('service.auth.header', 'names a header but no token_env holds its value.');
            }

            return new AuthSpec();
        }

        if (1 !== preg_match('/^[A-Z][A-Z0-9_]*$/', $tokenEnv)) {
            throw new SpecValidationException('service.auth.token_env', 'must be an environment variable NAME (upper-case letters, digits, "_"), never a token value.');
        }

        $header ??= 'Authorization';

        if (1 !== preg_match('/^[A-Za-z0-9-]{1,64}$/', $header)) {
            throw new SpecValidationException('service.auth.header', 'must be an HTTP header name.');
        }

        return new AuthSpec($header, $tokenEnv);
    }

    /**
     * @return array<string, string>
     */
    private function headers(mixed $headers): array
    {
        if (!is_array($headers) || ([] !== $headers && array_is_list($headers))) {
            throw new SpecValidationException('service.headers', 'must be an object of header name => value.');
        }

        if (count($headers) > 20) {
            throw new SpecValidationException('service.headers', 'may not carry more than 20 headers.');
        }

        $normalised = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name) || 1 !== preg_match('/^[A-Za-z0-9-]{1,64}$/', $name)) {
                throw new SpecValidationException('service.headers', 'every header name must be letters, digits or "-", at most 64 characters.');
            }

            if (!is_string($value) || strlen($value) > 500 || 1 === preg_match('/[\r\n]/', $value)) {
                throw new SpecValidationException('service.headers.' . $name, 'must be a single-line string of at most 500 characters.');
            }

            $normalised[$name] = $value;
        }

        return $normalised;
    }

    private function resourcePath(string $path, string $field, bool $item): string
    {
        if (strlen($path) > 2048) {
            throw new SpecValidationException($field, 'is longer than the 2048 characters the store keeps.');
        }

        if (!str_starts_with($path, '/')) {
            throw new SpecValidationException($field, 'must start with "/" and stay relative to the service base URL.');
        }

        if (str_contains($path, '//') || str_contains($path, '..') || str_contains($path, '?') || str_contains($path, '#') || str_contains($path, ':')) {
            throw new SpecValidationException($field, 'must be a plain path: no scheme, "..", "//", query or fragment.');
        }

        if (1 === preg_match('/\s/', $path)) {
            throw new SpecValidationException($field, 'must not contain whitespace.');
        }

        if ($item) {
            if (1 !== substr_count($path, '{id}')) {
                throw new SpecValidationException($field, 'must contain exactly one {id} placeholder, filled from id_field.');
            }
        }

        $withoutId = str_replace('{id}', '', $path);

        if (1 === preg_match('/\{([^}]*)\}/', $withoutId, $matches)) {
            throw new SpecValidationException($field, sprintf(
                'has an unresolved placeholder {%s}; only {id} is filled, from id_field.',
                $matches[1],
            ));
        }

        if (str_contains($withoutId, '{') || str_contains($withoutId, '}')) {
            throw new SpecValidationException($field, 'has a malformed placeholder; only {id} is filled, from id_field.');
        }

        return $path;
    }

    private function actions(mixed $actions, string $prefix): ActionSet
    {
        if (!is_array($actions) || !array_is_list($actions)) {
            throw new SpecValidationException($prefix . '.actions', 'must be a list of actions.');
        }

        try {
            return new ActionSet($actions);
        } catch (\InvalidArgumentException $exception) {
            throw new SpecValidationException($prefix . '.actions', $exception->getMessage());
        }
    }

    private function pagination(mixed $strategy, string $prefix): PaginationSpec
    {
        if (null === $strategy) {
            // The model owns the default; spelling it here as well would be a
            // second owner for the same word.
            return new PaginationSpec();
        }

        if (!is_string($strategy)) {
            throw new SpecValidationException($prefix . '.pagination', 'must be one of page, link, cursor or none.');
        }

        try {
            return new PaginationSpec($strategy);
        } catch (\InvalidArgumentException $exception) {
            throw new SpecValidationException($prefix . '.pagination', $exception->getMessage());
        }
    }

    /**
     * @return list<FieldSpec>
     */
    private function fields(mixed $fields, string $prefix): array
    {
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new SpecValidationException($prefix . '.fields', 'must be a list of field objects.');
        }

        $built = [];
        $seen = [];

        foreach ($fields as $index => $field) {
            $fieldPrefix = $prefix . '.fields[' . $index . ']';

            if (!is_array($field) || ([] !== $field && array_is_list($field))) {
                throw new SpecValidationException($fieldPrefix, 'must be a field object.');
            }

            $this->rejectUnknownKeys($field, ['name', 'label', 'type', 'required', 'read_only', 'in_list'], $fieldPrefix);

            $name = $this->requiredString($field, 'name', $fieldPrefix);

            if (strlen($name) > 255) {
                throw new SpecValidationException($fieldPrefix . '.name', 'is longer than the 255 characters the store keeps.');
            }

            if (isset($seen[$name])) {
                throw new SpecValidationException($fieldPrefix . '.name', sprintf('duplicates "%s" in this resource.', $name));
            }

            $seen[$name] = true;

            $label = $this->requiredString($field, 'label', $fieldPrefix);

            if (strlen($label) > 255) {
                throw new SpecValidationException($fieldPrefix . '.label', 'is longer than the 255 characters the store keeps.');
            }

            $type = FieldType::tryFrom($this->requiredString($field, 'type', $fieldPrefix));

            if (null === $type) {
                throw new SpecValidationException($fieldPrefix . '.type', 'is not a field type this panel renders.');
            }

            $required = $this->boolean($field, 'required', false, $fieldPrefix);
            $readOnly = $this->boolean($field, 'read_only', false, $fieldPrefix);

            if ($required && $readOnly) {
                throw new SpecValidationException($fieldPrefix . '.required', 'cannot be true while read_only is true: a read-only field is never submitted.');
            }

            $built[] = new FieldSpec(
                name: $name,
                label: $label,
                type: $type,
                required: $required,
                readOnly: $readOnly,
                inList: $this->boolean($field, 'in_list', true, $fieldPrefix),
            );
        }

        return $built;
    }

    /**
     * @return list<RelationSpec>
     */
    private function relations(mixed $relations, string $prefix): array
    {
        if (!is_array($relations) || !array_is_list($relations)) {
            throw new SpecValidationException($prefix . '.relations', 'must be a list of relation objects.');
        }

        $built = [];
        $seen = [];

        foreach ($relations as $index => $relation) {
            $relationPrefix = $prefix . '.relations[' . $index . ']';

            if (!is_array($relation) || ([] !== $relation && array_is_list($relation))) {
                throw new SpecValidationException($relationPrefix, 'must be a relation object.');
            }

            $this->rejectUnknownKeys($relation, ['name', 'kind', 'target', 'foreign_key'], $relationPrefix);

            $name = $this->requiredString($relation, 'name', $relationPrefix);

            if (isset($seen[$name])) {
                throw new SpecValidationException($relationPrefix . '.name', sprintf('duplicates "%s" in this resource.', $name));
            }

            $seen[$name] = true;

            try {
                $built[] = new RelationSpec(
                    name: $name,
                    kind: $this->requiredString($relation, 'kind', $relationPrefix),
                    target: $this->requiredString($relation, 'target', $relationPrefix),
                    foreignKey: $this->nullableString($relation, 'foreign_key', $relationPrefix),
                );
            } catch (\InvalidArgumentException $exception) {
                throw new SpecValidationException($relationPrefix . '.kind', $exception->getMessage());
            }
        }

        return $built;
    }

    /**
     * @param array<string, true> $names lower-cased resource names
     */
    private function assertRelationTargets(ServiceSpec $spec, array $names): void
    {
        foreach ($spec->resources as $resourceIndex => $resource) {
            foreach ($resource->relations as $relationIndex => $relation) {
                if (!isset($names[strtolower($relation->target)])) {
                    throw new SpecValidationException(
                        sprintf('resources[%d].relations[%d].target', $resourceIndex, $relationIndex),
                        sprintf('points at "%s", which this service does not define.', $relation->target),
                    );
                }
            }
        }
    }

    /**
     * v1 resolves nested relations recursively, so the graph must be acyclic.
     * A self-reference is the shortest cycle and is refused too: a `parent`
     * relation on the same resource needs a depth rule this version does not
     * have.
     */
    private function assertAcyclic(ServiceSpec $spec): void
    {
        $edges = [];

        foreach ($spec->resources as $resource) {
            $targets = [];

            foreach ($resource->relations as $relation) {
                $targets[] = strtolower($relation->target);
            }

            $edges[strtolower($resource->name)] = $targets;
        }

        $state = [];

        foreach (array_keys($edges) as $node) {
            $this->walk($node, [], $edges, $state);
        }
    }

    /**
     * @param list<string>              $path
     * @param array<string, list<string>> $edges
     * @param array<string, int>        $state 1 = visiting, 2 = done
     */
    private function walk(string $node, array $path, array $edges, array &$state): void
    {
        if (2 === ($state[$node] ?? 0)) {
            return;
        }

        if (1 === ($state[$node] ?? 0)) {
            $start = array_search($node, $path, true);
            $cycle = array_slice($path, false === $start ? 0 : $start);
            $cycle[] = $node;

            throw new SpecValidationException('resources', sprintf(
                'relations form a cycle (%s); v1 resolves nested relations recursively, so it must end.',
                implode(' → ', $cycle),
            ));
        }

        $state[$node] = 1;
        $path[] = $node;

        foreach ($edges[$node] ?? [] as $target) {
            $this->walk($target, $path, $edges, $state);
        }

        $state[$node] = 2;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function requiredString(array $data, string $key, string $prefix): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || '' === trim($value)) {
            throw new SpecValidationException($prefix . '.' . $key, 'is required and must be a non-empty string.');
        }

        return trim($value);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function nullableString(array $data, string $key, string $prefix): ?string
    {
        $value = $data[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!is_string($value) || '' === trim($value)) {
            throw new SpecValidationException($prefix . '.' . $key, 'must be a non-empty string when it is present.');
        }

        return trim($value);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function boolean(array $data, string $key, bool $default, string $prefix): bool
    {
        $value = $data[$key] ?? $default;

        if (!is_bool($value)) {
            throw new SpecValidationException($prefix . '.' . $key, 'must be true or false, not a word or a number.');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>            $allowed
     */
    private function rejectUnknownKeys(array $value, array $allowed, string $prefix): void
    {
        foreach (array_keys($value) as $key) {
            if (is_string($key) && in_array($key, $allowed, true)) {
                continue;
            }

            $path = '' === $prefix ? (string) $key : $prefix . '.' . (string) $key;

            throw new SpecValidationException($path, 'is not a key this specification knows; a typo that is ignored is a field the operator believes they configured.');
        }
    }
}
