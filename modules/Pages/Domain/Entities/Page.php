<?php

declare(strict_types = 1);

namespace Modules\Pages\Domain\Entities;

/**
 * Page — value object for a static page (about, contact, etc.).
 *
 * Pages are stored as markdown files with YAML frontmatter in
 * storage/pages/{locale}/. This entity represents the parsed data.
 */
final readonly class Page
{
    public function __construct(
        private string $slug,
        private string $title,
        private string $body,
        private string $seoTitle,
        private string $seoDescription,
        private bool $published,
        private string $locale,
    ) {
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function seoTitle(): string
    {
        return $this->seoTitle;
    }

    public function seoDescription(): string
    {
        return $this->seoDescription;
    }

    public function published(): bool
    {
        return $this->published;
    }

    public function locale(): string
    {
        return $this->locale;
    }
}
