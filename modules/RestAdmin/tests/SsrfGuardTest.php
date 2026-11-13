<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Tests;

use Modules\RestAdmin\Infrastructure\Http\SsrfGuard;
use Modules\RestAdmin\Infrastructure\Http\HttpTransportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SsrfGuardTest — the address table, not a live socket.
 *
 * Every case is a spelling of the same question: may this server be asked to
 * fetch that? The table is the gate's whole value, so the entries are the
 * ranges an attacker actually reaches for — cloud metadata
 * (169.254.169.254), loopback, RFC 1918, CGNAT, and the IPv6 spellings that
 * wrap a private IPv4 address inside a public-looking literal.
 *
 * `localhost` is the one name in the table that resolves: it is the only
 * hostname a test can resolve without touching a DNS server, and it is
 * exactly the case a spelling-only check misses.
 *
 * The allowlist cases are the other half of the contract: an operator CAN
 * reach a loopback API, but only by writing it down, and the exception does
 * not extend to schemes or URLs carrying credentials.
 */
final class SsrfGuardTest extends TestCase
{
    private const string MODULE = 'RestAdmin';

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — its slice ships with the module.');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedUrls(): iterable
    {
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'gopher scheme' => ['gopher://93.184.216.34/'];
        yield 'ftp scheme' => ['ftp://93.184.216.34/'];
        yield 'loopback v4' => ['http://127.0.0.1/'];
        yield 'loopback with port' => ['http://127.0.0.1:8080/posts'];
        yield 'this host' => ['http://0.0.0.0/'];
        yield 'private 10/8' => ['http://10.0.0.5/'];
        yield 'private 172.16/12' => ['http://172.16.4.2/'];
        yield 'private 172.31/12 edge' => ['http://172.31.255.255/'];
        yield 'private 192.168/16' => ['http://192.168.1.1/'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'carrier grade nat' => ['http://100.64.0.1/'];
        yield 'ietf protocol assignment' => ['http://192.0.0.8/'];
        yield 'test net 1' => ['http://192.0.2.10/'];
        yield 'test net 2' => ['http://198.51.100.7/'];
        yield 'test net 3' => ['http://203.0.113.7/'];
        yield 'multicast' => ['http://224.0.0.1/'];
        yield 'reserved' => ['http://240.0.0.1/'];
        yield 'broadcast' => ['http://255.255.255.255/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'ipv6 link local' => ['http://[fe80::1]/'];
        yield 'ipv6 unique local' => ['http://[fc00::1]/'];
        yield 'ipv6 documentation' => ['http://[2001:db8::1]/'];
        yield 'ipv4 mapped loopback' => ['http://[::ffff:127.0.0.1]/'];
        yield 'ipv4 mapped metadata' => ['http://[::ffff:169.254.169.254]/'];
        yield 'ipv4 compatible loopback' => ['http://[::127.0.0.1]/'];
        yield 'credentials in url' => ['https://user:secret@93.184.216.34/'];
        yield 'no host' => ['http:///posts'];
        yield 'no scheme' => ['93.184.216.34/posts'];
        yield 'name that resolves to loopback' => ['http://localhost:8080/'];
    }

    #[Test]
    #[DataProvider('blockedUrls')]
    public function itRefusesAnAddressOnTheWrongSideOfTheWall(string $url): void
    {
        $this->expectException(HttpTransportException::class);

        (new SsrfGuard())->assertAllowed($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicUrls(): iterable
    {
        yield 'https literal' => ['https://93.184.216.34/posts'];
        yield 'http literal with port and query' => ['http://93.184.216.34:8080/posts?page=2'];
        yield 'public ipv6' => ['https://[2606:4700:4700::1111]/'];
        yield 'public ipv4 mapped' => ['https://[::ffff:8.8.8.8]/'];
    }

    #[Test]
    #[DataProvider('publicUrls')]
    public function itAllowsAPublicAddress(string $url): void
    {
        (new SsrfGuard())->assertAllowed($url);

        self::assertTrue(true, 'assertAllowed() returning is the assertion.');
    }

    #[Test]
    public function theAllowlistIsTheOnlyWayToReachALoopbackService(): void
    {
        (new SsrfGuard(['127.0.0.1']))->assertAllowed('http://127.0.0.1:8080/posts');

        self::assertTrue(true, 'assertAllowed() returning is the assertion.');
    }

    #[Test]
    public function theAllowlistIsCaseInsensitiveBecauseHostsAre(): void
    {
        (new SsrfGuard(['LOCALHOST']))->assertAllowed('http://LocalHost:8080/posts');

        self::assertTrue(true, 'assertAllowed() returning is the assertion.');
    }

    #[Test]
    public function theAllowlistDoesNotWidenToANeighbouringAddress(): void
    {
        $this->expectException(HttpTransportException::class);

        (new SsrfGuard(['127.0.0.1']))->assertAllowed('http://127.0.0.2/');
    }

    #[Test]
    public function theAllowlistDoesNotPermitAnotherScheme(): void
    {
        $this->expectException(HttpTransportException::class);

        (new SsrfGuard(['localhost']))->assertAllowed('file://localhost/etc/passwd');
    }

    #[Test]
    public function theAllowlistDoesNotPermitCredentialsInTheUrl(): void
    {
        $this->expectException(HttpTransportException::class);

        (new SsrfGuard(['127.0.0.1']))->assertAllowed('http://user:secret@127.0.0.1/');
    }

    /**
     * The message has to name the host so an operator can decide whether to
     * allow it — and it must not repeat the URL, which could carry a query.
     */
    #[Test]
    public function theRefusalNamesTheHostAndTheFix(): void
    {
        try {
            (new SsrfGuard())->assertAllowed('http://169.254.169.254/latest/meta-data/?token=abc');
        } catch (HttpTransportException $exception) {
            self::assertStringContainsString('169.254.169.254', $exception->getMessage());
            self::assertStringContainsString('RESTADMIN_ALLOWED_HOSTS', $exception->getMessage());
            self::assertStringNotContainsString('token=abc', $exception->getMessage());

            return;
        }

        self::fail('A link-local metadata address was allowed.');
    }
}
