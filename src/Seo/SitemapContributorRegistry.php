<?php

declare(strict_types = 1);

namespace Seo;

use FilesystemIterator;
use Modules\Core\Infrastructure\Paths\AppPaths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;

/**
 * SitemapContributorRegistry — discovers `#[AsSitemapContributor]` classes in
 * the installed module slices and answers their rows.
 *
 * The scan is lazy and request-scoped: it runs only when `/sitemap.xml` is
 * requested. A contributor that cannot be loaded, does not implement the port
 * or throws is skipped with a bounded log line; a failure in one module never
 * takes the kernel sitemap down. The registry knows no module class by name.
 */
final class SitemapContributorRegistry
{
    /** @var list<SitemapContributor>|null */
    private ?array $contributors;

    /**
     * @param list<SitemapContributor>|null $contributors null means discover from installed modules
     */
    private function __construct(?array $contributors = null)
    {
        $this->contributors = $contributors;
    }

    public static function fromModules(): self
    {
        return new self();
    }

    /**
     * A fixed registry for tests and explicit wiring. An empty list proves the
     * kernel-only fallback without writing a module to disk.
     *
     * @param list<SitemapContributor> $contributors
     */
    public static function fromContributors(array $contributors): self
    {
        return new self($contributors);
    }

    /**
     * @return list<SitemapEntry>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->contributors() as $contributor) {
            try {
                $produced = $contributor->entries();
            } catch (Throwable $exception) {
                self::logSkipped($contributor::class, $exception->getMessage());

                continue;
            }

            foreach ($produced as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return list<SitemapContributor>
     */
    private function contributors(): array
    {
        return $this->contributors ??= $this->discover();
    }

    /**
     * @return list<SitemapContributor>
     */
    private function discover(): array
    {
        $found = [];

        foreach (self::files() as $file) {
            try {
                $contributor = self::load($file);
            } catch (Throwable $exception) {
                self::logSkipped($file, $exception->getMessage());

                continue;
            }

            if (null !== $contributor) {
                $found[] = $contributor;
            }
        }

        usort(
            $found,
            static fn (array $a, array $b): int => [$b[0], get_class($a[1])] <=> [$a[0], get_class($b[1])],
        );

        return array_map(static fn (array $entry): SitemapContributor => $entry[1], $found);
    }

    /**
     * Candidate files under every installed module's `Application` and
     * `Interfaces` trees. The filename is the filter; no other file is loaded.
     *
     * @return list<string>
     */
    private static function files(): array
    {
        $root = AppPaths::instance()->src('Modules');
        $files = [];

        foreach (['Application', 'Interfaces'] as $layer) {
            $directories = glob($root . '/*/' . $layer, GLOB_ONLYDIR);

            if (!is_array($directories)) {
                continue;
            }

            foreach ($directories as $directory) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                );

                foreach ($iterator as $file) {
                    if ($file instanceof SplFileInfo
                        && $file->isFile()
                        && str_ends_with($file->getFilename(), 'SitemapContributor.php')
                    ) {
                        $files[] = $file->getPathname();
                    }
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array{int, SitemapContributor}|null
     */
    private static function load(string $file): ?array
    {
        $content = file_get_contents($file);

        if (!is_string($content) || !str_contains($content, 'AsSitemapContributor')) {
            return null;
        }

        $fqcn = self::resolveFqcn($content);

        if (null === $fqcn) {
            return null;
        }

        if (!class_exists($fqcn)) {
            require_once $file;
        }

        if (!class_exists($fqcn)) {
            return null;
        }

        $reflection = new ReflectionClass($fqcn);

        if (!$reflection->implementsInterface(SitemapContributor::class)) {
            return null;
        }

        $attributes = $reflection->getAttributes(AsSitemapContributor::class);

        if ([] === $attributes) {
            return null;
        }

        if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        $attribute = $attributes[0]->newInstance();

        /** @var SitemapContributor $instance */
        $instance = $reflection->newInstance();

        return [$attribute->priority, $instance];
    }

    /**
     * Extract the FQCN from a file body, never from prose. The pattern is the
     * same one the shell registry uses: a declaration starts a line or a
     * statement, and a docblock sentence is not a declaration.
     */
    private static function resolveFqcn(string $content): ?string
    {
        $namespace = '';
        $className = '';

        if (preg_match('/(?:^|;)\s*namespace\s+([\w\\\\]+)\s*;/m', $content, $namespaceMatch)) {
            $namespace = $namespaceMatch[1];
        }

        if (preg_match('/(?:^|;)\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $content, $classNameMatch)) {
            $className = $classNameMatch[1];
        }

        if ('' === $namespace || '' === $className) {
            return null;
        }

        return $namespace . '\\' . $className;
    }

    private static function logSkipped(string $class, string $reason): void
    {
        error_log(sprintf('[seo] sitemap contributor skipped: %s: %s', $class, $reason));
    }

}
