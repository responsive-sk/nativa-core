<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * StatementHelperContractTest — thirteen copies of one file, held to one shape.
 *
 * `PDO::prepare()` and `PDO::query()` are declared as returning
 * `PDOStatement|false`, so every `$stmt = $pdo->prepare(…); $stmt->execute();`
 * in the catalogue was a method call on `false` — 313 of them, measured
 * 2026-10-27. Each module now carries a checked `Statement` helper that raises
 * instead, and the catalogue's count for that rule is 0.
 *
 * The copies are deliberate. Modules are independent slices: `Modules\Blog` may
 * not import `Modules\Media`, and a helper in `Modules\Database` would put a new
 * file into the kernel slice, which `extract-kernel --check` holds against
 * upstream. Thirteen small files is the cost of those two rules, and THIS is
 * what keeps the copying honest: a single shared file would be one edit, and
 * thirteen files are thirteen chances to fix one module and forget the other
 * twelve.
 *
 * The failure this test exists for is quiet in exactly that way. Change the
 * throw message, the guard, or the return type in the helper a module happens
 * to need, and nothing in the tree objects: every module still has a checked
 * `Statement`, every call site still type-checks, `mago analyze` still reports
 * 0 — and one module now behaves differently from the twelve beside it. That
 * difference is only visible by comparing the files, which is what this test
 * does.
 *
 * Three things are pinned:
 *
 *  - the SET of modules that ship a helper — a ratchet, so a fourteenth module
 *    that prepares SQL is a decision someone records (and copies the file for),
 *    not a side effect of a merge;
 *  - each helper's namespace, derived from where the file sits, so a copy that
 *    keeps `Modules\Media\…` inside `modules/Blog/` fails here rather than
 *    resolving to whichever class the autoloader finds first;
 *  - the bodies, byte for byte, after the namespace line is normalised — so
 *    "identical except the namespace" is checked, not asserted in a docblock.
 *
 * The guarded methods are pinned by name as well, because identity alone would
 * accept thirteen copies of a helper that had lost its `if (false === …)`.
 *
 * @internal
 *
 * @coversNothing
 */
final class StatementHelperContractTest extends TestCase
{
    /**
     * Every module that ships a checked `Statement`, in the order the modules
     * directory lists them.
     *
     * Adding a module here is a two-part change: the helper, and this line. The
     * test is a ratchet rather than a scan so that a helper which disappears
     * with its module cannot shrink the set unnoticed.
     *
     * @var list<string>
     */
    private const array MODULES = [
        'Audit',
        'Blog',
        'Cardboard',
        'Clickhouse',
        'Comments',
        'Forms',
        'Locales',
        'Media',
        'Notifications',
        'Outbox',
        'Questions',
        'Queue',
        'Security',
        'Settings',
        'Users',
    ];

    /**
     * The marker the namespace line is replaced with before comparing bodies.
     */
    private const string NAMESPACE_MARKER = 'namespace <MODULE>\\Infrastructure\\Persistence;';

    #[Test]
    public function theHelperSetIsTheModulesThatShipOne(): void
    {
        $found = array_keys($this->helpers());
        sort($found);

        $expected = self::MODULES;
        sort($expected);

        self::assertSame(
            $expected,
            $found,
            'The modules shipping a checked `Statement` changed. If a module now prepares SQL, copy the helper into it and name it in StatementHelperContractTest::MODULES; if one was removed, remove it here deliberately.',
        );
    }

    #[Test]
    public function everyHelperDeclaresItsOwnModuleNamespace(): void
    {
        foreach ($this->helpers() as $module => $file) {
            self::assertStringContainsString(
                $this->namespaceLine($module),
                (string) file_get_contents($file),
                sprintf(
                    '%s must declare `%s`: a helper copied into one module and left in another module\'s namespace resolves to the wrong class — or to none.',
                    $this->relative($file),
                    $this->namespaceLine($module),
                ),
            );
        }
    }

