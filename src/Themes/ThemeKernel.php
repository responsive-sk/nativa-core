<?php

declare(strict_types = 1);

namespace Themes;

use Themes\kernel\KernelFallbackTheme;

/**
 * ThemeKernel connector — the ONLY place mapping a resolved theme name to its
 * `Themes\ThemeModule` implementation.
 *
 * Every theme is a module: `ThemeRegistry` discovers the installed ones (the
 * kernel's own visual theme ships as `Modules\KernelTheme`), so a module that
 * is removed takes its theme with it without anyone editing this file.
 *
 * The kernel keeps one built-in default, `Themes\kernel\KernelFallbackTheme`.
 * It answers when no installed module supplies the requested name, so every
 * public surface still renders in a tree with no theme module at all.
 *
 * **An unknown name falls back to the kernel default, never to a theme the tree
 * does not have.** This repo has already paid for the other shape once:
 * `AssetManager::knownBundles()` served the default theme's stylesheet for a
 * theme it did not recognise, and the result — a page rendering another theme's
 * CSS — is indistinguishable from a page that works.
 *
 * One-way dependency edge, Modules\Core → Themes, for this file and
 * `ThemeRegistry` alone; no real runtime cycle, because a theme already
 * depends on Core helpers (`FrontendLayout::e`) — documented in ADR-0003.
 */
final class ThemeKernel
{
    public static function for(string $theme): ThemeModule
    {
        $class = ThemeRegistry::all()[$theme] ?? KernelFallbackTheme::class;

        // class_exists() rather than a bare `new`: a stale catalogue entry or a
        // theme class deleted by hand must degrade to the kernel default, not
        // fatal in the middle of rendering a page.
        if (!class_exists($class)) {
            $class = KernelFallbackTheme::class;
        }

        return new $class();
    }
}
