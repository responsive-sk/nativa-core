<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use Modules\Tooling\ModuleCatalog;
use Modules\Tooling\ModuleInstaller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ModuleCatalogueShapeTest — `modules/` has two shapes, and the catalogue knows
 * which is which.
 *
 * The four presentation modules (`Erik`, `Griffe`, `Jednotka`, `Mono`) are
 * themes, not features, and they live under `modules/Themes/<Name>/` so the
 * catalogue root reads as a list of things that DO something. The installed
 * tree has the SAME shape: `module:add Erik` copies to
 * `src/Modules/Themes/Erik/` and the class owns `Modules\Themes\Erik`, so the
 * two trees cannot drift into different layouts. `lekalo/modules/erik/` and
 * the theme NAME (`erik`, what the settings select stores) do not move — those
 * are identities, not paths. That is exactly why this needs a test: the only
 * place the move is visible is whatever enumerates either tree, and a missed
 * enumerator fails quietly — the theme disappears from `module:list`, or
 * `Themes` starts being treated as an installable module.
 *
 * `Themes` passes `isValidName()` (PascalCase), so it cannot be excluded by
 * syntax. It is excluded by shape: a group is a directory whose children are
 * modules, never a module itself.
 *
 * @internal
 *
 * @coversNothing
 */
final class ModuleCatalogueShapeTest extends TestCase
{
    /** Directory under `modules/` that groups modules instead of being one. */
    private const string GROUP = 'Themes';

    /** @var list<string> */
    private const array THEMES = ['Erik', 'Griffe', 'Jednotka', 'Mono'];

    /**
     * Catalogue entries that are TEST FIXTURES rather than modules: `Scratch`
     * is installed, exercised and removed by `ModuleLifecycleTest`, and
     * `install-catalogue.sh` skips it for the same reason. Named, not excused
     * by a pattern — a silent addition here would be this test weakening.
     *
     * @var list<string>
     */
    private const array CATALOGUE_FIXTURES = ['Scratch'];

    /**
     * Installed slices with no catalogue entry of their own: `KernelTheme`
     * ships with the kernel tree the way `Core` and `Database` do.
     *
     * @var list<string>
     */
    private const array KERNEL_ONLY = ['KernelTheme'];

