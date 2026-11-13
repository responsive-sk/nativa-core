<?php

declare(strict_types = 1);

namespace Modules\Blog\Domain\Entities;

use Modules\Markdown\Application\Services\MarkdownRenderer;

/**
 * ArticleAuthor — immutable value object for a blog article's resolved author profile.
 *
 * When an article declares an `author_email` front-matter key that matches an
 * active user in `users.db`, this value object carries the resolved profile
 * (id, name, avatar). Otherwise it is null — callers fall back to the plain
 * `author` string from front matter.
 *
 * The email is a lookup key only and is deliberately NOT part of this VO —
 * it never leaves the resolver and is never rendered.
 */
final readonly class ArticleAuthor
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $avatar,
        public string $slug,
    ) {
    }

    /**
     * Create from a raw profile array (BlogAuthorResolver output). The URL
     * slug is the diacritics-folded display name (nativa-dev, evan-mcdan) —
     * same convention as tags/categories.
     *
     * @param array{id: string, name: string, avatar: ?string} $profile
     */
    public static function fromArray(array $profile): self
    {
        return new self(
            id: $profile['id'],
            name: $profile['name'],
            avatar: $profile['avatar'] ?? null,
            slug: MarkdownRenderer::slugify($profile['name']),
        );
    }
}
