<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Domain\Model;

/**
 * CardLink — immutable value object for a card action link.
 *
 * Represents a clickable action pill on a dashboard stat card
 * (e.g. "List", "New", "Upload").
 */
final readonly class CardLink
{
    public function __construct(
        public string $label,
        public string $href,
        public ?string $icon = null,
    ) {
    }

    public static function make(string $label, string $href): self
    {
        return new self(label: $label, href: $href);
    }
}
