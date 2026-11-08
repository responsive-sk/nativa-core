<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Markdown;

/**
 * MarkdownBlockInterface — one expander over already-rendered markdown HTML.
 *
 * The renderer turns markdown into HTML and then hands the result to every
 * registered block. A block owns ONE placeholder syntax (the Forms module owns
 * `{{form:<slug>}}`) and nothing else — Core never learns what the placeholders
 * mean, and no module has to be imported by Pages, Blog or the renderer for a
 * feature to appear inside content.
 *
 * This mirrors the `#[AsMiddleware]` extension point exactly: a contract, an
 * attribute and a registry, all discovered by scanning the module tree.
 */
interface MarkdownBlockInterface
{
    /**
     * Human-readable block name (for diagnostics and tests).
     */
    public function name(): string;

    /**
     * Replace this block's placeholders in `$html`.
     *
     * Implementations MUST return markup that is safe to embed as-is: the
     * input is already-escaped content HTML, so escaping again would double
     * encode it, and injecting raw author text would undo the renderer's
     * escaping guarantee.
     */
    public function expand(string $html, MarkdownBlockContext $context): string;
}
