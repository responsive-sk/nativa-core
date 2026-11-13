<?php

declare(strict_types = 1);

namespace Modules\Tooling;

use JsonException;
use Modules\Core\Infrastructure\Env;
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
            $version = $raw['version'] ?? null;

            if (!is_string($archive) || '' === trim($archive)) {
                throw new RuntimeException(sprintf('Registry entry for %s has no archive.', $name));
            }

            if (!is_string($sha256) || 1 !== preg_match('/^[0-9a-f]{64}$/', $sha256)) {
                throw new RuntimeException(sprintf('Registry entry for %s has no lowercase sha256.', $name));
            }

            if (null !== $version && !is_string($version)) {
                throw new RuntimeException(sprintf('Registry entry for %s has a non-string version.', $name));
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
        /** @var list<string> $copied */
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
                    $copied[] = $target;
                }

                return $this->installer->addWithDependencies($name);
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
