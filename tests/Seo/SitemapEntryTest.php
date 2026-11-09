<?php

declare(strict_types = 1);

namespace Tests\Seo;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Seo\SitemapEntry;

final class SitemapEntryTest extends TestCase
{
    #[Test]
    public function aRootRelativePathKeepsItsPolicy(): void
    {
        $entry = new SitemapEntry('/blog/nativa-php-core', 'weekly', '0.8');

        self::assertSame('/blog/nativa-php-core', $entry->path);
        self::assertSame('weekly', $entry->changefreq);
        self::assertSame('0.8', $entry->priority);
    }

    #[Test]
    public function theRootAndLocaleRootsAreValid(): void
    {
        self::assertSame('/', (new SitemapEntry('/'))->path);
        self::assertSame('/en/', (new SitemapEntry('/en/'))->path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafePaths(): iterable
    {
        yield 'empty' => [''];

        yield 'missing leading slash' => ['blog'];

        yield 'protocol relative' => ['//example.test/blog'];

        yield 'absolute URL' => ['https://example.test/blog'];

        yield 'traversal' => ['/blog/../mark'];

        yield 'query' => ['/blog?q=x'];

        yield 'fragment' => ['/blog#top'];

        yield 'raw space' => ['/blog post'];

        yield 'control byte' => ["/blog\npost"];
    }

    #[Test]
    #[DataProvider('unsafePaths')]
    public function anUnsafePathIsRejected(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SitemapEntry($path);
    }

    #[Test]
    public function anUnknownPolicyFallsBackWithoutLosingTheUrl(): void
    {
        $entry = new SitemapEntry('/about', 'sometimes', '2.0');

        self::assertSame('/about', $entry->path);
        self::assertSame(SitemapEntry::DEFAULT_CHANGEFREQ, $entry->changefreq);
        self::assertSame(SitemapEntry::DEFAULT_PRIORITY, $entry->priority);
    }
}
