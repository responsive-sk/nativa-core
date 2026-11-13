<?php

declare(strict_types = 1);

namespace Modules\Locales\Tests;

use Modules\Locales\Interfaces\HTTP\Actions\LocalesAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * LocalesValidationTest — the translation form's legacy error strings
 * survive the Validation-module refactor, in the same order.
 *
 * `LocalesAction::validate()` is private, so the test reaches it via
 * reflection with a constructor-less instance (the router instantiates
 * actions without arguments, and the method only touches its own parameters
 * plus the pure locale-availability lookup).
 *
 * Like every module test it drives the INSTALLED copy and skips when the
 * module (or its Validation dependency) is not installed.
 */
final class LocalesValidationTest extends TestCase
{
    /** @var callable(string, string, string): list<string> */
    private $validate;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Locales', 'Validation'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $method = new ReflectionMethod(LocalesAction::class, 'validate');
        $action = (new \ReflectionClass(LocalesAction::class))->newInstanceWithoutConstructor();

        $this->validate = static fn (string $key, string $locale, string $value): array =>
            /** @var list<string> */
            $method->invoke($action, $key, $locale, $value);
    }

    #[Test]
    public function validPayloadPasses(): void
    {
        $this->assertSame([], ($this->validate)('nav.home', 'sk_SK', 'Domov'));
    }

    #[Test]
    public function missingFieldsReportLegacyMessagesInOrder(): void
    {
        $this->assertSame(
            ['Translation key is required.', 'Locale is required.', 'Value is required.'],
            ($this->validate)('', '', ''),
        );
    }

    #[Test]
    public function malformedKeyReportsLegacyMessage(): void
    {
        $this->assertSame(
            ['Key must match [a-zA-Z0-9._-]+ (e.g. nav.home).'],
            ($this->validate)('no good!', 'sk_SK', 'Domov'),
        );
    }

    #[Test]
    public function unavailableLocaleReportsLegacyMessage(): void
    {
        $this->assertSame(
            ['The selected locale is not available.'],
            ($this->validate)('nav.home', 'xx_XX', 'Domov'),
        );
    }

    #[Test]
    public function blankKeyIsRejected(): void
    {
        // Tightening vs the old `=== ''` check (documented on the method):
        // inputs arrive raw, so whitespace-only is not a key.
        $this->assertSame(
            ['Translation key is required.'],
            ($this->validate)('   ', 'sk_SK', 'Domov'),
        );
    }

    #[Test]
    public function editValueCheckKeepsLegacyMessage(): void
    {
        $method = new ReflectionMethod(LocalesAction::class, 'validateValue');
        $action = (new \ReflectionClass(LocalesAction::class))->newInstanceWithoutConstructor();
        $validateValue = static fn (string $value): array =>
            /** @var list<string> */
            $method->invoke($action, $value);

        $this->assertSame([], $validateValue('Domov'));
        $this->assertSame(['Value is required.'], $validateValue(''));
        $this->assertSame(['Value is required.'], $validateValue('   '));
    }
}
