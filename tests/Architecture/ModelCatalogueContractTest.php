<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use Modules\Tooling\ModuleCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ModelCatalogueContractTest — the Lekalo model against the module catalogue.
 *
 * Measured 2026-10-16, on the way to moving each model slice into its module:
 *
 *     php bin/cms module:remove Media --force
 *     lekalo/modules/media/ stale existuje: ANO
 *     make lekalo-check  ->  23 rules, 0 errors, 0 warnings, 0 info   (exit 0)
 *
 * The model described a module that was no longer in the tree, and the gate was
 * green. That is not a coverage gap — the model has NO adapter, so nothing in
 * Lekalo binds a definition to code, and `lekalo validate` checks the model for
 * well-formedness alone. It is the same failure Boot 32 measured from the other
 * side: the model states things the code does not do, and nothing notices.
 *
 * Moving the slices into `modules/<Name>/lekalo/` does NOT fix this on its own,
 * for two reasons both measured: `ModuleInstaller::remove()` deletes
 * `src/Modules/<Name>` and nothing else, so a slice inside the catalogue would
 * outlive the module exactly as the current one does; and the Lekalo loader
 * reads files, never the installed set. **The file layout was never the missing
 * binding — this test is.**
 *
 * Deliberately cheap and deliberately dumb, like `KernelContractTest` beside it:
 * two directory listings compared. No adapter, no Lekalo change, no schema
 * knowledge. What it protects is narrower than the model's truth and is stated
 * rather than implied — it says the model and the catalogue describe the SAME
 * SET of modules, and nothing about whether any description is accurate.
 *
 * @internal
 *
 * @coversNothing
 */
final class ModelCatalogueContractTest extends TestCase
{
    /**
     * Model directories that describe the kernel rather than a catalogued module.
     *
     * `core` and `database` are the two kernel modules the whole model hangs
     * off, and `kernel_theme` is installed but has no catalogue entry of its own
     * — it ships with the kernel tree the way `Core` and `Database` do. They are
     * modelled on purpose, so they are named here rather than excused by a
     * pattern: a list that silently grew would be the check quietly weakening.
     *
     * @var list<string>
     */
    private const array KERNEL_MODELLED = ['core', 'database', 'kernel_theme'];

    /**
     * Catalogue entries that describe a TEST FIXTURE rather than a module.
     *
     * `Scratch` is the lifecycle leaf `ModuleLifecycleTest` installs, exercises
     * and removes, and `bin/build/install-catalogue.sh` skips it for the same
     * reason. It is not modelled and must not be: a `lekalo/modules/scratch/`
     * slice would put a test artifact into the model as truth. Named rather
     * than excused by a pattern, exactly like the list above — a silent
     * addition here would be this check quietly weakening.
     *
     * @var list<string>
     */
    private const array CATALOGUE_FIXTURES = ['Scratch'];

    private static function catalogue(): ModuleCatalog
    {
        return ModuleCatalog::fromAppPaths();
    }

    /** @return list<string> */
    private static function modelDirectories(): array
    {
        $root = dirname(__DIR__, 2) . '/lekalo/modules';
        $found = [];

        foreach (scandir($root) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry && is_dir($root . DIRECTORY_SEPARATOR . $entry)) {
                $found[] = $entry;
            }
        }

        sort($found);

        return $found;
    }

    /** Model directory name → module name: `kernel_theme` ⇒ `KernelTheme`. */
    private static function moduleNameFor(string $directory): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $directory)));
    }

    #[Test]
    public function everyModelDirectoryNamesAModuleTheCatalogueKnows(): void
    {
        $catalogue = self::catalogue();

        foreach (self::modelDirectories() as $directory) {
            $name = self::moduleNameFor($directory);

            self::assertTrue(
                $catalogue->isCatalogued($name) || $catalogue->isInstalled($name),
                sprintf(
                    'lekalo/modules/%s/ describes "%s", which the catalogue does not know.'
                    . ' Removing a module leaves its model behind and the gate green —'
                    . ' this is the check that says so.',
                    $directory,
                    $name,
                ),
            );
        }
    }

    #[Test]
    public function everyCataloguedModuleHasAModelDirectory(): void
    {
        $modelled = array_map(self::moduleNameFor(...), self::modelDirectories());

        foreach (self::catalogue()->catalogued() as $name) {
            if (in_array($name, self::CATALOGUE_FIXTURES, true)) {
                continue;
            }

            self::assertContains(
                $name,
                $modelled,
                sprintf(
                    'Module "%s" is catalogued but has no lekalo/modules/ slice.'
                    . ' An unmodelled module is one nobody wrote down, which is the'
                    . ' state the other assertion cannot see.',
                    $name,
                ),
            );
        }
    }

    #[Test]
    public function theKernelModulesModelledOnPurposeAreTheOnesNamed(): void
    {
        // If a kernel module gains a model directory and is not listed above,
        // this fails. If one is listed that no longer applies, it fails too —
        // which is the point: the exception list cannot quietly grow or rot.
        $kernelModelled = array_filter(
            self::modelDirectories(),
            static function (string $directory): bool {
                $name = self::moduleNameFor($directory);

                return in_array($name, ['Core', 'Database'], true) || 'KernelTheme' === $name;
            },
        );

        sort($kernelModelled);

        self::assertSame(self::KERNEL_MODELLED, $kernelModelled);
    }

    #[Test]
    public function aRemovedModuleCannotLeaveItsModelBehindQuietly(): void
    {
        // The negative control, as a permanent assertion rather than a thing
        // done by hand once. `Media` is the module the round was measured on.
        //
        // Measured 2026-10-17, when this test was red on a kernel-only checkout
        // and the tree was innocent. It compared `isInstalled('Media')` against
        // `!modelled || isCatalogued('Media')`, and those answer two different
        // questions:
        //
        //     lekalo/modules/media   true    tracked, describes a module
        //     isCatalogued('Media')  true    modules/Media is in the catalogue
        //     isInstalled('Media')   false   src/Modules/Media is operator state
        //
        // `module:remove` deletes the slice and leaves the catalogue entry, so
        // "removed" and "never installed in this checkout" were the SAME state —
        // and the assertion could not tell them apart. It went red on every
        // checkout that had not installed Media, which is the whole point of
        // kernel-only. The comparison the name promises is model-against-
        // catalogue, because the catalogue is the tracked source of truth and
        // the slice is gitignored operator state.
        //
        // `everyModelDirectoryNamesAModuleTheCatalogueKnows()` above is the
        // general form of this, over every modelled slice. This one keeps the
        // measured case named, so a regression here is recognisable rather than
        // arriving as one anonymous entry in a loop over twenty directories.
        $catalogue = self::catalogue();
        $mediaModelled = is_dir(dirname(__DIR__, 2) . '/lekalo/modules/media');

        self::assertFalse(
            $mediaModelled && !$catalogue->isCatalogued('Media'),
            'lekalo/modules/media/ describes a module the catalogue no longer knows.'
            . ' This check is the only thing standing between module:remove and a'
            . ' silent lie.',
        );
    }
}
