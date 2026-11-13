<?php

declare(strict_types = 1);

namespace Modules\Locales\Application\DTOs;

/**
 * TranslationDTO — Data Transfer Object for translations.
 *
 * Immutable snapshot of a translation used for admin list rendering,
 * JSON export and API responses.
 *
 * `source` says where the row came from: a `translations` database row
 * (`database`, the default, so every existing construction site is unchanged)
 * or the locale's JSON file (`file`, which carries no id and is therefore not
 * editable in place).
 */
final readonly class TranslationDTO
{
    public function __construct(
        public string $id,
        public string $key,
        public string $locale,
        public string $value,
        public string $group,
        public string $createdAt,
        public string $updatedAt,
        public string $source = 'database',
    ) {
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $key = $data['translation_key'] ?? $data['key'] ?? '';
        $group = $data['group_name'] ?? $data['group'] ?? 'general';

        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : '',
            key: is_string($key) ? $key : '',
            locale: is_string($data['locale'] ?? null) ? $data['locale'] : '',
            value: is_string($data['value'] ?? null) ? $data['value'] : '',
            group: is_string($group) ? $group : 'general',
            createdAt: is_string($data['created_at'] ?? null) ? $data['created_at'] : '',
            updatedAt: is_string($data['updated_at'] ?? null) ? $data['updated_at'] : '',
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'translation_key' => $this->key,
            'locale'          => $this->locale,
            'value'           => $this->value,
            'group_name'      => $this->group,
            'created_at'      => $this->createdAt,
            'updated_at'      => $this->updatedAt,
        ];
    }
}
