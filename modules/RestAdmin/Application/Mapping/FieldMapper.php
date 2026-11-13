<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping;

/**
 * FieldMapper — reading a stored field name out of a row, honestly.
 *
 * A field's `name` is the API's key and may be a dotted path (`author.name`),
 * because a nested object is common and flattening it on save would lose the
 * shape the form has to write back. The one ambiguous case in JSON is a value
 * that is `null` versus a key that is absent: a form must not show "empty" for
 * a field the API never sent, so `has()` answers presence and `read()` answers
 * value, and `map()` omits absent keys rather than inventing nulls.
 *
 * JSON:API rows keep the readable fields under `attributes` beside `id` and
 * `type`. Reading falls back to that wrapper only when the top-level path is
 * absent, so a field that genuinely lives at the top level still wins.
 */
final readonly class FieldMapper
{
    public function read(array $row, string $path): mixed
    {
        [$found, $value] = $this->find($row, $path);

        return $found ? $value : null;
    }

    public function has(array $row, string $path): bool
    {
        return $this->find($row, $path)[0];
    }

    /**
     * The values of the named fields that the row actually carries.
     *
     * @param list<string> $paths
     *
     * @return array<string, mixed> path => value, absent keys omitted
     */
    public function map(array $row, array $paths): array
    {
        $values = [];

        foreach ($paths as $path) {
            [$found, $value] = $this->find($row, $path);

            if ($found) {
                $values[$path] = $value;
            }
        }

        return $values;
    }

    /**
     * @return array{bool, mixed}
     */
    private function find(array $row, string $path): array
    {
        [$found, $value] = $this->dig($row, $path);

        if ($found) {
            return [true, $value];
        }

        $attributes = $row['attributes'] ?? null;

        return is_array($attributes) ? $this->dig($attributes, $path) : [false, null];
    }

    /**
     * @return array{bool, mixed}
     */
    private function dig(array $row, string $path): array
    {
        $value = $row;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }

            $value = $value[$segment];
        }

        return [true, $value];
    }
}
