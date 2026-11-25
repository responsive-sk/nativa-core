<?php

declare(strict_types = 1);

namespace Tests\Discovery;

use Modules\Tooling\ModuleInstaller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shell\HomepageSurfaceRegistry;

/**
 * HomepageSurfaceRegistryTest — the kernel's answer to "who owns `/`".
 *
 * The registry is reflection over installed files, which means its failure mode
 * is not an exception: it is a page that quietly belongs to somebody else. Three
 * ways that happens, and none of them raises anything:
 *
 *   1. priority ignored — a module that asked to lose wins;
 *   2. a tie decided by `glob()` order — the owner of `/` depends on the
 *      filesystem the deployment happens to have;
 *   3. a class the tree cannot instantiate being used anyway — an attribute on
 *      something that is not a surface, or a constructor the registry cannot
 *      call.
 *
 * ## The fixture
 *
 * Throwaway surfaces in an installed slice, created and deleted exactly the way
 * `ShellContextCopyMergeTest` builds its providers. Classes only, assertions on
 * class names: the DTO needs a `PageContext` this test has no business building,
 * and the question here is WHICH surface answers, not what it renders.
 *
 * The fixture priorities are `-20`, `5`, `5` and `20`, so a real installed
 * surface at the default `0` sits between them and the ordering assertions hold
 * on a full tree and on a kernel-only one alike.
 */
