<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use Modules\Core\Infrastructure\Paths\AppPaths;
use PHPUnit\Framework\TestCase;

/**
 * KernelShapeTest — the guard that makes the core kernel refactor stick.
 *
 * The refactor's promise is not a byte count; it is a *shape*: a contract kernel
 * (interfaces, enums, traits) plus a concrete runtime that is approved file by
 * file and can shrink but not silently grow. `make kernel-size` measures that
 * shape on demand, `docs/kernel-shape.md` classifies every file into a bucket —
 * and this test is what stops the two from describing a tree that no longer
 * exists the moment somebody adds a class.
 *
 * Two rules, two failures this exists for, neither visible from a green suite:
 *
 *  1. **A new concrete class appears in the kernel.** It lands as a
 *     `runtime`-looking file nobody classified, and the contract surface grows
 *     by one implementation the kernel now has to carry. The test fails until
 *     the file is added to `ALLOW_LIST` *and* the inventory row is written —
 *     approval is a decision with a paper trail, not a side effect of typing
 *     `class`.
 *  2. **The allow-list grows.** A baseline that can be raised by editing a
 *     constant in the same commit as the class it approves is not a baseline.
 *     `MAX_APPROVED` is asserted separately so raising it shows up as its own
 *     red line in review.
 *
 * Declarations are read as **tokens**, never as text — the rule
 * `TextFirstFqcnTest` pins for the discovery resolvers: a declaration is the
 * first `class` / `interface` / `trait` / `enum` keyword that is not prose
 * (comments are their own tokens, so a docblock cannot be one) and not a
 * `::class` constant (`Foo::class` tokenizes its `class` too, which is why the
 * token before it is checked). Anything else in the file — a docblock sentence
 * about "this class", an interpolated `${x}` — cannot register as a type.
 *
 * The inventory is the approval record: `docs/kernel-shape.md`, Task 2 of
 * `.ai-factory/plans/feature-kernel-contracts.md`.
 *
 * @internal
 *
 * @coversNothing
 */
