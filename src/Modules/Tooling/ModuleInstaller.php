<?php

declare(strict_types = 1);

namespace Modules\Tooling;

use Modules\Core\Application\Services\DiscoveryCache;
use Modules\Core\Infrastructure\Bootstrap\Bootstrapper;
use Modules\Core\Infrastructure\Paths\AppPaths;
use RuntimeException;

/**
 * ModuleInstaller — the add/remove half of bin/cms, as a service.
 *
 * bin/cms used to own copyTree/deleteTree and every refusal inline, which
 * meant the lifecycle contract (round trip leaves a byte-identical tree, a
 * second add refuses, removal while imported refuses) was only provable by
 * shelling out and eyeballing output. The CLI is now a thin printer over
 * this class; ModuleLifecycleTest drives it in-process instead.
 *
 * Every refusal throws RuntimeException with the exact message bin/cms
 * prints — one source of truth for the wording, two front-ends (CLI, tests).
 */
final readonly class ModuleInstaller
{
    public function __construct(
        private ModuleCatalog $catalog,
    ) {
    }

    public static function fromAppPaths(): self
    {
        return new self(ModuleCatalog::fromAppPaths());
    }

    /**
     * Install a catalogued module: refuse an incomplete closure BEFORE
     * copying anything (a half-installed module answers for dependencies
     * that never arrived), then copy the slice verbatim and flush.
     *
     *
     * @throws RuntimeException on refusal (unknown / invalid / installed / closure)
     *
     * @return array{copied: int, flushed: int}
     */
    public function add(string $name): array
    {
        $manifest = $this->catalog->manifest($name);

        if (!ModuleCatalog::isValidName($name)) {
            throw new RuntimeException(sprintf("Invalid module name '%s'.", $name));
        }

        if ($this->catalog->isInstalled($name)) {
            throw new RuntimeException(sprintf("Module '%s' is already installed — refusing to overwrite the installed slice.", $name));
        }

        $missing = $this->catalog->missingDependencies($manifest);

        if ([] !== $missing) {
            throw new RuntimeException(sprintf(
                "Cannot install '%s': missing dependencies: %s. Install them first (or add them to the catalog). Nothing was copied.",
                $name,
                implode(', ', $missing),
            ));
        }

        // The target follows the catalogue's shape: a grouped module installs
        // into `src/Modules/<Group>/<Name>/` with the namespace to match, so
        // `module:add` and `module:remove` never disagree with `catalogued()`.
        $target = $this->catalog->installedPath($name);
        $copied = self::copyTree($manifest->sourceDir, $target);

        // The new slice may carry #[AsMigrator] classes, so the first-request
        // marker must not outlive the module set it was written for. The
        // discovery cache records the same module set, so it goes with it.
        Bootstrapper::invalidateMigrations();
        DiscoveryCache::clearCache();

        return ['copied' => $copied, 'flushed' => $this->flushPageCache()];
    }

    /**
     * Remove an installed slice. Refuses while another installed module
     * imports it (unless forced), and never touches module data: the code
     * is not the data, so leftovers are reported, not deleted.
     *
     *
     * @throws RuntimeException on refusal (invalid / kernel / missing / imported)
     *
     * @return array{flushed: int, leftovers: list<string>, holders: list<string>}
     */
    public function remove(string $name, bool $force = false): array
    {
        if (!ModuleCatalog::isValidName($name)) {
            throw new RuntimeException(sprintf("Invalid module name '%s'.", $name));
        }

        if (in_array($name, ModuleCatalog::KERNEL_MODULES, true)) {
            throw new RuntimeException(sprintf("Module '%s' is the kernel itself — it cannot be removed.", $name));
        }

        if (!$this->catalog->isInstalled($name)) {
            throw new RuntimeException(sprintf("Module '%s' is not installed.", $name));
        }

        $holders = $this->catalog->importedBy($name);

        if ([] !== $holders && !$force) {
            throw new RuntimeException(sprintf(
                "Cannot remove '%s': still imported by:%s%s\nRemove those first, or pass --force (the only way past a refusal). The slice is untouched.",
                $name,
                PHP_EOL . '  - ',
                implode(PHP_EOL . '  - ', $holders),
            ));
        }

        self::deleteTree($this->catalog->installedPath($name));

        // The remaining module set may differ from the one the marker was
        // written for — let the next request re-derive it. Same for the
        // discovery cache, which fingerprints that module set.
        Bootstrapper::invalidateMigrations();
        DiscoveryCache::clearCache();

        $flushed = $this->flushPageCache();

        $leftovers = [];
        $db = AppPaths::instance()->storage('data' . DIRECTORY_SEPARATOR . strtolower($name) . '.db');

        foreach ([$db, $db . '-wal', $db . '-shm', $db . '-journal'] as $file) {
            if (is_file($file)) {
                $leftovers[] = $file . ' (module database — kept)';
            }
        }

        return ['flushed' => $flushed, 'leftovers' => $leftovers, 'holders' => $force ? $holders : []];
    }

    public function flushPageCache(): int
    {
        // The page cache owns `PageCacheService::DEFAULT_DIR`, but Tooling ships
        // in the kernel-only export while that module does not. The path is
        // base-relative ('storage/cache/pages'), not relative to the storage
        // root: composing it with `AppPaths::storage()` prepends a second
        // `storage/`, which is the defect PageCacheDirectoryTest pins. Keep the
        // same glob shape the service's `flush()` uses.
        $dir = APP_BASE_PATH . '/storage/cache/pages';
        $files = glob($dir . '/*.json');

        if (!is_array($files)) {
            return 0;
        }

        $count = 0;

        foreach ($files as $file) {
            if (is_file($file) && @unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Copy a directory tree (every file, not just PHP — assets travel too).
     *
     * @throws RuntimeException
     */
    public static function copyTree(string $from, string $to): int
    {
        // The destination root first, and it is not a detail: the iterator
        // below yields the source's CONTENTS in readdir order, so a slice whose
        // root holds a file before any directory hands us that file while `$to`
        // does not exist yet, and `copy()` fails. Measured on Boot 19 in a fresh
        // checkout: `module:add` died with
        // `Cannot copy .../TempHolder/module.json` — the lifecycle fixture, whose
        // slice root holds `module.json` beside `src/`. It is intermittent by
        // construction (the outcome depends on the filesystem's ordering, so the
        // same test passed on the workstation and failed in CI), and no shipped
        // catalogue entry triggers it today: all sixteen hold directories only.
        // The contract is what is fixed here — `copyTree` must not depend on
        // readdir order for a slice that carries a root file.
        if (!is_dir($to) && !@mkdir($to, 0o775, true) && !is_dir($to)) {
            throw new RuntimeException(sprintf('Cannot create directory: %s', $to));
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $target = $to . DIRECTORY_SEPARATOR . $iterator->getSubPathname();

            if ($entry->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0o775, true) && !is_dir($target)) {
                    throw new RuntimeException(sprintf('Cannot create directory: %s', $target));
                }

                continue;
            }

            if (!@copy($entry->getPathname(), $target)) {
                throw new RuntimeException(sprintf('Cannot copy %s to %s.', $entry->getPathname(), $target));
            }

            $count++;
        }

        return $count;
    }

    /**
     * Delete a directory tree.
     *
     * @throws RuntimeException
     */
    public static function deleteTree(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                if (!@rmdir($entry->getPathname())) {
                    throw new RuntimeException(sprintf('Cannot remove directory: %s', $entry->getPathname()));
                }
            } elseif (!@unlink($entry->getPathname())) {
                throw new RuntimeException(sprintf('Cannot remove file: %s', $entry->getPathname()));
            }
        }

        if (!@rmdir($dir)) {
            throw new RuntimeException(sprintf('Cannot remove directory: %s', $dir));
        }
    }
}
