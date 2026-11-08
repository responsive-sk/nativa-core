<?php

declare(strict_types = 1);

namespace Themes\kernel;

use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Themes\ThemeModule;

/**
 * KernelFallbackTheme — the kernel's built-in default theme.
 *
 * The kernel must render every public surface even when no theme module is
 * installed, so `ThemeKernel` falls back to this class. It is deliberately
 * plain: semantic HTML, no CSS, no build step. Visual themes ship as modules
 * (see `Modules\KernelTheme`) and are discovered by `ThemeRegistry`; this class
 * lives outside `src/Modules` on purpose, so discovery never registers it.
 *
 * Every escaped value goes through `e()`; markup the kernel produced itself
 * (the article body) is passed through, the same rule the other themes follow.
 */
final class KernelFallbackTheme implements ThemeModule
{
    public function name(): string
    {
        return 'kernel';
    }

    public function bundle(): string
    {
        return 'kernel';
    }

    public function jsKey(): string
    {
        return 'kernel';
    }

    /** No stylesheet: the fallback is unstyled on purpose. */
    public function criticalCss(): array
    {
        return [];
    }

    public function themeInitScript(): string
    {
        return '';
    }

    public function prefix(): string
    {
        return 'k';
    }

    public function header(ShellContext $context): string
    {
        return '<header><a href="/">' . self::e($context->siteName) . '</a></header>' . "\n";
    }

    public function footer(ShellContext $context): string
    {
        return '<footer>&copy; ' . self::e((string) $context->year) . ' ' . self::e($context->siteName) . '</footer>' . "\n";
    }

    public function pageWrap(string $content): string
    {
        return '<main>' . "\n" . '    ' . $content . "\n" . '</main>' . "\n";
    }

    public function homepage(PageContext $context): string
    {
        return $this->pageWrap($this->section((string) ($context->copy['title'] ?? 'Home')));
    }

    public function blogIndex(PageContext $context): string
    {
        return $this->pageWrap($this->section('Blog'));
    }

    public function blogSearch(PageContext $context): string
    {
        return $this->pageWrap($this->section('Search: ' . $context->query));
    }

    public function blogArticle(PageContext $context): string
    {
        // Article HTML is rendered by MarkdownRenderer; it is passed through, never escaped.
        return $this->pageWrap(
            '<article><h1>' . self::e($context->title) . '</h1>' . $context->bodyHtml . '</article>',
        );
    }

    public function articleTail(string $html): string
    {
        return $html;
    }

    public function blogTags(PageContext $context): string
    {
        return $this->pageWrap($this->section(null !== $context->tag ? 'Tag: ' . $context->tag : 'Tags'));
    }

    public function blogCategories(PageContext $context): string
    {
        return $this->pageWrap($this->section($context->category ?? 'Categories'));
    }

    public function authorProfile(PageContext $context): string
    {
        return $this->pageWrap($this->section('Author'));
    }

    public function errorContent(int $status, string $title, string $detail): string
    {
        return '<section>'
            . '<p>' . self::e((string) $status) . '</p>'
            . '<h1>' . self::e($title) . '</h1>'
            . '<p>' . self::e($detail) . '</p>'
            . '<p><a href="/">Back to homepage</a></p>'
            . '</section>';
    }

    private function section(string $title): string
    {
        return '<section><h1>' . self::e($title) . '</h1></section>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
