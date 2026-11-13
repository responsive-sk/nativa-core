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
    #[Test]
    public function installsDependenciesBeforeTheRequestedModule(): void
    {
        $root = 'TempRoot';
        $dependency = 'TempDep';

        self::cleanup($root, $dependency);

        try {
            self::writeModule(
                $dependency,
                "namespace Modules\\TempDep;\n\nfinal class TempDep\n{\n}",
            );
            self::writeModule(
                $root,
                "namespace Modules\\TempRoot;\n\nuse Modules\\TempDep\\TempDep;\n\nfinal class TempRoot\n{\n    public function dependency(): string\n    {\n        return TempDep::class;\n    }\n}",
            );

            $result = ModuleInstaller::fromAppPaths()->addWithDependencies($root);

            $this->assertSame(
                [$dependency, $root],
                $result['installed'],
                'The dependency must be copied before the module that imports it.',
            );
            $this->assertGreaterThanOrEqual(2, $result['copied']);
            $this->assertDirectoryExists(APP_BASE_PATH . '/src/Modules/' . $dependency);
            $this->assertDirectoryExists(APP_BASE_PATH . '/src/Modules/' . $root);
        } finally {
            self::cleanup($root, $dependency);
        }
    }

    #[Test]
    public function refusesACycleBeforeCopyingAnything(): void
    {
        $cycleA = 'TempCycleA';
        $cycleB = 'TempCycleB';

        self::cleanup($cycleA, $cycleB);

        try {
            self::writeModule(
                $cycleA,
                "namespace Modules\\TempCycleA;\n\nuse Modules\\TempCycleB\\TempCycleB;\n\nfinal class TempCycleA\n{\n    public function dependency(): string\n    {\n        return TempCycleB::class;\n    }\n}",
            );
            self::writeModule(
                $cycleB,
                "namespace Modules\\TempCycleB;\n\nuse Modules\\TempCycleA\\TempCycleA;\n\nfinal class TempCycleB\n{\n    public function dependency(): string\n    {\n        return TempCycleA::class;\n    }\n}",
            );

            try {
                ModuleInstaller::fromAppPaths()->addWithDependencies($cycleA);
                $this->fail('A dependency cycle should refuse the install.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('dependency cycle', $e->getMessage());
                $this->assertStringContainsString($cycleA, $e->getMessage());
                $this->assertStringContainsString($cycleB, $e->getMessage());
            }

            $this->assertDirectoryDoesNotExist(APP_BASE_PATH . '/src/Modules/' . $cycleA);
            $this->assertDirectoryDoesNotExist(APP_BASE_PATH . '/src/Modules/' . $cycleB);
        } finally {
            self::cleanup($cycleA, $cycleB);
        }
    }

    #[Test]
    public function refusesADependencyThatIsNotInTheCatalogue(): void
    {
        $needsNope = 'TempNeedsNope';

        self::cleanup($needsNope);

        try {
            self::writeModule(
                $needsNope,
                "namespace Modules\\TempNeedsNope;\n\nuse Modules\\Nope\\Nope;\n\nfinal class TempNeedsNope\n{\n    public function dependency(): string\n    {\n        return Nope::class;\n    }\n}",
            );

            try {
                ModuleInstaller::fromAppPaths()->addWithDependencies($needsNope);
                $this->fail('A non-catalogued dependency should refuse the install.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('missing dependencies: Nope', $e->getMessage());
            }

            $this->assertDirectoryDoesNotExist(APP_BASE_PATH . '/src/Modules/' . $needsNope);
        } finally {
            self::cleanup($needsNope);
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
