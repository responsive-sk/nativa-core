<?php

declare(strict_types = 1);

namespace Modules\Locales\Domain\Model;

/**
 * Translation Entity.
 *
 * Represents a single translation key-value pair for a specific locale.
 * A plain data entity — no domain events (the minimal kit has no listeners
 * for them; writes invalidate the request cache in TranslationService).
 */
final class Translation
{
    private string $id;

    private string $key;

    private Locale $locale;

    private string $value;

    private string $group;

    private \DateTimeImmutable $createdAt;

    private \DateTimeImmutable $updatedAt;

    private function __construct()
    {
    }

    /**
     * Create a new translation.
     *
     * @throws \InvalidArgumentException When the key format is invalid
     */
    public static function create(
        string $key,
        Locale | string $locale,
        string $value = '',
        string $group = 'general',
    ): self {
        if (!self::isValidKey($key)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid translation key: "%s". Must match [a-zA-Z0-9._-]+',
                $key,
            ));
        }

        $now = new \DateTimeImmutable();
        $instance = new self();
        $instance->id = bin2hex(random_bytes(13));
        $instance->key = $key;
        $instance->locale = is_string($locale) ? Locale::fromString($locale) : $locale;
        $instance->value = $value;
        $instance->group = $group;
        $instance->createdAt = $now;
        $instance->updatedAt = $now;

        return $instance;
    }

    /**
     * Hydrate from a database row.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $instance = new self();
        $instance->id = self::s($data['id'] ?? '');
        $instance->key = self::s($data['translation_key'] ?? $data['key'] ?? '');
        $localeCode = self::s($data['locale'] ?? '');
        $instance->locale = '' !== $localeCode ? Locale::fromString($localeCode) : Locale::default();
        $instance->value = self::s($data['value'] ?? '');
        $instance->group = self::s($data['group_name'] ?? $data['group'] ?? 'general');
        $instance->createdAt = new \DateTimeImmutable(self::s($data['created_at'] ?? 'now'));
        $instance->updatedAt = new \DateTimeImmutable(self::s($data['updated_at'] ?? 'now'));

        return $instance;
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * Get the translation key (group-prefixed, e.g. nav.home).
     */
    public function key(): string
    {
        return $this->key;
    }

    public function locale(): Locale
    {
        return $this->locale;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function group(): string
    {
        return $this->group;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Update the translation value and bump the timestamp.
     */
    public function updateValue(string $value): void
    {
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Update the translation group and bump the timestamp.
     */
    public function updateGroup(string $group): void
    {
        $this->group = $group;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'translation_key' => $this->key,
            'locale'          => $this->locale->code(),
            'value'           => $this->value,
            'group_name'      => $this->group,
            'created_at'      => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at'      => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    private static function s(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private static function isValidKey(string $key): bool
    {
        return '' !== $key && (bool) preg_match('/^[a-zA-Z0-9._-]+$/', $key);
    }
}
