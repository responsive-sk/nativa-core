<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Infrastructure\Paths\AppPaths;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * AdminPolicyOwnershipTest — the kernel's admin policy knows a prefix and no
 * permission.
 *
 * Measured 2026-11-12: `AdminRoutePolicy` carried the admin path => permission
 * table plus five readers (`permits()`, `requiredPermission()`,
 * `permissionForEntity()`, `bulkEntities()`, `prefixes()`), and nothing under
 * `src/`, `modules/`, `bin/` or `public/` called any of them. Who may open which
 * panel path is the PANEL's policy — it classifies the routes the panel module
 * declares — so the table moved out (`ab09fea`) and `Permissions.php` went with
 * it. The kernel kept the one fact it actually uses before routing: that `/mark`
 * exists and how it is spelled here.
 *
 * Two failure modes this guard exists for, neither of which a green suite would
 * notice:
 *
 *  1. **The table walks back in.** A `permits()` helper added to
 *     `AdminRoutePolicy` "while we are here" looks like convenience and makes
 *     the kernel own a rule it does not apply. `prefixes()` is deliberately NOT
 *     in the vocabulary below: it is a shape a router may legitimately have, so
 *     banning the word would be a naming preference, not an ownership rule.
 *  2. **The vocabulary reappears elsewhere in Core.** A new
 *     `Modules\Core\Domain\Permissions` class, or a second reader, recreates the
 *     same split with a new file name — the kernel states a permission and the
 *     routes' owner cannot correct it.
 *
 * Read as tokens, not as text. The `AdminRoutePolicy` docblock names every
 * method that left, and `SecurityHeadersMiddleware` sends a `Permissions-Policy`
 * header — a grep would flag both and teach the next reader to mute the gate.
 * A comment is a `T_DOC_COMMENT` token and a header value is a string literal;
 * neither is an identifier, so neither is a symbol. The blind spot is honest and
 * narrow: a permission dispatched through a string (`$policy->{'permits'}()`)
 * would not be seen here, and that is not how this tree calls anything.
 *
 * @internal
 *
 * @coversNothing
 */
final class AdminPolicyOwnershipTest extends TestCase
{
    /**
     * Every public method the kernel's admin policy is allowed to have.
     *
     * The coverage, not the count: a method added here is a decision that the
     * kernel owns that question, and this list is where the decision is
     * recorded. It fails closed — a new public method is a failing test until
     * somebody writes it down.
     *
     * @var list<string>
     */
    private const array PREFIX_SURFACE = ['canonicalPath', 'isAdminPath', 'prefix', 'publicPath'];

    /**
     * The permission half's vocabulary, as it existed in `ab09fea`'s parent.
     *
     * Names, not paths: a permission reader moved to another kernel file is the
     * same regression, and a path-keyed ledger would call it a new one.
     *
     * @var list<string>
     */
    private const array PERMISSION_VOCABULARY = [
        'Permissions',
        'permits',
        'requiredPermission',
        'permissionForEntity',
        'bulkEntities',
    ];

    /**
     * The admin policy publishes prefix arithmetic and nothing else.
     *
     * `prefix()`, `canonicalPath()` and `publicPath()` run before or inside
     * routing, so they belong to the kernel. Anything permission-shaped belongs
     * to the module that declares the panel's routes.
     */
    public function testTheAdminPolicyPublishesOnlyPrefixArithmetic(): void
    {
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(AdminRoutePolicy::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        $expected = self::PREFIX_SURFACE;
        sort($expected);

        self::assertSame(
            $expected,
            $methods,
            'AdminRoutePolicy\'s public surface changed. The kernel knows the admin PREFIX and no permission: '
            . 'who may open which path is the panel module\'s policy (`modules/Cardboard` in the source tree, '
            . 'absent from this export profile). If the kernel genuinely needs a new method here, add it to '
            . 'PREFIX_SURFACE and say why in the docblock — do not re-grow a rule the kernel does not apply.',
        );
    }

    /**
     * No kernel file names a permission.
     *
     * `Modules\Core` may talk about paths, headers and middleware; the moment it
     * names `permits()` or a `Permissions` class it is stating a rule whose owner
     * is elsewhere.
     */
    public function testTheKernelNamesNoPermission(): void
    {
        $offenders = [];

        foreach (self::kernelFiles() as $path) {
            $code = file_get_contents($path);

            if (false === $code) {
                continue;
            }

            foreach (self::permissionNamesIn($code) as [$line, $name]) {
                $offenders[] = sprintf('%s:%d names %s', self::relative($path), $line, $name);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "The kernel's admin policy grew a permission back:\n" . implode("\n", $offenders)
            . "\n\nWho may open which /mark path is the panel module's policy — it declares the routes, so it "
            . 'can answer "this path has no row, so deny". The kernel states no permission and grants nothing.',
        );
    }

    /**
     * `Permissions.php` does not come back to Core.
     *
     * The vocabulary scan above catches a symbol that is USED; this catches the
     * file itself, which is what a reader would import first.
     */
    public function testThePermissionsClassStaysOutOfTheKernel(): void
    {
        self::assertFileDoesNotExist(
            AppPaths::instance()->join('src', 'Modules', 'Core', 'Domain', 'Permissions.php'),
            'The kernel\'s permission table belongs to the panel module that declares the routes it '
            . 'classifies. The kernel keeps prefix arithmetic in AdminRoutePolicy and nothing else.',
        );
    }

    /**
     * The scanner must find something.
     *
     * Every rule above compares against a walk. A moved root or a changed
     * extension would leave the offender list empty and the guard green while
     * asserting nothing, so the walk has to prove it read the tree: 39 PHP files
     * under `src/Modules/Core` when this test was written.
     */
    public function testTheScannerFindsTheKernel(): void
    {
        $files = self::kernelFiles();

        self::assertGreaterThan(
            30,
            \count($files),
            'the walk found ' . \count($files) . ' PHP files under src/Modules/Core where ~39 exist — '
            . 'the scan is traversing only part of the tree, and the rule above would cover almost nothing',
        );
    }

    /**
     * The names in `PERMISSION_VOCABULARY` that appear as identifiers.
     *
     * @return list<array{int, string}>
     */
    private static function permissionNamesIn(string $code): array
    {
        $found = [];

        foreach (token_get_all($code) as $token) {
            if (!\is_array($token)) {
                continue;
            }

            // A qualified name (`Modules\Core\Domain\Permissions`) arrives as one
            // token; its last segment is the symbol a reader would recognise.
            if (T_STRING !== $token[0] && T_NAME_QUALIFIED !== $token[0] && T_NAME_FULLY_QUALIFIED !== $token[0]) {
                continue;
            }

            $segments = explode('\\', $token[1]);
            $name = end($segments);

            if (\in_array($name, self::PERMISSION_VOCABULARY, true)) {
                $found[] = [$token[2], $name];
            }
        }

        return $found;
    }

    /**
     * Every PHP file under the kernel module, sorted for a stable failure order.
     *
     * @return list<string>
     */
    private static function kernelFiles(): array
    {
        $directory = AppPaths::instance()->join('src', 'Modules', 'Core');

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * A path relative to the repository root, with forward slashes.
     */
    private static function relative(string $path): string
    {
        $base = AppPaths::instance()->base();
        $relative = str_starts_with($path, $base) ? substr($path, \strlen($base) + 1) : $path;

        return str_replace('\\', '/', $relative);
    }
}
