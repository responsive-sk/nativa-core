<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Tests;

use Modules\Clickhouse\Domain\ClickHouseError;
use Modules\Clickhouse\Infrastructure\Http\ClickHouseHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ClickHouseHttpClientTest — the parts of the wire that need no server.
 *
 * The error body is real (ClickHouse's own wording for a missing table), the
 * closed port is the `CloudinaryApi` precedent for proving a transport failure
 * is reported as one, and the URL assertions pin the two decisions that are
 * invisible in a running system: the trailing slash and `?database=`.
 */
final class ClickHouseHttpClientTest extends TestCase
{
    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Clickhouse')) {
            $this->markTestSkipped('Clickhouse is not installed.');
        }
    }

    protected function tearDown(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Clickhouse')) {
            return;
        }
    }

    #[Test]
    public function aServerErrorBodyBecomesACodeAndAnExceptionType(): void
    {
        $error = ClickHouseError::fromResponse(
            "Code: 60. DB::Exception: Table default.x doesn't exist. (UNKNOWN_TABLE) (version 24.8.4.13)\n",
        );

        self::assertSame(60, $error->getCode());
        self::assertSame('DB::Exception', $error->type);
        self::assertStringContainsString("Table default.x doesn't exist", $error->getMessage());
        self::assertSame(
            "ClickHouse error 60 (DB::Exception): Table default.x doesn't exist. (UNKNOWN_TABLE) (version 24.8.4.13)",
            (string) $error,
        );
    }

    #[Test]
    public function aTransportFailureHasNoServerCodeAndSaysSo(): void
    {
        $error = ClickHouseError::fromTransport('Connection refused');

        self::assertSame(0, $error->getCode());
        self::assertSame('transport', $error->type);
        self::assertSame('ClickHouse error 0 (transport): Connection refused', (string) $error);
    }

    #[Test]
    public function theDatabaseIsAQueryParameterAndTheUrlKeepsOneTrailingSlash(): void
    {
        $client = new ClickHouseHttpClient('http://localhost:8123/', 'default', 'secret');
        $withoutSlash = new ClickHouseHttpClient('http://localhost:8123', 'default', 'secret');

        self::assertSame('http://localhost:8123/', $client->requestUrl());
        self::assertSame('http://localhost:8123/?database=nativa_blog', $client->requestUrl('nativa_blog'));
        self::assertSame('http://localhost:8123/?database=nativa_blog', $withoutSlash->requestUrl('nativa_blog'));
    }

    #[Test]
    public function aClosedPortIsATransportErrorWithCodeZero(): void
    {
        $client = new ClickHouseHttpClient('http://127.0.0.1:1', 'default', 'secret', 1, 1);

        try {
            $client->execute('SELECT 1');
            self::fail('A closed port must not answer.');
        } catch (ClickHouseError $error) {
            self::assertSame(0, $error->getCode());
            self::assertSame('transport', $error->type);
            self::assertNotSame('', $error->getMessage());
        }
    }

    #[Test]
    public function debugInfoShowsNoPassword(): void
    {
        $client = new ClickHouseHttpClient('http://localhost:8123', 'default', 'hunter2-do-not-print');

        $dump = print_r($client->__debugInfo(), true);

        self::assertStringNotContainsString('hunter2-do-not-print', $dump);
        self::assertStringContainsString('***redacted***', $dump);
    }
}
