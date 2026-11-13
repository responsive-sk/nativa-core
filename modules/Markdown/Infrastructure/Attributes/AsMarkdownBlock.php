<?php

declare(strict_types = 1);

namespace Modules\Markdown\Infrastructure\Attributes;

/**
 * Marks a class as a markdown block expander for auto-discovery.
 *
 * Classes annotated with #[AsMarkdownBlock] are discovered by
 * MarkdownBlockRegistry from `Modules/<Module>/Application/Markdown/` and
 * `Modules/<Module>/Interfaces/HTTP/Markdown/` and applied to every rendered
 * markdown document. Priority controls expansion order (lower = earlier).
 *
 * @see MarkdownBlockRegistry
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class AsMarkdownBlock
{
    public function __construct(
        public string $module = '',
        public int $priority = 100,
    ) {
    }
}
