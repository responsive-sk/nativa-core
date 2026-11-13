<?php

declare(strict_types=1);

namespace Modules\Cardboard\Application\Services;

use Modules\Tooling\ModuleCatalog;

/**
 * ModuleTreeState — what the catalogue says, what is installed, and how far
 * apart they are.
 *
 * `src/Modules/<Name>` is gitignored operator state: a clone carries Core +
 * Database, and `module:add` copies the catalogue in. So the running tree and
 * the committed catalogue are allowed to disagree, and they do without saying
 * so. The catalogue itself has two shapes (`modules/<Name>/` and
 * `modules/<Group>/<Name>/`); both are resolved by `ModuleCatalog`, so this
 * class holds no path rule of its own. Measured 2026-10-17: after `AdminRoutePolicy` and `Permissions` moved to
 * `Modules\Core\Domain` and Cardboard's two local copies of the permission table
 * were deleted, every gate was green and the installed slice still carried the
 * old code. `git status` cannot see it and `module:show` asks `isInstalled()`,
 * which is whether a slice exists, not whether it is the right one.
 *
 * ## Why this is one class and not a private method on the card
 *
 * The `modules` stat card and the `/mark/settings/modules` page answer the same
 * question for the same tree, and they are the code that would drift from each
 * other — exactly the failure this class exists to end. One reader, two
 * surfaces. It is deliberately the same shape the `ModelCatalogueContractTest`
 * compares with, so the three can be read side by side.
 *
 * Read-only by construction: it opens files and hashes them, and decides
 * nothing. Reinstalling is `ModuleInstaller::add()`, which is a separate,
 * deliberate call.
 *
 * @internal
 *
 * @coversNothing
 */
final readonly class ModuleTreeState
{
    /** Compared on both sides, so tests running from the catalogue are not drift. */
    private const array IGNORED = ['/tests/', '/assets/', '/dist/', '/node_modules/'];

    /**
     * @param ?string $installedRoot root of the installed tree; `null` means
     *                              `src/Modules` from AppPaths. Tests point it at
     *                              a temp tree so a drift case needs no real slice.
     */
    public function __construct(
        private ?ModuleCatalog $catalogue = null,
        private ?string $installedRoot = null,
    ) {
    }

    /**
     * Every catalogued module, in the order the catalogue lists them.
     *
     * @return list<array{
     *     name: string,
     *     installed: bool,
     *     state: 'identical'|'drifted'|'not-installed',
     *     drifted: list<string>
     * }>
     */
    public function all(): array
    {
        $catalogue = $this->catalogue ?? ModuleCatalog::fromAppPaths();
        $rows = [];

        foreach ($catalogue->catalogued() as $name) {
            // One hash pass per module, not two: `differences()` reads and
            // digests every file of the slice, and the first version called it
            // from both the state and the list of drifted files. Measured
            // 2026-11-07: `all()` took 42 ms for 26 modules, half of it the
            // second pass, and the page built TWO of these (the intro and the
            // table), so a single view hashed the tree four times.
            $installed = $catalogue->isInstalled($name);
            $drifted = $installed ? $this->differences($catalogue, $name) : [];

            $rows[] = [
                'name' => $name,
                'installed' => $installed,
                'state' => !$installed ? 'not-installed' : ([] === $drifted ? 'identical' : 'drifted'),
                'drifted' => $drifted,
            ];
        }

        return $rows;
    }

    /**
     * The counts the stat card's subtitle is made of.
     *
     * @return array{total: int, identical: int, drifted: int, missing: int}
     */
    public function counts(): array
    {
        $counts = ['total' => 0, 'identical' => 0, 'drifted' => 0, 'missing' => 0];

        foreach ($this->all() as $row) {
            ++$counts['total'];

            match ($row['state']) {
                'identical' => ++$counts['identical'],
                'drifted' => ++$counts['drifted'],
                default => ++$counts['missing'],
            };
        }

        return $counts;
    }

    /**
     * Which files of a module's slice differ from the catalogue.
     *
     * `tests/` is excluded on both sides: tests live in the catalogue and are run
     * from there, so a slice without them is correct rather than drifted. `assets/`
     * and `dist/` are build output — `make assets` regenerates them and the slice
     * carries whatever was last built, so comparing them would report drift after
     * every build.
     *
     * @return list<string> paths relative to the module root
     */
    public function differences(ModuleCatalog $catalogue, string $name): array
    {
        // The catalogue path is the catalogue's answer, never this class's:
        // themes live under `modules/Themes/<Name>/`, and a hand-built
        // `modules/<Name>/` would fail `is_dir()` and read as "identical" —
        // the page would report peace for a slice that never matched.
        $catalogued = $catalogue->cataloguePath($name);

        // The installed path follows the same group as the catalogue; only a
        // test-supplied root is joined flat, because its fixtures are flat.
        $installed = null === $this->installedRoot
            ? $catalogue->installedPath($name)
            : $this->installedRoot . '/' . $name;

        if (null === $catalogued || !is_dir($catalogued) || !is_dir($installed)) {
            return [];
        }

        $different = [];

        foreach ($this->comparedFiles($catalogued) as $relative) {
            $to = $installed . '/' . $relative;

            if (!is_file($to)) {
                $different[] = $relative;

                continue;
            }

            if ($this->digest($catalogued . '/' . $relative) !== $this->digest($to)) {
                $different[] = $relative;
            }
        }

        return $different;
    }

    /**
     * @return list<string> paths relative to $dir, slash-separated
     */
    private function comparedFiles(string $dir): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            foreach (self::IGNORED as $skip) {
                if (str_contains($path, $skip)) {
                    continue 2;
                }
            }

            if ('php' !== $file->getExtension() && 'css' !== $file->getExtension()) {
                continue;
            }

            $found[] = substr($path, strlen($dir) + 1);
        }

        sort($found);

        return $found;
    }

    /**
     * Length and hash: two files differing only in trailing whitespace are still
     * different files, and this reports drift rather than peace. A `filemtime()`
     * comparison would call every reinstall drift, because a copy writes every
     * file it touches and preserves nothing.
     */
    private function digest(string $file): string
    {
        $contents = (string) file_get_contents($file);

        return strlen($contents) . ':' . hash('sha256', $contents);
    }
}