final class KernelShapeTest extends TestCase
{
    /**
     * Every concrete class in `src/Modules/Core` that has been justified in
     * `docs/kernel-shape.md` — the `contract` bucket's value objects and
     * discovery attributes plus the `runtime` bucket's boot, dispatch, render,
     * cache and middleware classes.
     *
     * The three `tooling` classes (ModuleCatalog, ModuleInstaller,
     * ModuleManifest) left for `src/Modules/Tooling` in Phase 4 and the five
     * `dead` / dead-CLI files were deleted in Phase 3, which is where this list
     * got its 67 → 59 movement (60 since the auth-provider discovery round
     * resurrected one runtime file — see `docs/kernel-shape.md`, Movement). An
     * interface, enum or trait needs no row: the
     * shape wants them, so they are free.
     *
     * @var list<string>
     */
    private const array ALLOW_LIST = [
        'src/Modules/Core/Application/Services/AssetManager.php',
        'src/Modules/Core/Application/Services/AuthProviderRegistry.php',
        'src/Modules/Core/Application/Services/CacheInvalidator.php',
        'src/Modules/Core/Application/Services/ContentLocaleMapper.php',
        'src/Modules/Core/Application/Services/DiscoveryCache.php',
        'src/Modules/Core/Application/Services/DisposalRegistry.php',
        'src/Modules/Core/Application/Services/DocsCatalog.php',
        'src/Modules/Core/Application/Services/DocsSearchService.php',
        'src/Modules/Core/Application/Services/MarkdownRenderer.php',
        'src/Modules/Core/Application/Services/PageCacheService.php',
        'src/Modules/Core/Application/Services/PreferenceVote.php',
        'src/Modules/Core/Application/Services/ShellContextProviderRegistry.php',
        'src/Modules/Core/Application/Services/StructuredDataService.php',
        'src/Modules/Core/Application/Services/View/InlineCss.php',
        'src/Modules/Core/Application/Services/View/InlineJs.php',
        'src/Modules/Core/Application/Services/View/Paths.php',
        'src/Modules/Core/Application/Services/View/ViewRenderer.php',
        'src/Modules/Core/Domain/AdminRoutePolicy.php',
        'src/Modules/Core/Domain/AuthIdentity.php',
        'src/Modules/Core/Domain/Permissions.php',
        'src/Modules/Core/Domain/ShellNavItem.php',
        'src/Modules/Core/Domain/SubmitResult.php',
        'src/Modules/Core/Domain/ThemePreference.php',
        'src/Modules/Core/Domain/ThemeSetting.php',
        'src/Modules/Core/Domain/ThemeSettingValues.php',
        'src/Modules/Core/Infrastructure/Attributes/AsAuthProvider.php',
        'src/Modules/Core/Infrastructure/Attributes/AsMarkdownBlock.php',
        'src/Modules/Core/Infrastructure/Attributes/AsMiddleware.php',
        'src/Modules/Core/Infrastructure/Attributes/AsMigrator.php',
        'src/Modules/Core/Infrastructure/Attributes/AsNavBadge.php',
        'src/Modules/Core/Infrastructure/Attributes/AsShellContextProvider.php',
        'src/Modules/Core/Infrastructure/Bootstrap/Bootstrapper.php',
        'src/Modules/Core/Infrastructure/Env.php',
        'src/Modules/Core/Infrastructure/Http/JsonResponse.php',
        'src/Modules/Core/Infrastructure/Http/Request.php',
        'src/Modules/Core/Infrastructure/Http/Response.php',
        'src/Modules/Core/Infrastructure/Paths/AppPaths.php',
        'src/Modules/Core/Infrastructure/Registry/NavBadgeRegistry.php',
        'src/Modules/Core/Infrastructure/Session/FileSessionHandler.php',
        'src/Modules/Core/Infrastructure/Storage/LocalStorageProvider.php',
        'src/Modules/Core/Interfaces/CLI/Support/DatabaseMigrator.php',
        'src/Modules/Core/Interfaces/HTTP/Actions/DocsIndexAction.php',
        'src/Modules/Core/Interfaces/HTTP/Actions/DocsPageAction.php',
        'src/Modules/Core/Interfaces/HTTP/Actions/HomepageAction.php',
        'src/Modules/Core/Interfaces/HTTP/Actions/LlmsTxtAction.php',
        'src/Modules/Core/Interfaces/HTTP/Actions/RobotsAction.php',
        'src/Modules/Core/Interfaces/HTTP/Actions/SitemapAction.php',
        'src/Modules/Core/Interfaces/HTTP/Attributes/Route.php',
        'src/Modules/Core/Interfaces/HTTP/ContentServices.php',
        'src/Modules/Core/Interfaces/HTTP/ErrorPageRenderer.php',
        'src/Modules/Core/Interfaces/HTTP/FrontendLayout.php',
        'src/Modules/Core/Interfaces/HTTP/Kernel.php',
        'src/Modules/Core/Interfaces/HTTP/Markdown/MarkdownBlockContext.php',
        'src/Modules/Core/Interfaces/HTTP/Markdown/MarkdownBlockRegistry.php',
        'src/Modules/Core/Interfaces/HTTP/Middleware/AuthMiddleware.php',
        'src/Modules/Core/Interfaces/HTTP/Middleware/CsrfMiddleware.php',
        'src/Modules/Core/Interfaces/HTTP/Middleware/MiddlewareRegistry.php',
        'src/Modules/Core/Interfaces/HTTP/Middleware/PageCacheMiddleware.php',
        'src/Modules/Core/Interfaces/HTTP/Middleware/RateLimitMiddleware.php',
        'src/Modules/Core/Interfaces/HTTP/Middleware/SecurityHeadersMiddleware.php',
        'src/Modules/Core/Interfaces/HTTP/PageContext.php',
        'src/Modules/Core/Interfaces/HTTP/Router.php',
        'src/Modules/Core/Interfaces/HTTP/ShellContext.php',
        'src/Modules/Core/Interfaces/HTTP/ThemeKernel.php',
        'src/Modules/Core/Interfaces/HTTP/ThemeRegistry.php',
        'src/Modules/Core/Interfaces/HTTP/ThemeResolver.php',
    ];

    /**
     * The number of approved concrete classes, asserted as the contract.
     *
     * Not `count(self::ALLOW_LIST)` — that would assert the list equals itself
     * and guard nothing. This constant is the ceiling the refactor reached
     * (67 concrete at the baseline → 59 after the dead code left and the
     * tooling moved, **60** once `AuthProviderRegistry` came back for the
     * auth-provider discovery round, **64** once the per-theme settings
     * contract arrived, **65** with `SubmitResult`), so it is written down as
     * a number a reviewer can argue with: lowering it is the work, raising it
     * is a deliberate edit with its own red line.
     *
     * 64 → 65 is the boolean-result round and nothing else: `SubmitResult` is
     * the `ok`/`errors` answer four services used to spell as an array, with
     * the "`ok` ⇒ no errors" invariant now in its constructors. It is a
     * `contract` row in docs/kernel-shape.md and stores nothing.
     */
    private const int MAX_APPROVED = 66;

