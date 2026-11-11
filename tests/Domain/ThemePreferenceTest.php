<?php

declare(strict_types = 1);

namespace Tests\Domain;

use Modules\Layout\ThemePreference;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        $available = ['kernel', 'erik'];

        $this->assertSame('erik', ThemePreference::resolve('erik', $available, 'kernel'));
        $this->assertSame('kernel', ThemePreference::resolve('kernel', $available, 'fallback'));
        $this->assertSame('fallback', ThemePreference::resolve(null, $available, 'fallback'));
        $this->assertSame('fallback', ThemePreference::resolve('', $available, 'fallback'));
        $this->assertSame('fallback', ThemePreference::resolve('mono', $available, 'fallback'), 'Stale stored name (theme module removed) must fall back.');
        $this->assertSame('fallback', ThemePreference::resolve('Erik', $available, 'fallback'), 'Theme names match exactly — no case folding.');
        $this->assertSame('fallback', ThemePreference::resolve('erik', [], 'fallback'), 'Nothing renderable means nothing resolvable.');
    }

    #[Test]
    public function isResolvable(): void
    {
        $this->assertTrue(ThemePreference::isResolvable('erik', ['kernel', 'erik']));
        $this->assertFalse(ThemePreference::isResolvable(null, ['kernel']));
        $this->assertFalse(ThemePreference::isResolvable('', ['kernel']));
        $this->assertFalse(ThemePreference::isResolvable('mono', ['kernel']));
    }
}
