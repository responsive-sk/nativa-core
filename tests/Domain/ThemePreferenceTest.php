<?php

declare(strict_types = 1);

namespace Tests\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Themes\ThemePreference;
use Themes\ThemeResolver;

/**
 * ThemePreferenceTest — the stored-theme vote as a pure decision, no
 * database, no registry, no migrated tree.
 *
 * Pins the rule both halves share (`ThemeResolver::resolvePath()` renders it,
 * the Settings provider answers it): a stored name renders only when this
 * tree can render it, everything else falls back.
 */
final class ThemePreferenceTest extends TestCase
{
    #[Test]
    public function resolve(): void
    {
        $available = [ThemeResolver::KERNEL_THEME, 'erik'];

        $this->assertSame('erik', ThemePreference::resolve('erik', $available, ThemeResolver::KERNEL_THEME));
        $this->assertSame(ThemeResolver::KERNEL_THEME, ThemePreference::resolve(ThemeResolver::KERNEL_THEME, $available, 'fallback'));
        $this->assertSame('fallback', ThemePreference::resolve(null, $available, 'fallback'));
        $this->assertSame('fallback', ThemePreference::resolve('', $available, 'fallback'));
        $this->assertSame('fallback', ThemePreference::resolve('mono', $available, 'fallback'), 'Stale stored name (theme module removed) must fall back.');
        $this->assertSame('fallback', ThemePreference::resolve('Erik', $available, 'fallback'), 'Theme names match exactly — no case folding.');
        $this->assertSame('fallback', ThemePreference::resolve('erik', [], 'fallback'), 'Nothing renderable means nothing resolvable.');
    }

    #[Test]
    public function isResolvable(): void
    {
        $this->assertTrue(ThemePreference::isResolvable('erik', [ThemeResolver::KERNEL_THEME, 'erik']));
        $this->assertFalse(ThemePreference::isResolvable(null, [ThemeResolver::KERNEL_THEME]));
        $this->assertFalse(ThemePreference::isResolvable('', [ThemeResolver::KERNEL_THEME]));
        $this->assertFalse(ThemePreference::isResolvable('mono', [ThemeResolver::KERNEL_THEME]));
    }
}
