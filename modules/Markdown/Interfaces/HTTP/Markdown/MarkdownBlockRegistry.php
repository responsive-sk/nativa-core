<?php

declare(strict_types = 1);

namespace Modules\Markdown\Interfaces\HTTP\Markdown;

use Modules\Markdown\Infrastructure\Attributes\AsMarkdownBlock;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * MarkdownBlockRegistry — auto-discovers #[AsMarkdownBlock] classes and applies
 * them to rendered markdown.
 *
 * Deliberately the same shape as `MiddlewareRegistry`: scan two conventional
 * directories per module, keep what implements the contract and carries the
 * attribute with a zero-arg constructor, sort by priority, cache, and expose a
 * `reset()` for test isolation. Two extension points with two different
 * mechanisms would be one too many.
 *
 * A block that throws surfaces loudly — a swallowed expansion failure would
 * render an article with a raw `{{...}}` in it and nobody would look.
 */
final class MarkdownBlockRegistry
{
    /** @var list<MarkdownBlockInterface>|null */
    private static ?array $blocks = null;

    /**
     * Discovered blocks, sorted by priority.
     *
     * @return list<MarkdownBlockInterface>
     */
    public static function all(): array
    {
        if (null !== self::$blocks) {
            return self::$blocks;
        }

        $blocks = [];
        $base = AppPaths::instance()->src('Modules');

        foreach (['/*/Application/Markdown', '/*/Interfaces/HTTP/Markdown'] as $pattern) {
            $dirs = glob($base . $pattern);
            $files = [];

            foreach (is_array($dirs) ? $dirs : [] as $dir) {
                $found = glob($dir . '/*.php');

                foreach (is_array($found) ? $found : [] as $file) {
                    $files[] = $file;
                }
            }

            foreach ($files as $file) {
                self::scanFile($file, $blocks);
            }
        }

        uasort($blocks, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        self::$blocks = array_values(array_map(
            static fn (array $entry): MarkdownBlockInterface => $entry[1],
            $blocks,
        ));

        return self::$blocks;
    }

    /**
     * Run every block over `$html`, in priority order.
     */
    public static function expand(string $html, ?MarkdownBlockContext $context = null): string
    {
        $resolved = $context ?? MarkdownBlockContext::default();

        foreach (self::all() as $block) {
            $html = $block->expand($html, $resolved);
        }

        return $html;
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$blocks = null;
    }

    /**
     * @param array<string, array{int, MarkdownBlockInterface}> $blocks
     */
    private static function scanFile(string $file, array &$blocks): void
    {
        $content = file_get_contents($file);

        if (!is_string($content) || !str_contains($content, 'AsMarkdownBlock')) {
            return;
        }

        $fqcn = self::resolveFqcn($content);

        if (null !== $fqcn) {
            self::loadAndRegister($fqcn, $file, $blocks);
        }
    }

    /**
     * Extract the FQCN from a file body: its namespace declaration and its type
     * declaration, and nothing else.
     *
     * A declaration starts a LINE (after optional whitespace and the modifiers
     * PHP allows on a type) or a STATEMENT (after a `;`), so a compact
     * `namespace Demo; class Thing {}` file still resolves — the failure mode
     * here is silence, not an error, and a heuristic that is too strict trades
     * one silent skip for another.
     *
     * What cannot be a declaration is PROSE. This used to take the FIRST mention
     * of the keyword anywhere in the file, so a docblock sentence like "This
     * class says the one thing..." resolved a type named `says`: the class did
     * not exist, the file was skipped with no log line, and the markdown block silently
     * disappeared — measured on the Settings shell provider, whose nav menu
     * vanished exactly that way (docs/kernel-status/03-content-themes-and-the-admin-merge.md, Boot 23).
     *
     * tests/Discovery/TextFirstFqcnTest.php pins both halves, for all four
     * registries that share this shape.
     */
    private static function resolveFqcn(string $content): ?string
    {
        $namespace = '';
        $className = '';

        if (preg_match('/(?:^|;)\s*namespace\s+([\w\\\\]+)\s*;/m', $content, $namespaceMatch)) {
            $namespace = $namespaceMatch[1];
        }

        if (preg_match('/(?:^|;)\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $content, $classNameMatch)) {
            $className = $classNameMatch[1];
        }

        if ('' === $namespace || '' === $className) {
            return null;
        }

        return $namespace . '\\' . $className;
    }

    /**
     * @param array<string, array{int, MarkdownBlockInterface}> $blocks
     */
    private static function loadAndRegister(string $fqcn, string $file, array &$blocks): bool
    {
        if (!class_exists($fqcn)) {
            require_once $file;
        }

        if (!class_exists($fqcn)) {
            return false;
        }

        $reflection = new \ReflectionClass($fqcn);

        if (!$reflection->implementsInterface(MarkdownBlockInterface::class)) {
            return false;
        }

        $attributes = $reflection->getAttributes(AsMarkdownBlock::class);

        if ([] === $attributes || $reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        /** @var AsMarkdownBlock $attr */
        $attr = $attributes[0]->newInstance();

        /** @var MarkdownBlockInterface $instance */
        $instance = $reflection->newInstance();
        $blocks[$attr->priority . '_' . $fqcn] = [$attr->priority, $instance];

        return true;
    }
}
