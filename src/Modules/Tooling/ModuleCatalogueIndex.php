<?php

declare(strict_types = 1);

namespace Modules\Tooling;

use RuntimeException;

/**
 * ModuleCatalogueIndex — the generated, machine-readable form of `modules/`.
 *
 * The directory tree remains the catalogue; this class only records what the
 * scanner sees: each module's source path and direct dependency closure. It is
 * used by the CLI generator and by ModuleRegistry after a remote install, so
 * there is one definition of the index rather than a build script and a runtime
 * copy that can disagree.
 */
final readonly class ModuleCatalogueIndex
{
    public function __construct(
        private ModuleCatalog $catalog,
    ) {
    }

    /**
     * @return array{schema_version: int, modules: array<string, array{source: string, requires: list<string>}>}
     *
     * @throws RuntimeException when a module cannot be described or a dependency
     *                          is not catalogued
     */
    public function build(): array
    {
        $known = array_flip($this->catalog->catalogued());
        $modules = [];
        $root = dirname($this->catalog->catalogueRoot());

        foreach ($this->catalog->catalogued() as $name) {
            $manifest = $this->catalog->manifest($name);
            $requires = [];

            foreach ($manifest->closure() as $dependency) {
                if ($dependency === $name || in_array($dependency, ModuleCatalog::KERNEL_MODULES, true)) {
                    continue;
                }

                if (!isset($known[$dependency])) {
                    throw new RuntimeException(sprintf(
                        "Module '%s' requires '%s', which is not catalogued. Add it to modules/ or remove the import.",
                        $name,
                        $dependency,
                    ));
                }

                $requires[$dependency] = true;
            }

            $requires = array_keys($requires);
            sort($requires);

            $source = $this->catalog->cataloguePath($name);

            if (null === $source) {
                throw new RuntimeException(sprintf("Module '%s' has no catalogue path.", $name));
            }

            $modules[$name] = [
                'source' => $this->relativePath($root, $source),
                'requires' => $requires,
            ];
        }

        ksort($modules);

        return [
            'schema_version' => 1,
            'modules' => $modules,
        ];
    }

    /**
     * @param array{schema_version: int, modules: array<string, array{source: string, requires: list<string>}>} $index
     */
    public function encode(array $index): string
    {
        $json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $json) {
            throw new RuntimeException('The catalogue index could not be encoded as JSON.');
        }

        return $json . PHP_EOL;
    }

    /**
     * Rewrite modules/catalogue.json and return the module count.
     *
     * @throws RuntimeException when the file cannot be written
     */
    public function write(): int
    {
        $index = $this->build();
        $path = $this->catalog->catalogueRoot() . '/catalogue.json';

        if (false === @file_put_contents($path, $this->encode($index))) {
            throw new RuntimeException(sprintf('Cannot write %s.', $path));
        }

        return count($index['modules']);
    }

    /**
     * Describe why a file differs from the generated index, for the CLI gate.
     *
     * @param array<string, array{source: string, requires: list<string>}> $expected
     */
    public function drift(array $expected, mixed $current): string
    {
        if (!is_array($current) || !isset($current['modules']) || !is_array($current['modules'])) {
            return 'the file is not a catalogue index';
        }

        $currentModules = $current['modules'];
        $expectedNames = array_keys($expected);
        $currentNames = array_keys($currentModules);

        $missing = array_values(array_diff($expectedNames, $currentNames));
        $extra = array_values(array_diff($currentNames, $expectedNames));
        $changed = [];

        foreach (array_intersect($expectedNames, $currentNames) as $name) {
            if (($currentModules[$name] ?? null) !== $expected[$name]) {
                $changed[] = $name;
            }
        }

        $parts = [];

        if ([] !== $missing) {
            $parts[] = 'missing: ' . implode(', ', $missing);
        }

        if ([] !== $extra) {
            $parts[] = 'extra: ' . implode(', ', $extra);
        }

        if ([] !== $changed) {
            $parts[] = 'changed: ' . implode(', ', $changed);
        }

        return [] === $parts ? 'the file differs from the generated index' : implode('; ', $parts);
    }

    private function relativePath(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $path = str_replace('\\', '/', $path);

        if (!str_starts_with($path, $root)) {
            throw new RuntimeException(sprintf('%s is outside the catalogue root %s', $path, $root));
        }

        return substr($path, strlen($root));
    }
}
