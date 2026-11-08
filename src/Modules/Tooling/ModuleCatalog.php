<?php

declare(strict_types = 1);

namespace Modules\Tooling;

use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * ModuleCatalog — given a name and the `modules/` catalogue, a ModuleManifest.
 *
 * Two roots, two directions: the CATALOGUE (`<root>/modules/<Name>/`, or
 * `<root>/modules/<Group>/<Name>/` once a module is filed under a group) is
 * where a module waits to be installed; the TREE (`<root>/src/Modules/<Name>/`,
 * or `<root>/src/Modules/<Group>/<Name>/`)
 * is where an installed module lives. `manifest()` reads either side — the scan
 * is identical, only the root differs — so `module:show` reports what WOULD be
 * installed before anything is copied.
 *
 * A GROUP is a catalogue directory whose children are modules (`Themes/`): it
 * is shape, not a module. `Themes` passes `isValidName()`, so it cannot be
 * excluded by syntax — `cataloguePath()` returns `null` for it, which is what
 * keeps `catalogued()` from listing it and `module:add Themes` from copying it.
 *
 * The install target never moves with the catalogue: a grouped module still
 * installs to `src/Modules/<Group>/<Name>/` and owns `Modules\<Group>\<Name>`, so
 * the two trees always have the same shape. Names that are IDENTITIES rather
 * than paths — the theme name `erik`, `lekalo/modules/erik/` — do not move.
 *
 * The kernel's own modules (`Core`, `Database`) are never catalogue entries
 * and never install targets: they are the tree, not something in it.
 */
