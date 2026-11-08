<?php

declare(strict_types = 1);

namespace Modules\Tooling;

/**
 * ModuleManifest — what one module contributes, as read from its own files.
 *
 * A manifest is a MEASUREMENT, not a declaration: routes come from `#[Route]`,
 * migrators from `#[AsMigrator]`, middleware from `#[AsMiddleware]`, nav badges
 * from `#[AsNavBadge]`, markdown blocks from `#[AsMarkdownBlock]`, shell
 * providers from `#[AsShellContextProvider]`, themes from `implements
 * ThemeModule`, and the dependency closure from `use Modules\<X>\…` across the
 * module's own files. Every read is text over the file body — the catalogue
 * never loads a class it describes, so a module whose dependencies are absent
 * is still describable (a scanner that fatals on a missing class cannot report
 * the very closure that is missing).
 *
 * An optional `module.json` exists ONLY for what a scan cannot see (an npm
 * package, a PHP package, a runtime dependency on another module). A module
 * written before this capability existed installs unchanged, on the strength
 * of the scan alone.
 */
final readonly class ModuleManifest
{
    /**
     * @param list<array{file: string, path: string, method: string, name: string|null}> $routes         files are relative to the module root
     * @param list<string>                                                               $migrators      relative file paths carrying #[AsMigrator]
     * @param list<string>                                                               $middleware     relative file paths carrying #[AsMiddleware]
     * @param list<string>                                                               $navBadges      relative file paths carrying #[AsNavBadge]
     * @param list<string>                                                               $markdownBlocks relative file paths carrying #[AsMarkdownBlock]
     * @param list<string>                                                               $shellProviders relative file paths carrying #[AsShellContextProvider]
     * @param list<string>                                                               $themeModules   relative file paths implementing ThemeModule
     * @param list<string>                                                               $assets         relative file paths under any assets/ directory
     * @param list<string>                                                               $dependencies   other module names this module imports (never self, Core or Database)
     * @param array<string, mixed>|null                                                  $extra          the decoded module.json, or null when the module ships none
     */
    public function __construct(
        public string $name,
        public string $sourceDir,
        public array $routes = [],
        public array $migrators = [],
        public array $middleware = [],
        public array $navBadges = [],
        public array $markdownBlocks = [],
        public array $shellProviders = [],
        public array $themeModules = [],
        public array $assets = [],
        public array $dependencies = [],
        public ?array $extra = null,
    ) {
    }

    /**
     * The SQLite file this module would own, derived from the module name —
     * the same convention the tree already uses (users.db, locales.db).
     */
    public function sqliteFile(): string
    {
        return strtolower($this->name) . '.db';
    }

    /**
     * Every dependency the scan cannot see: module.json `depends`, if any.
     *
     * @return list<string>
     */
    public function declaredDependencies(): array
    {
        $depends = $this->extra['depends'] ?? [];

        if (!is_array($depends)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $depends,
            static fn (mixed $dep): bool => is_string($dep) && '' !== $dep,
        )));
    }

    /**
     * The full closure: scanned imports plus declared extras, de-duplicated.
     *
     * @return list<string>
     */
    public function closure(): array
    {
        return array_values(array_unique([...$this->dependencies, ...$this->declaredDependencies()]));
    }
}