final class HomepageSurfaceRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        HomepageSurfaceRegistry::reset();
    }

    protected function tearDown(): void
    {
        self::deleteFixture();
        HomepageSurfaceRegistry::reset();

        parent::tearDown();
    }

    #[Test]
    public function theLowestPriorityOwnsThePage(): void
    {
        try {
            self::createFixture();
            HomepageSurfaceRegistry::reset();

            $first = HomepageSurfaceRegistry::first();
        } finally {
            self::deleteFixture();
            HomepageSurfaceRegistry::reset();
        }

        self::assertNotNull($first);
        self::assertSame(
            self::fqcn('Low'),
            $first::class,
            'The surface that asked for the lowest priority must own `/`, even with others installed.',
        );
    }

    #[Test]
    public function theSurfacesComeBackInPriorityOrder(): void
    {
        try {
            self::createFixture();
            HomepageSurfaceRegistry::reset();

            $rows = HomepageSurfaceRegistry::allWithPriority();
        } finally {
            self::deleteFixture();
            HomepageSurfaceRegistry::reset();
        }

        $priorities = array_column($rows, 0);
        $sorted = $priorities;
        sort($sorted);

        self::assertSame($sorted, $priorities, 'Surfaces must answer in ascending priority order.');

        $classes = array_map(static fn (array $row): string => $row[1]::class, $rows);
        $low = array_search(self::fqcn('Low'), $classes, true);
        $high = array_search(self::fqcn('High'), $classes, true);

        self::assertIsInt($low);
        self::assertIsInt($high);
        self::assertLessThan($high, $low, 'The fixture surface with priority -20 must precede the one with 20.');
    }

    /**
     * Two modules can pick the same priority by accident. The answer must not
     * then depend on the order `glob()` returned the files in — that is the coin
     * flip `HomepageSurface` exists to avoid, arriving one level up.
     */
    #[Test]
    public function aTieIsBrokenByClassNameNotByDiscoveryOrder(): void
    {
        try {
            self::createFixture();
            HomepageSurfaceRegistry::reset();
            $firstScan = array_map(
                static fn (array $row): string => $row[1]::class,
                HomepageSurfaceRegistry::allWithPriority(),
            );

            HomepageSurfaceRegistry::reset();
            $secondScan = array_map(
                static fn (array $row): string => $row[1]::class,
                HomepageSurfaceRegistry::allWithPriority(),
            );
        } finally {
            self::deleteFixture();
            HomepageSurfaceRegistry::reset();
        }

        $tied = array_values(array_filter(
            $firstScan,
            static fn (string $class): bool => in_array($class, [self::fqcn('TieAlpha'), self::fqcn('TieBeta')], true),
        ));

        self::assertSame(
            [self::fqcn('TieAlpha'), self::fqcn('TieBeta')],
            $tied,
            'Surfaces at equal priority must break the tie by class name, alphabetically.',
        );
        self::assertSame($firstScan, $secondScan, 'Two scans of the same tree must answer the same.');
    }

    /**
     * The registry reflects over files whose name says `…HomepageSurface.php`.
     * A file can still be a class that is not a surface, a surface with no
     * attribute, or one whose constructor needs arguments the registry cannot
     * supply. Each must be skipped, not fatal and not used.
     */
    #[Test]
    public function aClassTheTreeCannotUseIsSkippedRatherThanUsed(): void
    {
        try {
            self::createFixture();
            HomepageSurfaceRegistry::reset();

            $classes = array_map(
                static fn (array $row): string => $row[1]::class,
                HomepageSurfaceRegistry::allWithPriority(),
            );
        } finally {
            self::deleteFixture();
            HomepageSurfaceRegistry::reset();
        }

        foreach (['NoAttribute', 'NeedsArgument', 'NotASurface'] as $skipped) {
            self::assertNotContains(
                self::fqcn($skipped),
                $classes,
                $skipped . ' cannot serve `/` and must not appear among the surfaces.',
            );
        }

        self::assertContains(self::fqcn('Low'), $classes, 'A usable surface in the same slice must still be found.');
    }

    /**
     * Nothing installed answers null and the kernel renders its own landing —
     * the thin floor, not an error.
     *
     * On a tree with a real surface installed this branch is unreachable by
     * construction (the registry would have to answer the installed one), so the
     * case is skipped here and exercised for real by the kernel-only export
     * check, where `src/Modules` holds no site module.
     */
    #[Test]
    public function nothingInstalledAnswersNull(): void
    {
        if ([] !== HomepageSurfaceRegistry::allWithPriority()) {
            $this->markTestSkipped('A module owns `/` in this tree; the kernel-only run covers the null answer.');
        }

        self::assertNull(HomepageSurfaceRegistry::first(), 'No surface installed must answer null, not an error.');
    }

    /**
     * The fixture's FQCN, from its short name — `'Low'` is
     * `Modules\TempHome\Interfaces\HTTP\TempHomeLowHomepageSurface`.
     *
     * A method and not eight constants on purpose: this tree's architecture gate
     * counts `const` declarations and refuses a rise, and a test does not get to
     * raise it.
     */
    private static function fqcn(string $short): string
    {
        return 'Modules\\TempHome\\Interfaces\\HTTP\\' . self::className($short);
    }

    private static function className(string $short): string
    {
        return 'TempHome' . $short . 'HomepageSurface';
    }

    private static function createFixture(): void
    {
        $dir = APP_BASE_PATH . '/src/Modules/TempHome/Interfaces/HTTP';

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create homepage-surface fixture dir: ' . $dir);
        }

        self::writeSurface($dir, 'Low', priority: -20);
        self::writeSurface($dir, 'High', priority: 20);
        self::writeSurface($dir, 'TieAlpha', priority: 5);
        self::writeSurface($dir, 'TieBeta', priority: 5);

        // Skipped: the attribute is what makes a class a surface.
        file_put_contents($dir . '/' . self::className('NoAttribute') . '.php', self::surfaceSource(
            class: self::className('NoAttribute'),
            attribute: null,
        ));

        // Skipped: the registry instantiates surfaces itself and cannot call this.
        file_put_contents($dir . '/' . self::className('NeedsArgument') . '.php', self::surfaceSource(
            class: self::className('NeedsArgument'),
            attribute: '#[AsHomepageSurface]',
            constructor: 'public function __construct(private string $required)' . "\n    {\n    }\n",
        ));

        // Skipped: carries the attribute, implements nothing.
        file_put_contents($dir . '/' . self::className('NotASurface') . '.php', <<<'PHP'
            <?php

            declare(strict_types = 1);

            namespace Modules\TempHome\Interfaces\HTTP;

            use Shell\AsHomepageSurface;

            /** Fixture: named like a surface, marked like one, and not one. */
            #[AsHomepageSurface]
            final class TempHomeNotASurfaceHomepageSurface
            {
            }

            PHP);
    }

    private static function writeSurface(string $dir, string $short, int $priority): void
    {
        $class = self::className($short);

        file_put_contents(
            $dir . '/' . $class . '.php',
            self::surfaceSource(class: $class, attribute: '#[AsHomepageSurface(priority: ' . $priority . ')]'),
        );
    }

    private static function surfaceSource(string $class, ?string $attribute, string $constructor = ''): string
    {
        $attributeLine = null === $attribute ? '' : $attribute . "\n";

        return <<<PHP
            <?php

            declare(strict_types = 1);

            namespace Modules\\TempHome\\Interfaces\\HTTP;

            use Shell\\AsHomepageSurface;
            use Shell\\Homepage;
            use Shell\\HomepageSurface;
            use Shell\\PageContext;

            /** Fixture: answers a marker string, so the assertion is about choice, not rendering. */
            {$attributeLine}final class {$class} implements HomepageSurface
            {
                {$constructor}
                public function homepage(PageContext \$context): Homepage
                {
                    return new Homepage('{$class}');
                }
            }

            PHP;
    }

    private static function deleteFixture(): void
    {
        $dir = APP_BASE_PATH . '/src/Modules/TempHome';

        if (is_dir($dir)) {
            ModuleInstaller::deleteTree($dir);
        }
    }
}