    /**
     * Rule 1: every `.php` file under `src/Modules/Core` is an interface, enum
     * or trait, or a concrete class someone has approved in writing — and every
     * approval still points at a file that is there.
     */
    public function testEveryConcreteClassInTheKernelIsApproved(): void
    {
        $files = self::coreFiles();
        $concrete = [];

        // The walk must find the tree. A moved directory, a changed extension or
        // a typo in the path would leave both lists empty, the set equality
        // true, and this guard asserting nothing while the suite stayed green —
        // the failure mode KernelCouplingTest's scanner check exists for, and
        // the same reason it reads a signal that cannot go quiet. Core carries
        // 70 files (67 → 60 concrete + 10 interfaces: Phases 3-4, then the
        // resurrected AuthProviderRegistry).
        self::assertGreaterThan(
            50,
            \count($files),
            'the shape scan found ' . \count($files) . ' files under src/Modules/Core — the walk or the '
            . 'tokenizer stopped working, and every rule below would then approve zero files while the '
            . 'suite stayed green',
        );

        // The same signal for the approval record: an unreadable or moved
        // inventory would hand back zero rows and make the "approved but not
        // inventoried" check below vacuous instead of loud.
        self::assertGreaterThan(
            50,
            \count(self::inventoryRows()),
            'docs/kernel-shape.md yielded ' . \count(self::inventoryRows()) . ' inventory rows — the '
            . 'table is gone, unreadable, or its format changed, so every approval below would read '
            . 'as undocumented while the suite stayed green',
        );

        $problems = [];

        foreach ($files as $path) {
            $code = file_get_contents($path);
            if (false === $code) {
                $problems[] = 'UNREADABLE   ' . $path . ' could not be read — the guard cannot approve '
                    . 'what it cannot see';

                continue;
            }

            $file = self::relative($path);
            $kind = self::declarationKind($code);

            if (null === $kind) {
                $problems[] = sprintf(
                    'NO DECLARATION %s declares no type at all — a kernel file that is neither contract '
                    . 'nor approved runtime. Classify it in docs/kernel-shape.md',
                    $file,
                );

                continue;
            }

            if ('class' !== $kind) {
                continue;
            }

            $concrete[] = $file;

            if (\in_array($file, self::ALLOW_LIST, true)) {
                continue;
            }

            $problems[] = sprintf(
                'UNAPPROVED   %s declares a concrete class (layer %s) — approval lives in '
                . 'docs/kernel-shape.md: add the inventory row there and the path to ALLOW_LIST here, '
                . 'or make the type a contract (interface/enum/trait) instead',
                $file,
                self::layerOf($file),
            );
        }

        // The other direction: an approval whose file moved, was renamed or was
        // deleted reads as policy while describing nothing — the stale-ledger
        // failure KernelCouplingTest pins, in the shape's own currency.
        foreach (self::ALLOW_LIST as $approved) {
            if (\in_array($approved, $concrete, true)) {
                continue;
            }

            $problems[] = sprintf(
                'STALE APPROVAL %s is on the allow-list but no longer declares a concrete class under '
                . 'src/Modules/Core — the file moved, was renamed or was deleted; delete the row',
                $approved,
            );
        }

        // Approval is two records, not one: the path here and the row in the
        // inventory that says what bucket it is and why. A path approved in the
        // test with no row is a class the inventory never classified — which is
        // the drift the doc's own header claims this test prevents.
        $inventoried = self::inventoryRows();

        foreach (self::ALLOW_LIST as $approved) {
            if (\in_array($approved, $inventoried, true)) {
                continue;
            }

            $problems[] = sprintf(
                'UNINVENTORIED  %s is on the allow-list but has no row in docs/kernel-shape.md — '
                . 'an approved class nobody classified; add the inventory row (bytes, layer, type, '
                . 'importers, bucket) or remove the approval',
                $approved,
            );
        }

        self::assertSame(
            [],
            $problems,
            "The kernel's shape has drifted:\n" . implode("\n", $problems)
            . "\n\nApproved: " . \count(self::ALLOW_LIST) . ' · concrete found: ' . \count($concrete)
            . "\nApproval record: docs/kernel-shape.md (one row per file, bucket column)",
        );
    }

