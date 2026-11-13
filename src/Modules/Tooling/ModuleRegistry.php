<?php

declare(strict_types = 1);

namespace Modules\Tooling;

use JsonException;
use Modules\Core\Infrastructure\Env;
use Modules\Core\Infrastructure\Paths\AppPaths;
use RuntimeException;

/**
 * ModuleRegistry — install a module that is not in the local catalogue.
 *
 * The catalogue is still the local source of truth. This service fetches a
 * registry index, resolves the dependency closure from that index (falling
 * back to a local manifest when a dependency is already catalogued), downloads
 * every archive it needs, verifies each SHA-256, and only then copies the
 * modules into `modules/`. The copy is the same shape `module:add` expects, so
 * the existing ModuleInstaller still owns installation and flush semantics.
 *
 * The registry is opt-in: `fromEnv()` returns null while MODULE_REGISTRY_URL is
 * empty. An archive is a gzip-compressed tar with the module's files at its
 * root; no archive may contain a symlink or a path outside that temporary
 * extraction directory.
 */
final readonly class ModuleRegistry
{
    public function __construct(
        private ModuleCatalog $catalog,
        private ModuleInstaller $installer,
        private RegistryTransport $transport,
        private string $indexUrl,
        private string $catalogueRoot,
        private string $lockPath,
        private int $maxIndexBytes = 1_048_576,
        private int $maxArchiveBytes = 20_971_520,
    ) {
    }

    public static function fromEnv(): ?self
    {
        $url = trim((string) Env::get('MODULE_REGISTRY_URL', ''));

        if ('' === $url) {
            return null;
        }

        return new self(
            ModuleCatalog::fromAppPaths(),
            ModuleInstaller::fromAppPaths(),
            new CurlRegistryTransport(),
            $url,
            APP_BASE_PATH . '/modules',
            AppPaths::instance()->data('module-registry.json'),
        );
    }

    /**
     * Every entry in the registry, keyed by module name and sorted.
     *
     * @return array<string, array{requires: list<string>, archive: string, sha256: string, version: ?string}>
     *
     * @throws RuntimeException when the index is malformed or unreachable
     */
    public function entries(): array
    {
        $body = $this->transport->get($this->indexUrl, $this->maxIndexBytes);

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Registry index is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)
            || 1 !== ($decoded['schema_version'] ?? null)
            || !isset($decoded['modules'])
            || !is_array($decoded['modules'])
        ) {
            throw new RuntimeException('Registry index must be an object with schema_version 1 and a modules object.');
        }

        $snapshotVersion = $decoded['version'] ?? null;

        if (null !== $snapshotVersion && (!is_string($snapshotVersion) || '' === trim($snapshotVersion))) {
            throw new RuntimeException('Registry index version must be a non-empty string.');
        }

        $entries = [];

        foreach ($decoded['modules'] as $name => $raw) {
            if (!is_string($name) || !ModuleCatalog::isValidName($name)) {
                throw new RuntimeException(sprintf('Registry contains an invalid module name: %s', (string) $name));
            }

            if (!is_array($raw)) {
                throw new RuntimeException(sprintf('Registry entry for %s is not an object.', $name));
            }

            $requires = $raw['requires'] ?? [];

            if (!is_array($requires) || !array_is_list($requires)) {
                throw new RuntimeException(sprintf('Registry entry for %s has an invalid requires list.', $name));
            }

            $cleanRequires = [];

            foreach ($requires as $dependency) {
                if (!is_string($dependency)
                    || !ModuleCatalog::isValidName($dependency)
                    || $dependency === $name
                    || in_array($dependency, ModuleCatalog::KERNEL_MODULES, true)
                ) {
                    throw new RuntimeException(sprintf('Registry entry for %s has an invalid dependency.', $name));
                }

                $cleanRequires[$dependency] = true;
            }

            $cleanRequires = array_keys($cleanRequires);
            sort($cleanRequires);

            $archive = $raw['archive'] ?? null;
            $sha256 = $raw['sha256'] ?? null;
            $version = $raw['version'] ?? $snapshotVersion;

            if (!is_string($archive) || '' === trim($archive)) {
                throw new RuntimeException(sprintf('Registry entry for %s has no archive.', $name));
            }

            if (!is_string($sha256) || 1 !== preg_match('/^[0-9a-f]{64}$/', $sha256)) {
                throw new RuntimeException(sprintf('Registry entry for %s has no lowercase sha256.', $name));
            }

            if (null !== $version && (!is_string($version) || '' === trim($version))) {
                throw new RuntimeException(sprintf('Registry entry for %s has an invalid version.', $name));
            }

            $archive = $this->resolveArchive($archive);

            if (!in_array(strtolower((string) parse_url($archive, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw new RuntimeException(sprintf('Registry archive for %s must use HTTP(S).', $name));
            }

            $entries[$name] = [
                'requires' => $cleanRequires,
                'archive'  => $archive,
                'sha256'   => $sha256,
                'version'  => $version,
            ];
        }

        ksort($entries);

        return $entries;
    }

    /**
     * Registry entries that are neither catalogued nor installed locally.
     *
     * @return array<string, array{requires: list<string>, archive: string, sha256: string, version: ?string}>
     */
    public function available(): array
    {
        $available = [];

        foreach ($this->entries() as $name => $entry) {
            if ($this->catalog->isCatalogued($name) || $this->catalog->isInstalled($name)) {
                continue;
            }

            $available[$name] = $entry;
        }

        return $available;
    }

    /**
     * The registry panel's single read: installable, updatable and rollbackable
     * entries, resolved from one index fetch and the lock file. A module with a
     * different SHA-256 is an update; a version label alone is not code.
     *
     * @return array{
     *     available: array<string, array{requires: list<string>, archive: string, sha256: string, version: ?string}>,
     *     updates: array<string, array{current: ?string, entry: array{requires: list<string>, archive: string, sha256: string, version: ?string}}>,
     *     rollbacks: array<string, array{current: ?string, version: ?string}>
     * }
     */
    public function state(): array
    {
        $entries = $this->entries();
        $lock = $this->readLock();
        $available = [];
        $updates = [];
        $rollbacks = [];

        foreach ($entries as $name => $entry) {
            if (!$this->catalog->isCatalogued($name) && !$this->catalog->isInstalled($name)) {
                $available[$name] = $entry;

                continue;
            }

            if (!isset($lock['modules'][$name]) || !$this->catalog->isCatalogued($name) || !$this->catalog->isInstalled($name)) {
                continue;
            }

            $locked = $lock['modules'][$name];

            if ($locked['sha256'] !== $entry['sha256']) {
                $updates[$name] = ['current' => $locked['version'], 'entry' => $entry];
            }
        }

        foreach ($lock['modules'] as $name => $locked) {
            if (null === $locked['rollback'] || !$this->catalog->isCatalogued($name) || !$this->catalog->isInstalled($name)) {
                continue;
            }

            $rollbacks[$name] = [
                'current' => $locked['version'],
                'version' => $locked['rollback']['version'],
            ];
        }

        ksort($available);
        ksort($updates);
        ksort($rollbacks);

        return ['available' => $available, 'updates' => $updates, 'rollbacks' => $rollbacks];
    }

    /**
     * Replace one registry-managed module with the current registry archive.
     *
     * The archive is staged and verified before the catalogue is touched. The
     * old catalogue is copied into the lock directory, then the installed slice
     * is removed and re-added from the new catalogue. Any failure restores the
     * old catalogue and reinstalls it, so the tree is not left half-updated.
     * Dependencies are not upgraded implicitly: every direct dependency must
     * already be installed.
     *
     * @return array{from: ?string, to: ?string, copied: int, flushed: int}
     *
     * @throws RuntimeException when the module is not lock-managed, already up
     *                          to date, missing a dependency, or a stage/install
     *                          step fails
     */
    public function update(string $name): array
    {
        if (!ModuleCatalog::isValidName($name)) {
            throw new RuntimeException(sprintf("Invalid module name '%s'.", $name));
        }

        $entries = $this->entries();
        $lock = $this->readLock();

        if (!isset($lock['modules'][$name])) {
            throw new RuntimeException(sprintf("Module '%s' was not installed from the registry.", $name));
        }

        if (!isset($entries[$name])) {
            throw new RuntimeException(sprintf("Module '%s' is no longer in the registry.", $name));
        }

        if (!$this->catalog->isInstalled($name)) {
            throw new RuntimeException(sprintf("Module '%s' is not installed.", $name));
        }

        $catalogue = $this->catalog->cataloguePath($name);

        if (null === $catalogue) {
            throw new RuntimeException(sprintf("Module '%s' is no longer catalogued.", $name));
        }

        $locked = $lock['modules'][$name];
        $entry = $entries[$name];

        if ($locked['sha256'] === $entry['sha256']) {
            throw new RuntimeException(sprintf("Module '%s' is already up to date.", $name));
        }

        foreach ($entry['requires'] as $dependency) {
            if (!in_array($dependency, ModuleCatalog::KERNEL_MODULES, true) && !$this->catalog->isInstalled($dependency)) {
                throw new RuntimeException(sprintf(
                    "Cannot update '%s': dependency '%s' is not installed. Install it first; the update does not upgrade dependencies implicitly.",
                    $name,
                    $dependency,
                ));
            }
        }

        [$directory, $archive] = $this->stage($name, $entry);

        try {
            $backup = $this->backupCatalogue($name, $catalogue);
        } catch (\Throwable $e) {
            self::remove($directory);
            self::remove($archive);

            throw $e;
        }

        $succeeded = false;

        try {
            $this->replaceCatalogue($name, $catalogue, $directory);
            $result = $this->installer->addWithDependencies($name);
            (new ModuleCatalogueIndex($this->catalog))->write();

            $lock['modules'][$name] = [
                'version'  => $entry['version'],
                'sha256'   => $entry['sha256'],
                'archive'  => $entry['archive'],
                'rollback' => [
                    'version'   => $locked['version'],
                    'sha256'    => $locked['sha256'],
                    'archive'   => $locked['archive'],
                    'catalogue' => $backup['relative'],
                ],
            ];
            $this->writeLock($lock);
            $succeeded = true;

            return [
                'from'   => $locked['version'],
                'to'     => $entry['version'],
                'copied' => $result['copied'],
                'flushed' => $result['flushed'],
            ];
        } catch (\Throwable $e) {
            try {
                $this->restoreCatalogue($name, $catalogue, $backup['path']);
            } catch (\Throwable $restore) {
                throw new RuntimeException(sprintf(
                    'Update failed and the previous version could not be restored: %s / %s',
                    $e->getMessage(),
                    $restore->getMessage(),
                ), 0, $e);
            }

            throw new RuntimeException('Update failed; previous version restored: ' . $e->getMessage(), 0, $e);
        } finally {
            self::remove($directory);
            self::remove($archive);

            if (!$succeeded) {
                self::remove($backup['path']);
            }
        }
    }

    /**
     * Update every registry-managed module whose archive changed.
     *
     * This is the same verified one-module transaction as update(), run in
     * name order. It stops at the first failure: modules already updated keep
     * their new version, while the failing module is restored by update()
     * itself. This is not an atomic multi-module update, and dependencies are
     * still never upgraded implicitly.
     *
     * @return array{
     *     updated: list<array{name: string, from: ?string, to: ?string, copied: int, flushed: int}>,
     *     failed: ?array{name: string, error: string}
     * }
     */
    public function updateAll(): array
    {
        $updates = $this->state()['updates'];
        $updated = [];

        foreach ($this->updateOrder($updates) as $name) {
            try {
                $result = $this->update($name);
            } catch (RuntimeException $e) {
                return [
                    'updated' => $updated,
                    'failed'  => ['name' => $name, 'error' => $e->getMessage()],
                ];
            }

            $updated[] = ['name' => $name, ...$result];
        }

        return ['updated' => $updated, 'failed' => null];
    }

    /**
     * Dependencies first: a module in this run must not be reinstalled against
     * an older copy of another module that is waiting in the same run.
     *
     * @param array<string, array{current: ?string, entry: array{requires: list<string>, archive: string, sha256: string, version: ?string}}> $updates
     *
     * @return list<string>
     */
    private function updateOrder(array $updates): array
    {
        /** @var list<string> $order */
        $order = [];
        /** @var array<string, true> $visiting */
        $visiting = [];
        /** @var array<string, true> $done */
        $done = [];

        foreach (array_keys($updates) as $name) {
            $this->orderUpdate($name, $updates, $order, $visiting, $done);
        }

        return $order;
    }

    /**
     * @param array<string, array{current: ?string, entry: array{requires: list<string>, archive: string, sha256: string, version: ?string}}> $updates
     * @param list<string>                                                                                                  $order
     * @param array<string, true>                                                                                           $visiting
     * @param array<string, true>                                                                                           $done
     */
    private function orderUpdate(
        string $name,
        array $updates,
        array &$order,
        array &$visiting,
        array &$done,
    ): void {
        if (isset($done[$name]) || !isset($updates[$name])) {
            return;
        }

        if (isset($visiting[$name])) {
            throw new RuntimeException(sprintf(
                "Cannot update all: dependency cycle involving '%s'. Nothing was updated.",
                $name,
            ));
        }

        $visiting[$name] = true;

        foreach ($updates[$name]['entry']['requires'] as $dependency) {
            $this->orderUpdate($dependency, $updates, $order, $visiting, $done);
        }

        unset($visiting[$name]);
        $done[$name] = true;
        $order[] = $name;
    }

    /**
     * Restore the catalogue copy captured before the last update and reinstall
     * it. Rollback is one step: the backup is consumed, so an operator cannot
     * walk arbitrarily far back through versions that were never kept.
     *
     * @return array{version: ?string, copied: int, flushed: int}
     *
     * @throws RuntimeException when there is no backup, the module is not
     *                          installed/catalogued, or restore fails
     */
    public function rollback(string $name): array
    {
        if (!ModuleCatalog::isValidName($name)) {
            throw new RuntimeException(sprintf("Invalid module name '%s'.", $name));
        }

        $lock = $this->readLock();

        if (!isset($lock['modules'][$name])) {
            throw new RuntimeException(sprintf("Module '%s' was not installed from the registry.", $name));
        }

        $locked = $lock['modules'][$name];
        $rollback = $locked['rollback'];

        if (null === $rollback) {
            throw new RuntimeException(sprintf("Module '%s' has no previous registry version to restore.", $name));
        }

        if (!$this->catalog->isInstalled($name)) {
            throw new RuntimeException(sprintf("Module '%s' is not installed.", $name));
        }

        $catalogue = $this->catalog->cataloguePath($name);

        if (null === $catalogue) {
            throw new RuntimeException(sprintf("Module '%s' is no longer catalogued.", $name));
        }

        $backup = $this->resolveBackup($rollback['catalogue']);

        if (!is_dir($backup)) {
            throw new RuntimeException(sprintf("Rollback backup for '%s' is missing.", $name));
        }

        $current = $this->backupCatalogue($name, $catalogue);
        $succeeded = false;

        try {
            $this->replaceCatalogue($name, $catalogue, $backup);
            $result = $this->installer->add($name);
            (new ModuleCatalogueIndex($this->catalog))->write();

            $lock['modules'][$name] = [
                'version'  => $rollback['version'],
                'sha256'   => $rollback['sha256'],
                'archive'  => $rollback['archive'],
                'rollback' => null,
            ];
            $this->writeLock($lock);
            $succeeded = true;

            self::remove($backup);
            self::remove($current['path']);

            return [
                'version' => $rollback['version'],
                'copied'  => $result['copied'],
                'flushed' => $result['flushed'],
            ];
        } catch (\Throwable $e) {
            try {
                $this->restoreCatalogue($name, $catalogue, $current['path']);
            } catch (\Throwable $restore) {
                throw new RuntimeException(sprintf(
                    'Rollback failed and the current version could not be restored: %s / %s',
                    $e->getMessage(),
                    $restore->getMessage(),
                ), 0, $e);
            }

            throw new RuntimeException('Rollback failed; current version restored: ' . $e->getMessage(), 0, $e);
        } finally {
            if (!$succeeded) {
                self::remove($current['path']);
            }
        }
    }

    /**
     * Drop a module's registry lock entry and its rollback backup.
     *
     * This is the lifecycle counterpart to ModuleInstaller::remove(): once the
     * slice is gone, keeping a lock entry would describe code that no longer
     * exists, and keeping the backup would leave an orphan in storage. It is
     * deliberately explicit rather than a side effect of state(), which must
     * remain a read.
     */
    public function forget(string $name): void
    {
        if (!ModuleCatalog::isValidName($name)) {
            throw new RuntimeException(sprintf("Invalid module name '%s'.", $name));
        }

        $lock = $this->readLock();

        if (!isset($lock['modules'][$name])) {
            return;
        }

        $rollback = $lock['modules'][$name]['rollback'];
        unset($lock['modules'][$name]);

        if ([] === $lock['modules']) {
            $lock['snapshot'] = null;
        }

        $this->writeLock($lock);

        if (null !== $rollback) {
            try {
                self::remove($this->resolveBackup($rollback['catalogue']));
            } catch (\Throwable) {
                // The lock is already clear; an orphan backup is not worth turning
                // a successful uninstall into a failure.
            }
        }
    }

    /**
     * Download the closure, stage every remote module and install it.
     *
     * @return array{copied: int, flushed: int, installed: list<string>}
     *
     * @throws RuntimeException on a missing dependency, cycle, checksum mismatch,
     *                          unsafe archive or install refusal
     */
    public function install(string $name): array
    {
        if (!ModuleCatalog::isValidName($name)) {
            throw new RuntimeException(sprintf("Invalid module name '%s'.", $name));
        }

        $entries = $this->entries();
        $plan = $this->plan($name, $entries);

        /** @var array<string, string> $staged module name => extracted directory */
        $staged = [];
        /** @var list<string> $temporary */
        $temporary = [];
        /** @var array<string, string> $copied module name => catalogue target */
        $copied = [];

        try {
            foreach ($plan as $module) {
                if ($this->catalog->isInstalled($module) || $this->catalog->isCatalogued($module)) {
                    continue;
                }

                if (!isset($entries[$module])) {
                    throw new RuntimeException(sprintf(
                        "Cannot install '%s': module '%s' is not installed, catalogued or in the registry.",
                        $name,
                        $module,
                    ));
                }

                [$directory, $archive] = $this->stage($module, $entries[$module]);
                $staged[$module] = $directory;
                $temporary[] = $directory;
                $temporary[] = $archive;
            }

            try {
                foreach ($staged as $module => $directory) {
                    $target = rtrim($this->catalogueRoot, '/') . '/' . $module;

                    if (is_dir($target)) {
                        throw new RuntimeException(sprintf("Module '%s' already exists in the catalogue.", $module));
                    }

                    ModuleInstaller::copyTree($directory, $target);
                    $copied[$module] = $target;
                }
            } catch (\Throwable $e) {
                foreach ($copied as $target) {
                    if (is_dir($target)) {
                        try {
                            ModuleInstaller::deleteTree($target);
                        } catch (\Throwable) {
                            // Best effort: the original failure is the one worth reporting.
                        }
                    }
                }

                throw $e;
            }

            try {
                $result = $this->installer->addWithDependencies($name);
            } catch (\Throwable $e) {
                foreach ($copied as $module => $target) {
                    // An installed slice is keeping this catalogue entry alive; only
                    // a module the installer never installed may be removed.
                    if (!$this->catalog->isInstalled($module) && is_dir($target)) {
                        try {
                            ModuleInstaller::deleteTree($target);
                        } catch (\Throwable) {
                            // Best effort: the install refusal is the failure worth reporting.
                        }
                    }
                }

                try {
                    (new ModuleCatalogueIndex($this->catalog))->write();
                } catch (\Throwable) {
                    // The install refusal is the failure worth reporting.
                }

                throw $e;
            }

            (new ModuleCatalogueIndex($this->catalog))->write();
            $this->rememberInstalled($staged, $entries);

            return $result;
        } finally {
            foreach ($temporary as $path) {
                self::remove($path);
            }
        }
    }

    /**
     * @param array<string, array{requires: list<string>, archive: string, sha256: string, version: ?string}> $entries
     *
     * @return list<string>
     */
    private function plan(string $name, array $entries): array
    {
        if ($this->catalog->isInstalled($name)) {
            throw new RuntimeException(sprintf("Module '%s' is already installed.", $name));
        }

        /** @var list<string> $plan */
        $plan = [];
        /** @var array<string, true> $visiting */
        $visiting = [];
        /** @var array<string, true> $done */
        $done = [];

        $this->walk($name, $name, $plan, $visiting, $done, $entries);

        return $plan;
    }

    /**
     * @param list<string>                                                                                     $plan
     * @param array<string, true>                                                                              $visiting
     * @param array<string, true>                                                                              $done
     * @param array<string, array{requires: list<string>, archive: string, sha256: string, version: ?string}> $entries
     */
    private function walk(
        string $module,
        string $root,
        array &$plan,
        array &$visiting,
        array &$done,
        array $entries,
    ): void {
        if (in_array($module, ModuleCatalog::KERNEL_MODULES, true) || isset($done[$module])) {
            return;
        }

        if (isset($visiting[$module])) {
            $path = array_keys($visiting);
            $start = array_search($module, $path, true);
            $cycle = false === $start ? [$module] : array_slice($path, $start);
            $cycle[] = $module;

            throw new RuntimeException(sprintf(
                "Cannot install '%s': dependency cycle: %s. Nothing was copied.",
                $root,
                implode(' -> ', $cycle),
            ));
        }

        $visiting[$module] = true;

        try {
            foreach ($this->requires($module, $entries) as $dependency) {
                if (in_array($dependency, ModuleCatalog::KERNEL_MODULES, true)
                    || $this->catalog->isInstalled($dependency)
                ) {
                    continue;
                }

                if (!isset($entries[$dependency]) && !$this->catalog->isCatalogued($dependency)) {
                    throw new RuntimeException(sprintf(
                        "Cannot install '%s': missing dependencies: %s (not installed, catalogued or in the registry). Nothing was copied.",
                        $root,
                        $dependency,
                    ));
                }

                $this->walk($dependency, $root, $plan, $visiting, $done, $entries);
            }
        } finally {
            unset($visiting[$module]);
        }

        $done[$module] = true;

        if (!$this->catalog->isInstalled($module)) {
            $plan[] = $module;
        }
    }

    /**
     * @param array<string, array{requires: list<string>, archive: string, sha256: string, version: ?string}> $entries
     *
     * @return list<string>
     */
    private function requires(string $module, array $entries): array
    {
        if ($this->catalog->isCatalogued($module)) {
            $requires = [];

            foreach ($this->catalog->manifest($module)->closure() as $dependency) {
                if ($dependency !== $module && !in_array($dependency, ModuleCatalog::KERNEL_MODULES, true)) {
                    $requires[$dependency] = true;
                }
            }

            return array_keys($requires);
        }

        if (!isset($entries[$module])) {
            throw new RuntimeException(sprintf("Module '%s' is not installed, catalogued or in the registry.", $module));
        }

        return $entries[$module]['requires'];
    }

    /**
     * Download, verify and extract one archive into a temporary directory.
     *
     * @param array{requires: list<string>, archive: string, sha256: string, version: ?string} $entry
     *
     * @return array{0: string, 1: string} extracted directory, archive file
     */
    private function stage(string $module, array $entry): array
    {
        $body = $this->transport->get($entry['archive'], $this->maxArchiveBytes);

        if (!hash_equals($entry['sha256'], hash('sha256', $body))) {
            throw new RuntimeException(sprintf(
                "Cannot install '%s': sha256 mismatch for %s. Nothing was copied.",
                $module,
                $entry['archive'],
            ));
        }

        $temp = tempnam(sys_get_temp_dir(), 'nativa-module-');

        if (false === $temp) {
            throw new RuntimeException('Could not create a temporary file for the module archive.');
        }

        $archive = $temp . '.tar.gz';

        if (!@rename($temp, $archive) || false === @file_put_contents($archive, $body)) {
            @unlink($temp);
            @unlink($archive);

            throw new RuntimeException(sprintf("Could not write the archive for '%s'.", $module));
        }

        $directory = sys_get_temp_dir() . '/nativa-module-' . $module . '-' . bin2hex(random_bytes(6));

        if (!mkdir($directory, 0o775, true) && !is_dir($directory)) {
            @unlink($archive);

            throw new RuntimeException(sprintf("Could not create a temporary directory for '%s'.", $module));
        }

        try {
            $this->extract($archive, $directory);
            $this->assertSafeTree($directory);
        } catch (\Throwable $e) {
            self::remove($directory);
            @unlink($archive);

            throw $e;
        }

        return [$directory, $archive];
    }

    private function extract(string $archive, string $directory): void
    {
        try {
            $phar = new \PharData($archive);
            $prefix = 'phar://' . $archive . '/';

            foreach (new \RecursiveIteratorIterator($phar) as $file) {
                /** @var \PharFileInfo $file */
                if ($file->isLink()) {
                    throw new RuntimeException('Registry archive contains a symlink.');
                }

                $path = str_replace('\\', '/', $file->getPathname());

                if (!str_starts_with($path, $prefix)) {
                    continue;
                }

                $relative = substr($path, strlen($prefix));

                if ('' === $relative
                    || str_starts_with($relative, '/')
                    || str_contains($relative, "\0")
                    || in_array('..', explode('/', $relative), true)
                ) {
                    throw new RuntimeException('Registry archive contains an unsafe path.');
                }
            }

            $phar->extractTo($directory, null, true);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('Registry archive could not be extracted: ' . $e->getMessage(), 0, $e);
        }
    }

    private function assertSafeTree(string $directory): void
    {
        $root = realpath($directory);

        if (false === $root) {
            throw new RuntimeException('Registry extraction directory disappeared.');
        }

        $files = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isLink()) {
                throw new RuntimeException('Registry archive extracted a symlink.');
            }

            $real = realpath($file->getPathname());

            if (false === $real || !($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Registry archive extracted a file outside its temporary directory.');
            }

            if ($file->isFile()) {
                ++$files;
            }
        }

        if (0 === $files) {
            throw new RuntimeException('Registry archive is empty.');
        }
    }

    /**
     * Record what a successful remote install put in the tree. Only modules
     * that actually came from the registry are remembered; a local catalogue
     * dependency is not an update candidate.
     *
     * @param array<string, string>                                                                               $staged
     * @param array<string, array{requires: list<string>, archive: string, sha256: string, version: ?string}> $entries
     */
    private function rememberInstalled(array $staged, array $entries): void
    {
        if ([] === $staged) {
            return;
        }

        $lock = $this->readLock();

        foreach ($staged as $module => $_directory) {
            $entry = $entries[$module];

            $lock['modules'][$module] = [
                'version'  => $entry['version'],
                'sha256'   => $entry['sha256'],
                'archive'  => $entry['archive'],
                'rollback' => null,
            ];

            if (null !== $entry['version']) {
                $lock['snapshot'] = $entry['version'];
            }
        }

        ksort($lock['modules']);
        $this->writeLock($lock);
    }

    /**
     * @return array{
     *     schema_version: int,
     *     snapshot: ?string,
     *     modules: array<string, array{
     *         version: ?string,
     *         sha256: string,
     *         archive: string,
     *         rollback: ?array{version: ?string, sha256: string, archive: string, catalogue: string}
     *     }>
     * }
     */
    private function readLock(): array
    {
        $empty = ['schema_version' => 1, 'snapshot' => null, 'modules' => []];

        if (!is_file($this->lockPath)) {
            return $empty;
        }

        $body = @file_get_contents($this->lockPath);

        if (false === $body) {
            throw new RuntimeException(sprintf('Cannot read the registry lock at %s.', $this->lockPath));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Registry lock is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)
            || 1 !== ($decoded['schema_version'] ?? null)
            || !isset($decoded['modules'])
            || !is_array($decoded['modules'])
        ) {
            throw new RuntimeException('Registry lock must be an object with schema_version 1 and a modules object.');
        }

        $snapshot = $decoded['snapshot'] ?? null;

        if (null !== $snapshot && !is_string($snapshot)) {
            throw new RuntimeException('Registry lock has a non-string snapshot.');
        }

        $modules = [];

        foreach ($decoded['modules'] as $name => $raw) {
            if (!is_string($name) || !ModuleCatalog::isValidName($name) || !is_array($raw)) {
                throw new RuntimeException('Registry lock has an invalid module entry.');
            }

            $version = $raw['version'] ?? null;
            $sha256 = $raw['sha256'] ?? null;
            $archive = $raw['archive'] ?? null;
            $rollback = $raw['rollback'] ?? null;

            if (null !== $version && (!is_string($version) || '' === trim($version))) {
                throw new RuntimeException(sprintf('Registry lock entry for %s has an invalid version.', $name));
            }

            if (!is_string($sha256) || 1 !== preg_match('/^[0-9a-f]{64}$/', $sha256)) {
                throw new RuntimeException(sprintf('Registry lock entry for %s has an invalid sha256.', $name));
            }

            if (!is_string($archive) || '' === trim($archive)) {
                throw new RuntimeException(sprintf('Registry lock entry for %s has no archive.', $name));
            }

            $cleanRollback = null;

            if (null !== $rollback) {
                if (!is_array($rollback)) {
                    throw new RuntimeException(sprintf('Registry lock entry for %s has an invalid rollback.', $name));
                }

                $rollbackVersion = $rollback['version'] ?? null;
                $rollbackSha = $rollback['sha256'] ?? null;
                $rollbackArchive = $rollback['archive'] ?? null;
                $rollbackCatalogue = $rollback['catalogue'] ?? null;

                if (null !== $rollbackVersion && (!is_string($rollbackVersion) || '' === trim($rollbackVersion))) {
                    throw new RuntimeException(sprintf('Registry lock rollback for %s has an invalid version.', $name));
                }

                if (!is_string($rollbackSha) || 1 !== preg_match('/^[0-9a-f]{64}$/', $rollbackSha)) {
                    throw new RuntimeException(sprintf('Registry lock rollback for %s has an invalid sha256.', $name));
                }

                if (!is_string($rollbackArchive) || '' === trim($rollbackArchive)) {
                    throw new RuntimeException(sprintf('Registry lock rollback for %s has no archive.', $name));
                }

                if (!is_string($rollbackCatalogue) || '' === trim($rollbackCatalogue)) {
                    throw new RuntimeException(sprintf('Registry lock rollback for %s has no catalogue backup.', $name));
                }

                $cleanRollback = [
                    'version'   => $rollbackVersion,
                    'sha256'    => $rollbackSha,
                    'archive'   => $rollbackArchive,
                    'catalogue' => $rollbackCatalogue,
                ];
            }

            $modules[$name] = [
                'version'  => $version,
                'sha256'   => $sha256,
                'archive'  => $archive,
                'rollback' => $cleanRollback,
            ];
        }

        ksort($modules);

        return ['schema_version' => 1, 'snapshot' => $snapshot, 'modules' => $modules];
    }

    /**
     * @param array{
     *     schema_version: int,
     *     snapshot: ?string,
     *     modules: array<string, array{
     *         version: ?string,
     *         sha256: string,
     *         archive: string,
     *         rollback: ?array{version: ?string, sha256: string, archive: string, catalogue: string}
     *     }>
     * } $lock
     */
    private function writeLock(array $lock): void
    {
        $directory = dirname($this->lockPath);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create the registry lock directory: %s', $directory));
        }

        try {
            $json = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('The registry lock could not be encoded: ' . $e->getMessage(), 0, $e);
        }

        $temp = tempnam($directory, '.module-registry-');

        if (false === $temp) {
            throw new RuntimeException('Could not create a temporary registry lock file.');
        }

        if (false === @file_put_contents($temp, $json . PHP_EOL, LOCK_EX)) {
            @unlink($temp);

            throw new RuntimeException(sprintf('Cannot write the registry lock at %s.', $this->lockPath));
        }

        @chmod($temp, 0o640);

        if (!@rename($temp, $this->lockPath)) {
            @unlink($temp);

            throw new RuntimeException(sprintf('Cannot replace the registry lock at %s.', $this->lockPath));
        }
    }

    /**
     * @return array{path: string, relative: string}
     */
    private function backupCatalogue(string $name, string $catalogue): array
    {
        $directory = dirname($this->lockPath) . '/module-registry-backups';

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create the registry backup directory: %s', $directory));
        }

        $relative = 'module-registry-backups/' . $name . '-' . bin2hex(random_bytes(6));
        $path = dirname($this->lockPath) . '/' . $relative;

        try {
            ModuleInstaller::copyTree($catalogue, $path);
        } catch (\Throwable $e) {
            self::remove($path);

            throw new RuntimeException(sprintf('Cannot back up %s before the update: %s', $name, $e->getMessage()), 0, $e);
        }

        return ['path' => $path, 'relative' => $relative];
    }

    private function resolveBackup(string $relative): string
    {
        if ('' === $relative
            || str_starts_with($relative, '/')
            || in_array('..', explode('/', str_replace('\\', '/', $relative)), true)
        ) {
            throw new RuntimeException('Registry lock contains an unsafe backup path.');
        }

        return dirname($this->lockPath) . '/' . $relative;
    }

    /**
     * Swap the catalogue in one sequence after the archive has been verified.
     * The caller has already captured a backup of the old catalogue.
     */
    private function replaceCatalogue(string $name, string $catalogue, string $source): void
    {
        if ($this->catalog->isInstalled($name)) {
            $this->installer->remove($name, true);
        }

        if (is_dir($catalogue)) {
            ModuleInstaller::deleteTree($catalogue);
        }

        ModuleInstaller::copyTree($source, $catalogue);
    }

    /**
     * Put a catalogue backup back and reinstall its slice. The module index is
     * rewritten here, so a failed update leaves both the tree and the generated
     * catalogue description consistent with the restored code.
     */
    private function restoreCatalogue(string $name, string $catalogue, string $backup): void
    {
        if ($this->catalog->isInstalled($name)) {
            $this->installer->remove($name, true);
        }

        if (is_dir($catalogue)) {
            ModuleInstaller::deleteTree($catalogue);
        }

        ModuleInstaller::copyTree($backup, $catalogue);
        $this->installer->add($name);
        (new ModuleCatalogueIndex($this->catalog))->write();
    }

    private function resolveArchive(string $archive): string
    {
        if (1 === preg_match('#^[a-z][a-z0-9+.-]*://#i', $archive)) {
            return $archive;
        }

        $base = parse_url($this->indexUrl);

        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            throw new RuntimeException('Registry index URL is not absolute.');
        }

        $origin = $base['scheme'] . '://' . $base['host']
            . (isset($base['port']) ? ':' . (int) $base['port'] : '');

        if (str_starts_with($archive, '/')) {
            return $origin . $archive;
        }

        $path = (string) ($base['path'] ?? '/');
        $slash = strrpos($path, '/');
        $directory = false === $slash ? '/' : substr($path, 0, $slash + 1);

        return $origin . $directory . $archive;
    }

    private static function remove(string $path): void
    {
        if (is_dir($path)) {
            try {
                ModuleInstaller::deleteTree($path);
            } catch (\Throwable) {
                // The temp directory is best-effort cleanup after the result is known.
            }

            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