    #[Test]
    public function theHelpersAreIdenticalApartFromTheirNamespace(): void
    {
        $helpers = $this->helpers();
        $reference = self::MODULES[0];

        self::assertArrayHasKey($reference, $helpers, 'The reference module lost its helper — fix MODULES before trusting this comparison.');

        $referenceBody = $this->withoutNamespace($helpers[$reference], $reference);

        foreach ($helpers as $module => $file) {
            if ($module === $reference) {
                continue;
            }

            $body = $this->withoutNamespace($file, $module);

            self::assertSame(
                $referenceBody,
                $body,
                sprintf(
                    "%s and %s have diverged beyond their namespace.%s\nThirteen copies are only safe while they are the same file; make the change in every module, or in none.",
                    $this->relative($helpers[$reference]),
                    $this->relative($file),
                    $this->firstDifference($referenceBody, $body),
                ),
            );
        }
    }

    #[Test]
    public function everyHelperRefusesFalseInBothMethods(): void
    {
        // Identity cannot catch a guard removed from all thirteen at once, so the
        // shape that makes the helper worth copying is asserted directly.
        $body = $this->withoutNamespace($this->helpers()[self::MODULES[0]], self::MODULES[0]);

        foreach (['prepare', 'query'] as $method) {
            self::assertSame(
                1,
                preg_match('/public static function ' . $method . '\(\\\\PDO \$pdo, string \$sql\): \\\\PDOStatement/', $body),
                sprintf('`Statement::%s()` must keep the signature every call site is written against.', $method),
            );
        }

        self::assertSame(
            2,
            substr_count($body, 'if (false === $statement) {'),
            'Both methods must refuse `false` explicitly — a helper that hands back `PDOStatement|false` moves the problem, not the type.',
        );

        self::assertSame(
            2,
            substr_count($body, 'throw new \\RuntimeException('),
            'Both guards must raise: the point of the helper is that a failed prepare is not a value a caller has to branch on.',
        );
    }

    /**
     * Every `Statement.php` under `modules/`, keyed by module path.
     *
     * @return array<string, string> module path (`Users`, `Themes/Mono`, …) → absolute file path
     */
    private function helpers(): array
    {
        $root = $this->root() . '/modules';
        $helpers = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || 'Statement.php' !== $file->getFilename()) {
                continue;
            }

            $path = $file->getPathname();
            $suffix = '/Infrastructure/Persistence/Statement.php';

            if (!str_ends_with($path, $suffix)) {
                continue;
            }

            $module = str_replace('/', '\\', substr($path, \strlen($root) + 1, -\strlen($suffix)));
            $helpers[$module] = $path;
        }

        ksort($helpers);

        return $helpers;
    }

    /**
     * The helper's source with its own namespace line replaced by a marker, so
     * two modules' copies can be compared for everything except the one line
     * that is supposed to differ.
     */
    private function withoutNamespace(string $file, string $module): string
    {
        $namespace = $this->namespaceLine($module);

        return str_replace($namespace, self::NAMESPACE_MARKER, (string) file_get_contents($file));
    }

    private function namespaceLine(string $module): string
    {
        return sprintf('namespace Modules\\%s\\Infrastructure\\Persistence;', $module);
    }

    /**
     * The first differing line of two sources, as a message fragment. Line 1
     * of the message is the first line that differs, which is what a reader
     * needs; the alternative is a diff of two 60-line heredocs.
     */
    private function firstDifference(string $left, string $right): string
    {
        $leftLines = explode("\n", $left);
        $rightLines = explode("\n", $right);

        foreach ($leftLines as $index => $line) {
            $other = $rightLines[$index] ?? null;

            if ($line === $other) {
                continue;
            }

            return sprintf(
                "\nFirst difference: line %d\n  %s: %s\n  %s: %s",
                $index + 1,
                self::MODULES[0],
                $line,
                'the other copy',
                null === $other ? '<no line>' : $other,
            );
        }

        return '';
    }

    private function relative(string $path): string
    {
        return 'modules/' . substr($path, \strlen($this->root() . '/modules/'));
    }

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}
