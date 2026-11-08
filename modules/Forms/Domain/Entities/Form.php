<?php

declare(strict_types = 1);

namespace Modules\Forms\Domain\Entities;

use DateTimeImmutable;
use Modules\Forms\Domain\Model\FormSchema;

/**
 * Form — one form definition, owned per locale.
 *
 * The slug is unique only within a locale: `/o-nas` and `/en/about` are two
 * definitions with the same reader-facing name, and a unique index over
 * `slug` alone would make the second language impossible.
 *
 * `published` is the only boolean in this schema. The submission queue is a
 * named status (`new` / `handled`), not a second flag, per
 * `docs/boolean-vocabulary.md`.
 */
final readonly class Form
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $locale,
        public string $title,
        public string $intro,
        public string $successMessage,
        public FormSchema $schema,
        public bool $published,
        public ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function create(
        string $slug,
        string $locale,
        string $title,
        string $intro,
        string $successMessage,
        FormSchema $schema,
        bool $published,
    ): self {
        return new self(
            bin2hex(random_bytes(16)),
            $slug,
            $locale,
            $title,
            $intro,
            $successMessage,
            $schema,
            $published,
            new DateTimeImmutable(),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $published = $row['published'] ?? 0;

        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['slug'] ?? ''),
            (string) ($row['locale'] ?? ''),
            (string) ($row['title'] ?? ''),
            (string) ($row['intro'] ?? ''),
            (string) ($row['success_message'] ?? ''),
            FormSchema::fromJson((string) ($row['fields'] ?? '[]')),
            true === $published || 1 === $published || '1' === $published,
            self::date($row['created_at'] ?? null),
        );
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