    private static function base(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function catalogue(): ModuleCatalog
    {
        return ModuleCatalog::fromAppPaths();
    }

    #[Test]
    public function theThemesGroupIsNotAModule(): void
    {
        $catalogue = self::catalogue();

        // The reason this needs saying at all: the name is syntactically valid.
        $this->assertTrue(ModuleCatalog::isValidName(self::GROUP));

        $this->assertNotContains(
            self::GROUP,
            $catalogue->catalogued(),
            '`modules/Themes/` groups modules; it must not be listed as one.',
        );

        $this->assertFalse(
            $catalogue->isCatalogued(self::GROUP),
            'A group has no manifest, so `isCatalogued()` must say no.',
        );

        $this->assertNull(
            $catalogue->cataloguePath(self::GROUP),
            'A group name must not resolve to a catalogue path.',
        );
    }

    #[Test]
    public function everyThemeIsCataloguedUnderTheGroup(): void
    {
        $catalogue = self::catalogue();

        foreach (self::THEMES as $theme) {
            $this->assertContains(
                $theme,
                $catalogue->catalogued(),
                sprintf('Theme `%s` vanished from the catalogue when it moved into %s/.', $theme, self::GROUP),
            );

            $path = $catalogue->cataloguePath($theme);

            $this->assertNotNull($path, sprintf('Theme `%s` has no catalogue path.', $theme));
            $this->assertSame(
                self::base() . '/modules/' . self::GROUP . '/' . $theme,
                $path,
                sprintf('Theme `%s` must live under modules/%s/.', $theme, self::GROUP),
            );

            $this->assertDirectoryDoesNotExist(
                self::base() . '/modules/' . $theme,
                sprintf('`modules/%s/` is the OLD location; the catalogue must not carry both.', $theme),
            );

            // Same shape on the installed side: `module:add` copies to the
            // grouped path even before the directory exists, which is what
            // keeps the two trees identical instead of drifting apart.
            $this->assertSame(
                self::base() . '/src/Modules/' . self::GROUP . '/' . $theme,
                $catalogue->installedPath($theme),
                sprintf('Theme `%s` must install under src/Modules/%s/.', $theme, self::GROUP),
            );

            $this->assertSame(
                'Modules\\' . self::GROUP . '\\' . $theme,
                $catalogue->namespaceOf($theme),
                sprintf('A grouped theme owns `Modules\\%s\\%s`.', self::GROUP, $theme),
            );
        }

        $this->assertSame('Modules\\Blog', $catalogue->namespaceOf('Blog'), 'A flat module keeps its flat namespace.');
        $this->assertSame(self::base() . '/src/Modules/Blog', $catalogue->installedPath('Blog'));
    }

    /**
     * A slice that kept the old namespace stops autoloading the moment it moves:
     * PSR-4 maps `Modules\Themes\Erik` to `src/Modules/Themes/Erik`, so the
     * class's own `namespace` line must agree with where the file sits.
     */
    #[Test]
    public function aGroupedSliceDeclaresTheNamespaceTheAutoloaderResolves(): void
    {
        $catalogue = self::catalogue();
        $checked = 0;

        foreach (self::THEMES as $theme) {
            $file = $catalogue->installedPath($theme) . '/Theme/' . $theme . 'Theme.php';

            if (!is_file($file)) {
                continue; // not installed in this checkout
            }

            $checked++;

            $this->assertStringContainsString(
                'namespace ' . $catalogue->namespaceOf($theme) . '\\Theme;',
                (string) file_get_contents($file),
                sprintf('`%s` must declare the namespace its path resolves to.', $theme),
            );
        }

        if (0 === $checked) {
            $this->markTestSkipped('No theme slice installed — a kernel-only checkout has no namespace to check.');
        }
    }

    #[Test]
    public function theGroupNameCannotBeInstalled(): void
    {
        $catalogue = self::catalogue();

        try {
            $catalogue->manifest(self::GROUP);
            $this->fail('`manifest()` accepted a group name — it would install the whole group as one module.');
        } catch (\RuntimeException $refusal) {
            $this->assertStringContainsString(self::GROUP, $refusal->getMessage());
        }

        $addRefusal = '';

        try {
            ModuleInstaller::fromAppPaths()->add(self::GROUP);
        } catch (\RuntimeException $refusal) {
            $addRefusal = $refusal->getMessage();
        }

        $this->assertNotSame(
            '',
            $addRefusal,
            '`module:add Themes` must be refused — a group is not an installable module, and nothing may be copied.',
        );

        // The group directory exists in src/Modules because its members live
        // there — that must not read as "the group is an installed module".
        $this->assertFalse($catalogue->isInstalled(self::GROUP));

        $manifestRefusal = '';

        try {
            $catalogue->installedManifest(self::GROUP);
        } catch (\RuntimeException $refusal) {
            $manifestRefusal = $refusal->getMessage();
        }

        $this->assertNotSame(
            '',
            $manifestRefusal,
            '`installedManifest()` must refuse a group name instead of scanning it as a module.',
        );

        $this->assertDirectoryDoesNotExist(
            self::base() . '/src/Modules/' . self::GROUP . '/' . self::GROUP,
            'A refused install must not leave a slice behind.',
        );
    }

    /**
     * The catalogue and the installed tree describe the same SET of modules.
     * Two fixtures sit outside that set by design: `Scratch` is only ever
     * installed by its test, and `KernelTheme` is part of the tree, not an
     * entry in it.
     */
    #[Test]
    public function theCatalogueAndTheInstalledTreeDescribeTheSameModules(): void
    {
        $catalogue = self::catalogue();
        $installed = $catalogue->installed();

        // A kernel-only checkout carries the kernel tree plus `KernelTheme` and
        // no optional slices: `installed()` is never empty there, so the SET
        // that matters is the one with the kernel-only names removed. Skipping
        // on `[] === $installed` would fail this test on a clean kernel job —
        // exactly the tree it must stay green in.
        if ([] === array_diff($installed, self::KERNEL_ONLY)) {
            $this->markTestSkipped('Kernel-only checkout: no optional slices installed, nothing to compare.');
        }

        $catalogued = array_values(array_diff($catalogue->catalogued(), self::CATALOGUE_FIXTURES));
        $slices = array_values(array_diff($installed, self::KERNEL_ONLY));

        sort($catalogued);
        sort($slices);

        $this->assertSame(
            $slices,
            $catalogued,
            'A module is catalogued but not installed, or installed with no catalogue entry — '
            . 'moving a module into a group is a change the enumerator must follow.',
        );
    }

    /**
     * The install closure must be a DAG, not merely a set of manifests.
     *
     * `install-catalogue.sh` retries `module:add`, and a refusal that names the
     * missing closure is the graph talking. That loop can only terminate when
     * the graph has no cycle — measured on CI when Cardboard imported Media
     * while Media imported Cardboard, the second round installed nothing and
     * left twelve modules pending. A closure scan alone does not report the
     * cycle; it just refuses forever. This test runs the same rounds in memory,
     * so the failure is a test name and a pending list instead of a red job.
     */
    #[Test]
    public function theCatalogueCanBeInstalledWithoutADependencyCycle(): void
    {
        $catalogue = self::catalogue();
        $pending = array_values(array_diff(
            $catalogue->catalogued(),
            self::CATALOGUE_FIXTURES,
        ));
        $installed = array_fill_keys(ModuleCatalog::KERNEL_MODULES, true);

        while ([] !== $pending) {
            $next = [];
            $progress = false;

            foreach ($pending as $name) {
                $missing = array_values(array_filter(
                    $catalogue->manifest($name)->closure(),
                    static fn (string $dependency): bool => !in_array($dependency, ModuleCatalog::KERNEL_MODULES, true)
                        && !isset($installed[$dependency]),
                ));

                if ([] === $missing) {
                    $installed[$name] = true;
                    $progress = true;

                    continue;
                }

                $next[] = $name;
            }

            if (!$progress) {
                self::fail(
                    'The catalogue closure graph has a cycle; these modules can never be installed:'
                    . "\n  " . implode("\n  ", $next)
                    . "\nA module must not `use` another module that already depends on it."
                    . ' Fully-qualified inline references are invisible to the closure scanner;'
                    . ' a `use` line is the edge.',
                );
            }

            $pending = $next;
        }

        self::assertSame([], $pending);
    }

    /**
     * A group with no member is a directory nobody can install and nobody reads:
     * it would silently drop its modules from `catalogued()` if one ever left,
     * so the set is checked rather than assumed.
     */
    #[Test]
    public function everyCatalogueGroupHasAtLeastOneMember(): void
    {
        $catalogue = self::catalogue();

        foreach (ModuleCatalog::catalogueGroups() as $group) {
            $dir = self::base() . '/modules/' . $group;

            $this->assertDirectoryExists($dir, sprintf('Group `%s` is declared but does not exist.', $group));

            $members = array_values(array_filter(
                scandir($dir) ?: [],
                static fn (string $entry): bool => '.' !== $entry && '..' !== $entry && is_dir($dir . '/' . $entry),
            ));

            $this->assertNotSame(
                [],
                $members,
                sprintf('Group `%s` is empty — it would hide its modules instead of grouping them.', $group),
            );

            foreach ($members as $member) {
                $this->assertContains(
                    $member,
                    $catalogue->catalogued(),
                    sprintf('`%s/%s` is inside a group but missing from `catalogued()`.', $group, $member),
                );
            }
        }
    }

    /**
     * The admin module tree (`/mark/settings/modules`) and its metric compare
     * the installed slice against the catalogue on their own. A second, hand-built
     * `'/modules/' . $name` is how that page would report `identical` for every
     * theme after the move — `is_dir()` fails, `differences()` returns `[]`, and
     * the page says "in sync". One source of the path, or the page lies.
     */
    #[Test]
    public function theAdminModuleTreeReadsTheCataloguePathInsteadOfComposingItsOwn(): void
    {
        $file = self::base() . '/modules/Cardboard/Application/Services/ModuleTreeState.php';

        $this->assertFileExists($file);
        $source = (string) file_get_contents($file);

        $this->assertStringNotContainsString(
            "'/modules/'",
            $source,
            'ModuleTreeState must ask ModuleCatalog::cataloguePath(), not rebuild the path.',
        );
        $this->assertStringContainsString(
            'cataloguePath(',
            $source,
            'ModuleTreeState must resolve the catalogue path through ModuleCatalog.',
        );
    }
}
