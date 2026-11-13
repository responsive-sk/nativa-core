<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping;

/**
 * Envelope — the wrapper a collection or a detail answer arrives in.
 *
 * The examples this module was designed against use three list wrappers
 * (`data` for Laravel, `results` for the shop API, `items` for a plain Express
 * answer) and a bare JSON list. They all mean the same thing, so detection is
 * structural rather than configured: a specification is about the resource, not
 * about the service's serialiser, and forcing an operator to declare `data` for
 * one endpoint and `results` for the next would be a setting that only ever
 * repeats itself.
 *
 * A list is recognised only when the value under the key is a LIST of objects.
 * An object under `data` is a detail answer, not a collection — that
 * distinction is why `items()` and `item()` are separate methods rather than
 * one with a flag.
 *
 * JSON:API's `attributes` wrapper is not unwrapped here; `FieldMapper` reads
 * through it, so `id`/`type` stay visible beside the attributes instead of
 * being flattened into them.
 */
final readonly class Envelope
{
    /**
     * @param list<string> $listKeys
     * @param list<string> $itemKeys
     */
    public function __construct(
        private array $listKeys = ['data', 'items', 'results'],
        private array $itemKeys = ['data', 'item', 'result'],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     *
     * @throws MappingException when the answer carries no list of objects
     */
    public function items(array $payload): array
    {
        if (array_is_list($payload)) {
            return $this->rows($payload, 'the answer is a list');
        }

        foreach ($this->listKeys as $key) {
            $candidate = $payload[$key] ?? null;

            if (is_array($candidate) && array_is_list($candidate)) {
                return $this->rows($candidate, sprintf('"%s" is not a list of objects', $key));
            }
        }

        throw new MappingException(
            'The answer has no collection: expected a JSON list, or a list under "data", "items" or "results".',
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws MappingException when the answer is a list rather than an object
     */
    public function item(array $payload): array
    {
        if (array_is_list($payload) && [] !== $payload) {
            throw new MappingException('A detail answer arrived as a list; the item path must address one member.');
        }

        foreach ($this->itemKeys as $key) {
            $candidate = $payload[$key] ?? null;

            if (is_array($candidate) && !array_is_list($candidate)) {
                return $candidate;
            }
        }

        return $payload;
    }

    /**
     * @param list<mixed> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows, string $where): array
    {
        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new MappingException(sprintf(
                    'The collection holds a %s where a row object is required (%s).',
                    get_debug_type($row),
                    $where,
                ));
            }

            $items[] = $row;
        }

        return $items;
    }
}