    /**
     * Rule 2: the allow-list only shrinks.
     *
     * Kept as its own test so that raising the ceiling cannot hide inside an
     * approval commit — the two failures need different sentences, and a single
     * assertion would make the reviewer guess which one fired.
     */
    public function testTheAllowListOnlyShrinks(): void
    {
        self::assertLessThanOrEqual(
            self::MAX_APPROVED,
            \count(self::ALLOW_LIST),
            'the kernel approved ' . \count(self::ALLOW_LIST) . ' concrete classes against a ceiling of '
            . self::MAX_APPROVED . '. This number may fall freely but must never rise: shrinking it is '
            . 'the work, and raising it is a deliberate, reviewable edit — change MAX_APPROVED together '
            . 'with the inventory row in docs/kernel-shape.md and say why in the commit message, or '
            . 'turn the class into a contract',
        );
    }

    /**
     * The first type declaration in the file, as tokens.
     *
     * Returns `interface`, `trait` or `enum` for the free shapes and `class`
     * for a concrete type (or an abstract one, which is concrete enough to
     * need approval), null when the file declares nothing.
     *
     * `Foo::class` and `new class {}` both tokenize a `class` keyword that
     * declares nothing, so the preceding meaningful token decides: a double
     * colon or a `new` means the keyword is an operand, not a declaration.
     * Comments are separate tokens, which is the whole point — prose in a
     * docblock cannot be mistaken for a declaration.
     */
    private static function declarationKind(string $code): ?string
    {
        $previous = null;

        foreach (token_get_all($code) as $token) {
            if (!\is_array($token)) {
                $previous = $token;

                continue;
            }

            if (T_WHITESPACE === $token[0] || T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0]) {
                continue;
            }

            if (\in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $isClassKeyword = T_CLASS === $token[0];

                if ($isClassKeyword && \in_array($previous, [T_PAAMAYIM_NEKUDOTAYIM, T_NEW], true)) {
                    $previous = $token[0];

                    continue;
                }

                return match (true) {
                    T_INTERFACE === $token[0] => 'interface',
                    T_TRAIT === $token[0] => 'trait',
                    T_ENUM === $token[0] => 'enum',
                    default => 'class',
                };
            }

            $previous = $token[0];
        }

        return null;
    }

    /**
     * Every `.php` file under `src/Modules/Core`, sorted for a stable order.
     *
     * @return list<string>
     */
    private static function coreFiles(): array
    {
        $directory = AppPaths::instance()->join('src', 'Modules', 'Core');
        if (!is_dir($directory)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        // `sort()` re-indexes, so the result is already a list — the same
        // phpstan note tests/Support/KernelCoupling.php documents.
        sort($files);

        return $files;
    }

    /**
     * Every path the inventory table in `docs/kernel-shape.md` has a row for.
     *
     * Read as text on purpose: the table is prose a human maintains, so the
     * only shape this may rely on is the one the format guarantees — a path in
     * backticks at the head of a row. Rows whose file was deleted or moved keep
     * their path (the baseline does not move), so this list is wider than the
     * allow-list, and only the direction "approved but not inventoried" is a
     * problem.
     *
     * @return list<string>
     */
    private static function inventoryRows(): array
    {
        $path = AppPaths::instance()->join('docs', 'kernel-shape.md');
        $markdown = is_file($path) ? file_get_contents($path) : false;

        if (false === $markdown) {
            return [];
        }

        $matched = preg_match_all('/^\|\s*`(src\/Modules\/Core\/[^`]+)`\s*\|/m', $markdown, $matches);

        // `$matches[1]` is non-nullable once the pattern matched, so the
        // coalesce phpstan flags here would be dead code — the branch that can
        // be empty is the one above: no match at all (format changed, or a
        // table that shrank to zero rows).
        if (!\is_int($matched) || $matched < 1) {
            return [];
        }

        $unique = array_values(array_unique($matches[1]));
        sort($unique);

        return $unique;
    }

    /**
     * The top-level layer a kernel path sits in — the column the inventory
     * groups by, printed in the failure so nobody has to open the file to know
     * which half of the kernel grew.
     */
    private static function layerOf(string $file): string
    {
        $position = strpos($file, 'src/Modules/Core/');
        if (false === $position) {
            return '(outside the kernel?)';
        }

        $rest = substr($file, $position + \strlen('src/Modules/Core/'));
        $slash = strpos($rest, '/');

        return false === $slash ? '(root)' : substr($rest, 0, $slash);
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
