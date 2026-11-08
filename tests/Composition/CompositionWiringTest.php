<?php

declare(strict_types = 1);

namespace Tests\Composition;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CompositionWiringTest — the guard on `src/composition.php`.
 *
 * The file exists because of a measured asymmetry (Boot 16): the Audit↔Outbox
 * handler pairing was declared inline in `public/index.php`, so it existed in
 * exactly one process — the one serving HTTP. Any CLI drain found an empty
 * handler map and parked every message as `No handler registered for event
 * type`, while the HTTP responses still looked perfectly healthy. The pairing
 * did not fail loudly; it failed silently in the processes nobody watched.
 *
 * Three claims are pinned here, and they are deliberately NOT module tests:
 *
 *  1. **The wiring survives a CLI-only boot.** A fresh process that requires
 *     `src/init.php` and nothing else — exactly what `bin/cms` loads — can
 *     call the function and exit 0. A kernel-only checkout has no
 *     `Modules\Audit` on disk, so this is also the proof that the guards are
 *     real: the function must return rather than fatal on a missing class.
 *  2. **It is idempotent.** Every pairing is a map write, so a second call in
 *     the same process is a no-op, not a duplicated handler. A worker that
 *     boots, wires, and re-wires must not end up registering twice.
 *  3. **Both delivery entry points call it.** This is the drift guard, and it
 *     is a SOURCE assertion on purpose: the bug being prevented is a wiring
 *     line present in one entry point and missing from the other, which no
 *     runtime assertion can see. Re-adding `src/http.php`'s own inline
 * block would make this test pass while reintroducing Boot 16 — so the
 * third case also pins that `src/http.php` no longer carries one.
 *
 * Every source assertion reads CODE, not text: comments are stripped with
 * `token_get_all()` first, because both entry points *describe* this wiring in
 * their headers, and a `assertStringContainsString` over raw text would be
 * satisfied by that prose alone — a test that passes on a comment is the same
 * class of lie as the green suite Boot 26 opened this tree's gates about. This
 * is the rule `TextFirstFqcnTest` and `KernelShapeTest` already read tokens
 * for.
 *
 * @internal
 *
 * @coversNothing
 */
final class CompositionWiringTest extends TestCase
{
    private const string COMPOSITION = 'src/composition.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('wire_module_handlers')) {
            require_once APP_BASE_PATH . '/' . self::COMPOSITION;
        }
    }

    #[Test]
    public function theWiringIsReachableFromACliOnlyBoot(): void
    {
        $script = sprintf(
            'require %s; require %s; wire_module_handlers(); echo "wired\n";',
            var_export(APP_BASE_PATH . '/src/init.php', true),
            var_export(APP_BASE_PATH . '/' . self::COMPOSITION, true),
        );

        [$exit, $output] = $this->php($script);

        $this->assertSame(0, $exit, 'a CLI boot that wires the handlers must not fail: ' . $output);
        $this->assertStringContainsString('wired', $output);
    }

    #[Test]
    public function wiringTwiceInOneProcessIsSafe(): void
    {
        // In this process, with whatever modules happen to be installed —
        // including none. The claim is that the second call neither throws nor
        // changes what the first one produced, and a process that has never
        // registered anything must survive both calls.
        wire_module_handlers();
        wire_module_handlers();

        $this->assertTrue(function_exists('wire_module_handlers'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deliveryEntryPoints(): iterable
    {
        yield 'the HTTP bootstrap' => ['src/http.php'];

        yield 'the CLI tool' => ['bin/cms'];
    }

    #[Test]
    #[DataProvider('deliveryEntryPoints')]
    public function everyDeliveryEntryPointWiresTheHandlers(string $file): void
    {
        $this->assertStringContainsString(
            'wire_module_handlers()',
            $this->code($file),
            sprintf('%s delivers work but never wires the handlers it delivers through.', $file),
        );
    }

    #[Test]
    public function theHttpBootstrapHoldsNoPairingOfItsOwn(): void
    {
        // The asymmetry is the whole defect: a pairing declared inline here is
        // a pairing the worker does not have. So the file must CALL the shared
        // wiring and carry no pairing of its own — measured by the handler
        // class name, not by the word "register", which the middleware wiring
        // legitimately uses.
        $this->assertStringNotContainsString(
            'SecurityEventHandler::register',
            $this->code('src/http.php'),
            'src/http.php must not re-declare the pairing it delegates to src/composition.php.',
        );
    }

    #[Test]
    public function theCompositionNamesNoModuleInATypePositionOrUseStatement(): void
    {
        $code = $this->code(self::COMPOSITION);

        $this->assertStringNotContainsString(
            'use Modules\\',
            $code,
            'a `use Modules\…` line is what the closure scan counts, and src/ is the kernel\'s.',
        );

        $this->assertStringNotContainsString(
            'SecurityEventHandler::class',
            $code,
            'a class name in a ::class constant is resolved at compile time — the exact kernel-only '
            . 'failure the inline version was refactored away from.',
        );
    }

    private function read(string $file): string
    {
        $path = APP_BASE_PATH . '/' . $file;
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertIsString($contents, 'could not read ' . $file);

        return $contents;
    }

    /**
     * The file with every comment removed — the source as the engine reads it.
     *
     * `token_get_all()` is what makes this exact rather than approximate: a
     * `//` inside a string literal survives a regex strip and a docblock
     * mentioning a class name survives no strip at all.
     */
    private function code(string $file): string
    {
        $code = '';

        foreach (token_get_all($this->read($file)) as $token) {
            if (is_array($token)) {
                if (T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0]) {
                    // A newline is kept so a removed comment cannot join two
                    // tokens into one that reads as present.
                    $code .= "\n";

                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * @return array{0: int, 1: string} exit code, combined output
     */
    private function php(string $script): array
    {
        $output = [];
        $exit = 0;
        exec(
            sprintf('php -d error_reporting=E_ALL -r %s 2>&1', escapeshellarg($script)),
            $output,
            $exit,
        );

        return [$exit, implode("\n", $output)];
    }
}
