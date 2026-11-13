<?php

declare(strict_types = 1);

namespace Tests\Modules;

use Modules\Tooling\CurlRegistryTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * CurlRegistryTransportTest — the transport's first policy check runs before
 * curl, so a non-HTTPS registry is refused without a network request.
 */
final class CurlRegistryTransportTest extends TestCase
{
    #[Test]
    #[DataProvider('refusedUrls')]
    public function refusesUrlsOutsideThePolicy(string $url): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Registry URL must use HTTPS');

        (new CurlRegistryTransport())->get($url, 1024);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedUrls(): iterable
    {
        yield 'plain HTTP outside localhost' => ['http://example.test/registry.json'];

        yield 'file scheme'                  => ['file:///tmp/registry.json'];

        yield 'credentials in URL'           => ['https://user:pass@example.test/registry.json'];

        yield 'missing host'                 => ['https:///registry.json'];
    }
}
