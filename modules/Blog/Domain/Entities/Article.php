<?php

declare(strict_types = 1);

namespace Modules\Blog\Domain\Entities;

/**
 * Article — immutable value object for a blog article.
 *
 * Replaces the associative arrays previously passed between services and actions.
 * Articles are derived from markdown files in storage/blog/ on every request
 * (no generated map). The VO carries the normalized card data used by the index,
 * article page, feeds, search, tags, and categories.
 */
final readonly class Article
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $date,
        public string $excerpt,
        public string $category,
        public string $author,
        public string $authorEmail,
        public ?ArticleAuthor $profile,
        /** @var list<string> */
        public array $tags,
        public string $image,
        public bool $membersOnly,
        public string $locale,
        public ?string $translation,
        /** Drafts (published: false) are hidden from every public surface. */
        public bool $published = true,
    ) {
    }

    /**
     * Create from the normalized card array produced by BlogArticlesService.
     *
     * @param array{slug: string, title: string, date: string, excerpt: string, category: string, author: string, author_email: string, profile: array{id: string, name: string, avatar: ?string}|null, tags: list<string>, image: string, members_only: bool, locale: string, translation: ?string, published?: bool} $card
     */
    public static function fromCard(array $card): self
    {
        $profile = null !== $card['profile']
            ? ArticleAuthor::fromArray($card['profile'])
            : null;

        return new self(
            slug: $card['slug'],
            title: $card['title'],
            date: $card['date'],
            excerpt: $card['excerpt'],
            category: $card['category'],
            author: $card['author'],
            authorEmail: $card['author_email'],
            profile: $profile,
            tags: $card['tags'],
            image: $card['image'],
            membersOnly: $card['members_only'],
            locale: $card['locale'],
            translation: $card['translation'] ?? null,
            published: $card['published'] ?? true,
        );
    }

    /**
     * Convert back to the legacy card array shape for backward compatibility
     * during the migration period. New code should use the VO properties directly.
     *
     * @return array{slug: string, title: string, date: string, excerpt: string, category: string, author: string, author_email: string, profile: array{id: string, name: string, avatar: ?string}|null, tags: list<string>, image: string, members_only: bool, locale: string, translation: ?string, published: bool}
     */
    public function toArray(): array
    {
        /** @var list<string> $tags */
        $tags = $this->tags;

        return [
            'slug'         => $this->slug,
            'title'        => $this->title,
            'date'         => $this->date,
            'excerpt'      => $this->excerpt,
            'category'     => $this->category,
            'author'       => $this->author,
            'author_email' => $this->authorEmail,
            'profile'      => null !== $this->profile
                ? ['id' => $this->profile->id, 'name' => $this->profile->name, 'avatar' => $this->profile->avatar]
                : null,
            'tags'         => $tags,
            'image'        => $this->image,
            'members_only' => $this->membersOnly,
            'locale'       => $this->locale,
            'translation'  => $this->translation,
            'published'    => $this->published,
        ];
    }
}
