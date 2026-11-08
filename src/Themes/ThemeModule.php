<?php

declare(strict_types = 1);

namespace Themes;

use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;

/**
 * Theme module contract — the single entry point of a wired theme.
 *
 * Every wired theme (kernel, and module-carried ones like mono or erik) is a self-contained module
 * owning ALL of its HTML: shell chrome (header/footer, locale switcher,
 * user link, nav + footer nav), the theme-init script and one composition
 * method per public page surface. Actions resolve the active module through
 * ThemeKernel and call one surface method — no theme classes are imported
 * into actions and FrontendLayout stays a theme-agnostic head connector.
 */
interface ThemeModule
{
    /** Theme slug — matches ThemeResolver values ('kernel', plus module-carried names). */
    public function name(): string;

    /** Asset bundle id resolved through AssetManager ('main' | 'erik' | 'mark'). */
    public function bundle(): string;

    /** JS manifest key the layout defers (usually equals the bundle id). */
    public function jsKey(): string;

    /**
     * Ordered critical-CSS file list used when the Vite manifest is absent.
     *
     * @return list<string>
     */
    public function criticalCss(): array;

    /** Inline <script> body executed before first paint (theme-init). Empty string when none. */
    public function themeInitScript(): string;

    /** CSS class prefix of the theme's BEM vocabulary ('st', 'er', 'ms'). */
    public function prefix(): string;

    /** Body opening chrome: <header>… (nav, locale switcher, user link, theme toggle). */
    public function header(ShellContext $context): string;

    /** Body closing chrome: <footer>…. */
    public function footer(ShellContext $context): string;

    /** Main content wrapper — the theme decides <main class="…"> vs custom layout. */
    public function pageWrap(string $content): string;

    public function homepage(PageContext $context): string;

    public function blogIndex(PageContext $context): string;

    public function blogSearch(PageContext $context): string;

    public function blogArticle(PageContext $context): string;

    /**
     * Blocks the article page owns but the action produces outside the theme:
     * comments and reader questions. A theme that composes an article column
     * places them inside it; the default keeps them as the page's trailing
     * slot, which is what every theme did before this hook existed.
     */
    public function articleTail(string $html): string;

    public function blogTags(PageContext $context): string;

    public function blogCategories(PageContext $context): string;

    public function authorProfile(PageContext $context): string;

    /**
     * Error content block (404/405/500): the INNER section, wrapped in the
     * layout by the caller. Theme-owned so an error page wears the active
     * theme's vocabulary instead of another theme's class names — a 404 in
     * `.st-hero` on a kernel-styled page is the same bug class as a page in
     * another theme's stylesheet.
     */
    public function errorContent(int $status, string $title, string $detail): string;
}
