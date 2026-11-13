<?php

declare(strict_types = 1);

namespace Tests\Modules;

use Modules\Tooling\ModuleInstaller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The contract behind “Install with dependencies”: the plan is complete before
 * the first copy, dependencies land before the module that imports them, and a
 * cycle or an unknown dependency leaves the tree untouched.
 */
final class ModuleDependencyInstallTest extends TestCase
{
    private const string ROOT = 'TempRoot';

    private const string DEPENDENCY = 'TempDep';

    private const string CYCLE_A = 'TempCycleA';

    private const string CYCLE_B = 'TempCycleB';

    private const string NEEDS_NOPE = 'TempNeedsNope';

    #[Test]
    public function installsDependenciesBeforeTheRequestedModule(): void
    {
        self::cleanup(self::ROOT, self::DEPENDENCY);

        try {
            self::writeModule(
                self::DEPENDENCY,
                "namespace Modules\\TempDep;\n\nfinal class TempDep\n{\n}",
            );
            self::writeModule(
                self::ROOT,
                "namespace Modules\\TempRoot;\n\nuse Modules\\TempDep\\TempDep;\n\nfinal class TempRoot\n{\n    public function dependency(): string\n    {\n        return TempDep::class;\n    }\n}",
            );

            $result = ModuleInstaller::fromAppPaths()->addWithDependencies(self::ROOT);

            $this->assertSame(
                [self::DEPENDENCY, self::ROOT],
                $result['installed'],
                'The dependency must be copied before the module that imports it.',
            );
            $this->assertGreaterThanOrEqual(2, $result['copied']);
            $this->assertDirectoryExists(APP_BASE_PATH . '/src/Modules/' . self::DEPENDENCY);
            $this->assertDirectoryExists(APP_BASE_PATH . '/src/Modules/' . self::ROOT);
        } finally {
            self::cleanup(self::ROOT, self::DEPENDENCY);
        }
    }

    #[Test]
    public function refusesACycleBeforeCopyingAnything(): void
    {
        self::cleanup(self::CYCLE_A, self::CYCLE_B);

        try {
            self::writeModule(
                self::CYCLE_A,
                "namespace Modules\\TempCycleA;\n\nuse Modules\\TempCycleB\\TempCycleB;\n\nfinal class TempCycleA\n{\n    public function dependency(): string\n    {\n        return TempCycleB::class;\n    }\n}",
            );
            self::writeModule(
                self::CYCLE_B,
                "namespace Modules\\TempCycleB;\n\nuse Modules\\TempCycleA\\TempCycleA;\n\nfinal class TempCycleB\n{\n    public function dependency(): string\n    {\n        return TempCycleA::class;\n    }\n}",
            );

            try {
                ModuleInstaller::fromAppPaths()->addWithDependencies(self::CYCLE_A);
                $this->fail('A dependency cycle should refuse the install.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('dependency cycle', $e->getMessage());
                $this->assertStringContainsString(self::CYCLE_A, $e->getMessage());
                $this->assertStringContainsString(self::CYCLE_B, $e->getMessage());
            }

            $this->assertDirectoryDoesNotExist(APP_BASE_PATH . '/src/Modules/' . self::CYCLE_A);
            $this->assertDirectoryDoesNotExist(APP_BASE_PATH . '/src/Modules/' . self::CYCLE_B);
        } finally {
            self::cleanup(self::CYCLE_A, self::CYCLE_B);
        }
    }

    #[Test]
    public function refusesADependencyThatIsNotInTheCatalogue(): void
    {
        self::cleanup(self::NEEDS_NOPE);

        try {
            self::writeModule(
                self::NEEDS_NOPE,
                "namespace Modules\\TempNeedsNope;\n\nuse Modules\\Nope\\Nope;\n\nfinal class TempNeedsNope\n{\n    public function dependency(): string\n    {\n        return Nope::class;\n    }\n}",
            );

            try {
                ModuleInstaller::fromAppPaths()->addWithDependencies(self::NEEDS_NOPE);
                $this->fail('A non-catalogued dependency should refuse the install.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('missing dependencies: Nope', $e->getMessage());
            }

            $this->assertDirectoryDoesNotExist(APP_BASE_PATH . '/src/Modules/' . self::NEEDS_NOPE);
        } finally {
            self::cleanup(self::NEEDS_NOPE);
        }
    }

    /**
     * A catalogue fixture with one PHP file, which is all the closure scanner
     * needs to read the `use Modules\<Name>\…` edge.
     */
    private static function writeModule(string $name, string $body): void
    {
        $dir = APP_BASE_PATH . '/modules/' . $name . '/src';

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create fixture directory: ' . $dir);
        }

        $file = $dir . '/' . $name . '.php';
        $source = "<?php\n\ndeclare(strict_types = 1);\n\n" . $body . "\n";

        if (false === file_put_contents($file, $source)) {
            throw new RuntimeException('Cannot write fixture file: ' . $file);
        }
    }

    /**
     * Remove installed slices and catalogue fixtures, always attempting both
     * sides even when one removal is already gone.
     */
    private static function cleanup(string ...$names): void
    {
        /** @var list<RuntimeException> $failures */
        $failures = [];

        foreach ($names as $name) {
            try {
                self::removeInstalled($name);
            } catch (RuntimeException $e) {
                $failures[] = $e;
            }

            try {
                self::deleteCatalogue($name);
            } catch (RuntimeException $e) {
                $failures[] = $e;
            }
        }

        if ([] !== $failures) {
            throw $failures[0];
        }
    }

    /**
     * @throws RuntimeException
     */
    private static function removeInstalled(string $name): void
    {
        try {
            ModuleInstaller::fromAppPaths()->remove($name, true);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'is not installed')) {
                throw $e;
            }
        }
    }

    /**
     * @throws RuntimeException
     */
    private static function deleteCatalogue(string $name): void
    {
        $dir = APP_BASE_PATH . '/modules/' . $name;

        if (is_dir($dir)) {
            ModuleInstaller::deleteTree($dir);
        }
    }
}
