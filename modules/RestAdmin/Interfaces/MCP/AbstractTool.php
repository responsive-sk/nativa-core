<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\MCP;

use Modules\RestAdmin\Domain\Model\FieldSpec;
use Modules\RestAdmin\Domain\Model\FieldType;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Domain\Model\ServiceSpec;
use Modules\RestAdmin\Interfaces\HTTP\RestAdminServices;

/**
 * AbstractTool — argument reading and the two lookups every tool needs.
 *
 * Arguments are read by name with a type check, and a missing required argument
 * names the argument rather than becoming `null` three frames later. The
 * descriptions are the only documentation a model sees, so they say what the
 * operation does to the specification, not just its name.
 */
abstract class AbstractTool implements Tool
{
    /**
     * A service by the id the operator gave it, or a clear refusal.
     *
     * @param array<string, mixed> $arguments
     */
    protected function service(array $arguments): ServiceSpec
    {
        $id = $this->requireString($arguments, 'service_id');
        $spec = RestAdminServices::repository()->find($id);

        if (null === $spec) {
            throw new \InvalidArgumentException(sprintf(
                'No REST service with id "%s" is registered. Use list_services to see the ids.',
                $id,
            ));
        }

        return $spec;
    }

    protected function resource(ServiceSpec $spec, string $name): ResourceSpec
    {
        foreach ($spec->resources as $resource) {
            if ($resource->name === $name) {
                return $resource;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Service "%s" has no resource "%s". Use list_resources to see the names.',
            $spec->service->id,
            $name,
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function serviceId(array $arguments): string
    {
        return $this->requireString($arguments, 'service_id');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function requireString(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;

        if (!is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(sprintf('"%s" is required and must be a non-empty string.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function optionalString(array $arguments, string $key, ?string $default = null): ?string
    {
        $value = $arguments[$key] ?? null;

        if (null === $value) {
            return $default;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('"%s" must be a string when present.', $key));
        }

        $value = trim($value);

        return '' === $value ? $default : $value;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function optionalInt(array $arguments, string $key, int $default): int
    {
        $value = $arguments[$key] ?? null;

        if (null === $value) {
            return $default;
        }

        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException(sprintf('"%s" must be an integer when present.', $key));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    protected function optionalArray(array $arguments, string $key): array
    {
        $value = $arguments[$key] ?? null;

        if (null === $value) {
            return [];
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf('"%s" must be an object/array when present.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function requireArray(array $arguments, string $key): array
    {
        $value = $arguments[$key] ?? null;

        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf('"%s" is required and must be an object.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function confirm(array $arguments): bool
    {
        return true === ($arguments['confirm'] ?? false);
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<FieldSpec>
     */
    protected function fields(array $fields): array
    {
        $specs = [];

        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['name'], $field['label'])) {
                throw new \InvalidArgumentException('Every field needs at least "name" and "label".');
            }

            $type = FieldType::tryFrom((string) ($field['type'] ?? 'text'));

            if (null === $type) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" has an unknown type "%s".',
                    (string) $field['name'],
                    (string) ($field['type'] ?? ''),
                ));
            }

            $specs[] = new FieldSpec(
                name: (string) $field['name'],
                label: (string) $field['label'],
                type: $type,
                required: true === ($field['required'] ?? false),
                readOnly: true === ($field['read_only'] ?? false),
                inList: false !== ($field['in_list'] ?? true),
            );
        }

        return $specs;
    }

    /**
     * @param list<string> $actions
     *
     * @return list<string>
     */
    protected function actions(mixed $actions): array
    {
        if (null === $actions) {
            return ['list', 'read'];
        }

        if (!is_array($actions)) {
            throw new \InvalidArgumentException('"actions" must be a list of action names.');
        }

        $names = [];

        foreach ($actions as $action) {
            if (!is_string($action)) {
                throw new \InvalidArgumentException('Every action must be a string.');
            }

            $names[] = $action;
        }

        return $names;
    }

}
