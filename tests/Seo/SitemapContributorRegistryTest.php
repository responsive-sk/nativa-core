<?php

declare(strict_types = 1);

namespace Tests\Seo;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Seo\SitemapContributor;
use Seo\SitemapContributorRegistry;
use Seo\SitemapEntry;

final class SitemapContributorRegistryTest extends TestCase
{
    private const string MODULE = 'TempSeo';

    protected function tearDown(): void
    {
        $dir = APP_BASE_PATH . '/src/Modules/' . self::MODULE;

        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }

    #[Test]
    public function aFixedRegistryReturnsItsContributorsInOrder(): void
    {
        $registry = SitemapContributorRegistry::fromContributors([
            self::contributor('/first'),
            self::contributor('/second'),
        ]);

        self::assertSame(
            ['/first', '/second'],
            array_map(static fn (SitemapEntry $entry): string => $entry->path, $registry->entries()),
        );
    }

    #[Test]
    public function aThrowingContributorDoesNotHideTheHealthyOne(): void
    {
        $broken = new class () implements SitemapContributor {
            public function entries(): array
            {
                throw new RuntimeException('fixture failure');
            }
        };

        $registry = SitemapContributorRegistry::fromContributors([
            $broken,
            self::contributor('/healthy'),
        ]);

        self::assertSame(
            ['/healthy'],
            array_map(static fn (SitemapEntry $entry): string => $entry->path, $registry->entries()),
        );
    }

    #[Test]
    public function discoveryOrdersByPriorityThenClassName(): void
    {
        self::fixture('BLowSitemapContributor.php', 0, '/low');
        self::fixture('AHighSitemapContributor.php', 10, '/high');

        $paths = array_map(
            static fn (SitemapEntry $entry): string => $entry->path,
            SitemapContributorRegistry::fromModules()->entries(),
        );

        self::assertContains('/high', $paths);
        self::assertContains('/low', $paths);
        self::assertLessThan(
            array_search('/low', $paths, true),
            array_search('/high', $paths, true),
            'A priority-10 contributor sorts before a priority-0 contributor.',
        );
    }

    private static function contributor(string $path): SitemapContributor
    {
        return new class ($path) implements SitemapContributor {
            public function __construct(private readonly string $path)
            {
            }

            public function entries(): array
            {
                return [new SitemapEntry($this->path)];
            }
        };
    }

    private static function fixture(string $file, int $priority, string $path): void
    {
        $dir = APP_BASE_PATH . '/src/Modules/' . self::MODULE . '/Application/Services';

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create sitemap fixture dir: ' . $dir);
        }

        $class = basename($file, '.php');

        file_put_contents($dir . '/' . $file, sprintf(
            <<<'PHP'
                <?php

                declare(strict_types = 1);

                namespace Modules\TempSeo\Application\Services;

                use Seo\AsSitemapContributor;
                use Seo\SitemapContributor;
                use Seo\SitemapEntry;

                #[AsSitemapContributor(priority: %d)]
                final class %s implements SitemapContributor
                {
                    public function entries(): array
                    {
                        return [new SitemapEntry('%s')];
                    }
                }

                PHP,
            $priority,
            $class,
            $path,
        ));
    }
}