final readonly class ModuleCatalog
{
    /** Modules that are the tree itself — never a dependency, never removable. */
    public const array KERNEL_MODULES = ['Core', 'Database', 'Tooling'];

    public function __construct(
        private string $catalogDir,
        private string $modulesDir,
    ) {
    }

    public static function fromAppPaths(): self
    {
        $paths = AppPaths::instance();

        return new self($paths->join('modules'), $paths->src('Modules'));
    }

    /**
     * A module name is a directory name that becomes a namespace segment —
     * PascalCase alphanumerics, nothing that can traverse (`..`, `/`) or hide
     * (` .`, leading dots). A name that fails this check is refused before any
     * path is built from it, so `module:remove ../foo` is a refusal, not a
     * traversal.
     */
    public static function isValidName(string $name): bool
    {
        return 1 === preg_match('/^[A-Z][A-Za-z0-9]*$/', $name);
    }

    /**
     * Catalogue directories that GROUP modules instead of being one.
     *
     * A method rather than a constant because the name-ownership ratchet counts
     * every named constant in the tree; a group is not a value worth spending one
     * of those on, and the list is read in one place.
     *
     * @return list<string>
     */
    public static function catalogueGroups(): array
    {
        return ['Themes'];
    }

    /**
     * Where a catalogued module lives: `modules/<Name>/`, or the grouped
     * `modules/<Group>/<Name>/`. `null` means "not catalogued" — and a group
     * name is always `null`, because a group is not a module.
     *
     * The flat location wins if both exist: a module carried twice is the
     * shape `ModuleCatalogueShapeTest` refuses, and resolving the old one keeps
     * the drift report honest until it is fixed.
     */
    public function cataloguePath(string $name): ?string
    {
        if (in_array($name, self::catalogueGroups(), true)) {
            return null;
        }

        $flat = $this->catalogDir . DIRECTORY_SEPARATOR . $name;

        if (is_dir($flat)) {
            return $flat;
        }

        foreach (self::catalogueGroups() as $group) {
            $grouped = $this->catalogDir . DIRECTORY_SEPARATOR . $group . DIRECTORY_SEPARATOR . $name;

            if (is_dir($grouped)) {
                return $grouped;
            }
        }

        return null;
    }

    /**
     * Catalogue entries: every module, installed or not, wherever it is filed.
     * Group directories are not entries; their children are.
     *
     * @return list<string>
     */
    public function catalogued(): array
    {
        $names = array_values(array_filter(
            $this->childDirs($this->catalogDir),
            static fn (string $name): bool => !in_array($name, self::catalogueGroups(), true),
        ));

        foreach (self::catalogueGroups() as $group) {
            foreach ($this->childDirs($this->catalogDir . DIRECTORY_SEPARATOR . $group) as $name) {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Installed feature modules: every subdirectory of src/Modules except the
     * kernel's own, wherever it is filed. A group directory is not a module;
     * its children are.
     *
     * @return list<string>
     */
    public function installed(): array
    {
        $names = array_values(array_filter(
            $this->childDirs($this->modulesDir),
            static fn (string $name): bool => !in_array($name, self::KERNEL_MODULES, true)
                && !in_array($name, self::catalogueGroups(), true),
        ));

        foreach (self::catalogueGroups() as $group) {
            foreach ($this->childDirs($this->modulesDir . DIRECTORY_SEPARATOR . $group) as $name) {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Where an installed module lives: `src/Modules/<Name>/`, or the grouped
     * `src/Modules/<Group>/<Name>/`.
     *
     * Constructive, not a check: a module that is not installed yet still
     * resolves to the path `module:add` should copy to. The group comes from
     * the catalogue (or from an already-installed grouped copy), so the two
     * trees have the same shape and the installer never has to guess.
     */
    public function installedPath(string $name): string
    {
        $base = $this->modulesDir . DIRECTORY_SEPARATOR;
        $group = $this->groupOf($name);

        return null === $group
            ? $base . $name
            : $base . $group . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * The namespace an installed module owns — the part `importedBy()` looks
     * for and the autoloader resolves: `Modules\<Name>` or
     * `Modules\<Group>\<Name>`.
     */
    public function namespaceOf(string $name): string
    {
        $group = $this->groupOf($name);

        return null === $group
            ? 'Modules\\' . $name
            : 'Modules\\' . $group . '\\' . $name;
    }

    public function isCatalogued(string $name): bool
    {
        return null !== $this->cataloguePath($name);
    }

    public function isInstalled(string $name): bool
    {
        // A group directory exists in src/Modules too (`Themes/` holds four
        // slices), so existence alone would report the GROUP as installed.
        if (in_array($name, self::catalogueGroups(), true)) {
            return false;
        }

        return is_dir($this->installedPath($name));
    }

    /**
     * Read the catalogue entry — what WOULD be installed, before anything is
     * copied.
     *
     * @throws \RuntimeException when the name is invalid or not catalogued
     */
    public function manifest(string $name): ModuleManifest
    {
        $this->assertValidName($name);

        if (in_array($name, self::catalogueGroups(), true)) {
            throw new \RuntimeException(sprintf(
                "'%s' is a catalogue GROUP (modules/%s/), not a module — it groups modules, so it cannot be installed or shown as one.",
                $name,
                $name,
            ));
        }

        $dir = $this->cataloguePath($name);

        if (null === $dir) {
            throw new \RuntimeException(sprintf("Module '%s' is not in the catalog (modules/%s/).", $name, $name));
        }

        return $this->scan($name, $dir);
    }

    /**
     * Read the installed slice — what IS installed.
     *
     * @throws \RuntimeException when the name is invalid or not installed
     */
    public function installedManifest(string $name): ModuleManifest
    {
        $this->assertValidName($name);

        if (in_array($name, self::catalogueGroups(), true)) {
            throw new \RuntimeException(sprintf("'%s' is a module GROUP, not an installed module.", $name));
        }

        $dir = $this->installedPath($name);

        if (!is_dir($dir)) {
            throw new \RuntimeException(sprintf("Module '%s' is not installed.", $name));
        }

        return $this->scan($name, $dir);
    }

    /**
     * How far the installed slice has drifted from the catalogue entry.
     *
     * The catalogue (`modules/<Name>/`, or `modules/<Group>/<Name>/`) is the SOURCE: it is tracked in git,
     * it is what `module:add` copies, and it is the side an author edits. The
     * slice (`src/Modules/<Name>/`, or `src/Modules/<Group>/<Name>/`) is generated and untracked, so nobody
     * compares them — which is exactly how a fix lands in the catalogue and
     * never reaches the running tree, or the other way round. This returns
     * the file-level answer, with counts and names, so `module:show` can
     * report it instead of an operator remembering.
     *
     * Compare CONTENT, not timestamps: a reinstall touches every mtime and a
     * checkout rewrites them, while the question is "is it the same code?".
     *
     * @return array{status: 'identical'|'drifted'|'not-installed'|'not-catalogued', testsOnly: bool, catalog: int, installed: int, onlyInCatalog: list<string>, onlyInInstalled: list<string>, changed: list<string>}
     *
     * @throws \RuntimeException when the name cannot be a namespace segment
     */
    public function drift(string $name): array
    {
        $this->assertValidName($name);

        $cataloguePath = $this->cataloguePath($name);
        $catalogued = null !== $cataloguePath;
        $installed = $this->isInstalled($name);

        $catalog = null !== $cataloguePath ? $this->relativeFiles($cataloguePath) : [];
        $slice = $installed ? $this->relativeFiles($this->installedPath($name)) : [];

        $onlyInCatalog = array_keys(array_diff_key($catalog, $slice));
        $onlyInInstalled = array_keys(array_diff_key($slice, $catalog));
        $changed = [];

        foreach (array_intersect_key($catalog, $slice) as $relative => $hash) {
            if ($hash !== $slice[$relative]) {
                $changed[] = $relative;
            }
        }

        sort($onlyInCatalog);
        sort($onlyInInstalled);
        sort($changed);

        if (!$catalogued) {
            $status = 'not-catalogued';
        } elseif (!$installed) {
            $status = 'not-installed';
        } elseif ([] === $onlyInCatalog && [] === $onlyInInstalled && [] === $changed) {
            $status = 'identical';
        } else {
            $status = 'drifted';
        }

        $differences = [...$onlyInCatalog, ...$onlyInInstalled, ...$changed];

        // A catalogue entry's tests are run from modules/ (phpunit.xml's
        // module suite); the slice never needs them, so a slice that predates
        // a test-only addition is not a runtime divergence. Anything else
        // under src/Modules is code the page loads, and it must match.
        $testsOnly = [] !== $differences && [] === array_filter(
            $differences,
            static fn (string $file): bool => !str_starts_with($file, 'tests/'),
        );

        return [
            'status'          => $status,
            'testsOnly'       => $testsOnly,
            'catalog'         => count($catalog),
            'installed'       => count($slice),
            'onlyInCatalog'   => $onlyInCatalog,
            'onlyInInstalled' => $onlyInInstalled,
            'changed'         => $changed,
        ];
    }

    /**
     * Closure members with no installed slice — the missing names a refusal
     * must carry. Empty means the module may be installed.
     *
     * @return list<string>
     */
    public function missingDependencies(ModuleManifest $manifest): array
    {
        $missing = [];

        foreach ($manifest->closure() as $dependency) {
            if (!in_array($dependency, self::KERNEL_MODULES, true) && !$this->isInstalled($dependency)) {
                $missing[] = $dependency;
            }
        }

        return $missing;
    }

    /**
     * Installed files importing `Modules\<$name>\…` — the reason a removal is
     * refused. Each entry names the file, so the operator knows what still
     * holds the module, rather than just hearing that something does.
     *
     * @return list<string> paths relative to the project root
     */
    public function importedBy(string $name): array
    {
        $this->assertValidName($name);

        $holders = [];
        $base = AppPaths::instance()->base() . DIRECTORY_SEPARATOR;

        foreach ($this->installed() as $installed) {
            if ($installed === $name) {
                continue;
            }

            $dir = $this->installedPath($installed);
            foreach ($this->phpFiles($dir) as $file) {
                $content = file_get_contents($file);

                if (is_string($content) && $this->importsModule($content, $name)) {
                    $holders[] = str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
                }
            }
        }

        sort($holders);

        return $holders;
    }

    /**
     * @throws \RuntimeException when the name cannot be a namespace segment
     */
    private function assertValidName(string $name): void
    {
        if (!self::isValidName($name)) {
            throw new \RuntimeException(sprintf(
                "Invalid module name '%s': a module name is a namespace segment (PascalCase alphanumerics).",
                $name,
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function childDirs(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $names = [];

        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            if (is_dir($dir . DIRECTORY_SEPARATOR . $entry)) {
                $names[] = $entry;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @return list<string> absolute paths
     */
    private function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Every regular file under `$dir`, keyed by its relative path and valued
     * by a content hash. Hidden files count too: a `.gitignore` copied into a
     * slice is still part of the slice.
     *
     * @return array<string, string>
     */
    private function relativeFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $prefix = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $relative = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
            $files[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = (string) md5_file($path);
        }

        ksort($files);

        return $files;
    }

    private function scan(string $name, string $dir): ModuleManifest
    {
        $routes = [];
        $migrators = [];
        $middleware = [];
        $navBadges = [];
        $markdownBlocks = [];
        $shellProviders = [];
        $themeModules = [];
        $assets = [];
        $dependencies = [];

        $prefix = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        foreach ($this->phpFiles($dir) as $file) {
            $content = file_get_contents($file);

            if (!is_string($content)) {
                continue;
            }

            $relative = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;

            $route = $this->parseRoute($content);
            if (null !== $route) {
                $routes[] = ['file' => $relative, ...$route];
            }

            if (str_contains($content, 'AsMigrator')) {
                $migrators[] = $relative;
            }

            if (str_contains($content, 'AsMiddleware')) {
                $middleware[] = $relative;
            }

            if (str_contains($content, 'AsNavBadge')) {
                $navBadges[] = $relative;
            }

            if (str_contains($content, 'AsMarkdownBlock')) {
                $markdownBlocks[] = $relative;
            }

            if (str_contains($content, 'AsShellContextProvider')) {
                $shellProviders[] = $relative;
            }

            if (1 === preg_match('/\bimplements\b[^{]*\bThemeModule\b/', $content)) {
                $themeModules[] = $relative;
            }

            foreach ($this->importedModules($content) as $module) {
                if ($module !== $name && !in_array($module, self::KERNEL_MODULES, true)) {
                    $dependencies[$module] = true;
                }
            }
        }

        foreach ($this->assetFiles($dir) as $file) {
            $assets[] = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
        }

        $extra = $this->readModuleJson($dir);

        // Sorted before construction: the manifest is readonly, and a readonly
        // property cannot be passed by reference to sort() afterwards.
        $dependencies = array_keys($dependencies);
        sort($dependencies);

        return new ModuleManifest(
            name: $name,
            sourceDir: $dir,
            routes: $routes,
            migrators: $migrators,
            middleware: $middleware,
            navBadges: $navBadges,
            markdownBlocks: $markdownBlocks,
            shellProviders: $shellProviders,
            themeModules: $themeModules,
            assets: $assets,
            dependencies: $dependencies,
            extra: $extra,
        );
    }

    /**
     * The `#[Route]` a file declares, or null when it declares none.
     *
     * Named arguments first (`path:`, `method:`, `name:` — the shape every
     * action in this tree uses); a first positional string is the fallback for
     * a module written before the convention settled. A file with several
     * `#[Route]` attributes reports the first — one action, one route is the
     * shape the router's own scan assumes (it registers every attribute it
     * finds, and a second route on one file is the module's business to keep).
     *
     * @return array{path: string, method: string, name: string|null}|null
     */
    private function parseRoute(string $content): ?array
    {
        if (1 !== preg_match('/#\[Route\((.*?)\)\]/s', $content, $match)) {
            return null;
        }

        $args = $match[1];
        $path = $this->stringArg($args, 'path');

        if (null === $path && 1 === preg_match("/'([^']+)'/", $args, $positional)) {
            $path = $positional[1];
        }

        if (null === $path) {
            return null;
        }

        return [
            'path'   => $path,
            'method' => $this->stringArg($args, 'method') ?? 'GET',
            'name'   => $this->stringArg($args, 'name'),
        ];
    }

    private function stringArg(string $args, string $name): ?string
    {
        if (1 === preg_match('/' . $name . ':\s*\'([^\']*)\'/', $args, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * Module names this file imports — `use Modules\<X>\…` at line start (a
     * closure `use (` never matches: it has no `Modules\` segment after the
     * keyword). A group-use reports its group owner.
     *
     * @return list<string>
     */
    private function importedModules(string $content): array
    {
        // preg_match_all returns the COUNT (or false), never 1 — compare
        // against false, not against 1, or every multi-import file scans clean.
        //
        // The second segment matters once a module is grouped: a theme's own
        // files import `Modules\\Themes\\Erik\\…`, so reading only the first
        // segment would turn its group into a dependency named `Themes` — a
        // module that does not exist and can never be installed.
        if (false === preg_match_all('/^use\s+Modules\\\\(\w+)(?:\\\\(\w+))?/m', $content, $matches)) {
            return [];
        }

        $modules = [];

        foreach ($matches[1] as $index => $first) {
            if (!in_array($first, self::catalogueGroups(), true)) {
                $modules[] = $first;

                continue;
            }

            $second = $matches[2][$index] ?? '';

            if ('' !== $second) {
                $modules[] = $second;
            }
        }

        return array_values(array_unique($modules));
    }

    private function importsModule(string $content, string $name): bool
    {
        // `preg_quote()` already escapes the namespace separators; escaping
        // them first would ask the regex for a double backslash.
        return 1 === preg_match('/^use\s+' . preg_quote($this->namespaceOf($name), '/') . '\\\\/m', $content);
    }

    /**
     * The group a module is filed under, or `null` for the flat layout. The
     * catalogue wins; an installed grouped copy is enough for a module whose
     * catalogue entry is gone (removal must still find its own files).
     */
    private function groupOf(string $name): ?string
    {
        foreach (self::catalogueGroups() as $group) {
            $catalogued = $this->catalogDir . DIRECTORY_SEPARATOR . $group . DIRECTORY_SEPARATOR . $name;
            $installed = $this->modulesDir . DIRECTORY_SEPARATOR . $group . DIRECTORY_SEPARATOR . $name;

            if (is_dir($catalogued) || is_dir($installed)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @return list<string> absolute paths of non-PHP files under any assets/ dir
     */
    private function assetFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || 'php' === $file->getExtension()) {
                continue;
            }

            $path = $file->getPathname();

            if (str_contains($path, DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR)) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readModuleJson(string $dir): ?array
    {
        $file = $dir . DIRECTORY_SEPARATOR . 'module.json';

        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (!is_array($decoded)) {
            return null;
        }

        // A JSON list is a malformed manifest, not a manifest without keys.
        $manifest = [];

        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                return null;
            }

            $manifest[$key] = $value;
        }

        return $manifest;
    }
}
