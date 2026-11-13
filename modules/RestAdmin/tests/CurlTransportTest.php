<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Tests;

use Modules\RestAdmin\Infrastructure\Http\CurlTransport;
use Modules\RestAdmin\Infrastructure\Http\HttpRequestSpec;
use Modules\RestAdmin\Infrastructure\Http\HttpTransportException;
use Modules\RestAdmin\Infrastructure\Http\SsrfGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CurlTransportTest — the real wire, against a local server.
 *
 * The suite must never depend on the internet, and a mocked curl would test
 * the mock. So this test starts `php -S` on a free loopback port with
 * `fixtures/server.php`, and checks the behaviours a remote API can spring on
 * the panel: a JSON body, a redirect, an endless body, a hang, and a non-2xx.
 *
 * Every request goes through `SsrfGuard` with `127.0.0.1` on the allowlist.
 * That is not a test-only shortcut: it is the same operator exception a
 * self-hosted API uses, and it proves the guard runs on the request path —
 * `refusesALoopbackTargetWithoutAnAllowlistEntry()` is the other half, where
 * the identical URL is refused before curl is reached.
 *
 * The server is started once per class. `proc_open` receives an argument
 * array, so no shell is involved and the router path needs no quoting.
 */
final class CurlTransportTest extends TestCase
{
    private const string MODULE = 'RestAdmin';

    private static mixed $server = null;

    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            return;
        }

        self::startFixtureServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        self::$server = null;
        self::$baseUrl = '';

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — its slice ships with the module.');
        }
    }

    #[Test]
    public function itReadsAJsonAnswerAndKeepsItsHeaders(): void
    {
        $response = $this->transport()->send(new HttpRequestSpec('GET', self::$baseUrl . '/json'));

        self::assertSame(200, $response->status);
        self::assertTrue($response->isSuccess());
        self::assertSame(['ok' => true], json_decode($response->body, true));
        self::assertSame('restadmin', $response->header('X-Test'));
    }

    /**
     * The body is what a create form sends, and the method must survive: a
     * POST that curls as a GET creates nothing and reports success.
     */
    #[Test]
    public function itSendsTheMethodBodyAndJsonHeaders(): void
    {
        $request = HttpRequestSpec::json('post', self::$baseUrl . '/echo', ['title' => 'Hello']);

        $response = $this->transport()->send($request);
        $echo = json_decode($response->body, true);

        self::assertIsArray($echo);
        self::assertSame('POST', $echo['method']);
        self::assertSame('{"title":"Hello"}', $echo['body']);
        self::assertSame('application/json', $echo['content_type']);
        self::assertSame('application/json', $echo['accept']);
    }

    /**
     * A followed redirect would be an SSRF bypass: the guard checked the URL
     * the operator wrote, and curl would fetch wherever the answer points.
     */
    #[Test]
    public function itDoesNotFollowARedirect(): void
    {
        $response = $this->transport()->send(new HttpRequestSpec('GET', self::$baseUrl . '/redirect'));

        self::assertSame(302, $response->status);
        self::assertSame('/json', $response->header('Location'));
        self::assertSame('', $response->body);
        self::assertFalse($response->isSuccess());
    }

    #[Test]
    public function itRefusesABodyLargerThanTheLimit(): void
    {
        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessageMatches('/more than 128 bytes/');

        $this->transport(maxBodyBytes: 128)->send(new HttpRequestSpec('GET', self::$baseUrl . '/large'));
    }

    /**
     * A 404 is an answer, not a failure: the mapping layer turns it into "this
     * item is gone", and only an unmade request throws.
     */
    #[Test]
    public function aNotFoundIsAnAnswerRatherThanAnException(): void
    {
        $response = $this->transport()->send(new HttpRequestSpec('GET', self::$baseUrl . '/missing'));

        self::assertSame(404, $response->status);
        self::assertSame('not found', $response->body);
    }

    /**
     * Declared last on purpose: the fixture server is single-process, so the
     * abandoned /slow request keeps it busy for a moment after the timeout.
     */
    #[Test]
    public function itStopsWaitingWhenTheServerDoesNotAnswer(): void
    {
        $this->expectException(HttpTransportException::class);

        $this->transport(timeout: 1)->send(new HttpRequestSpec('GET', self::$baseUrl . '/slow'));
    }

    /**
     * The same loopback URL without an allowlist entry is refused by the
     * guard, before a socket exists — this is the acceptance criterion
     * "no request leaves the process without passing SsrfGuard".
     */
    #[Test]
    public function refusesALoopbackTargetWithoutAnAllowlistEntry(): void
    {
        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessageMatches('/not a public address/');

        (new CurlTransport(new SsrfGuard()))->send(new HttpRequestSpec('GET', self::$baseUrl . '/json'));
    }

    private function transport(int $timeout = 5, int $maxBodyBytes = 2_097_152): CurlTransport
    {
        return new CurlTransport(
            new SsrfGuard(['127.0.0.1']),
            timeout: $timeout,
            maxBodyBytes: $maxBodyBytes,
        );
    }

    private static function startFixtureServer(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if (false === $probe) {
            self::fail(sprintf('Could not reserve a loopback port: %s (%d).', $error, $errno));
        }

        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $router = __DIR__ . '/fixtures/server.php';

        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
        );

        if (!is_resource($server)) {
            self::fail('Could not start the fixture server with php -S.');
        }

        self::$server = $server;
        self::$baseUrl = 'http://127.0.0.1:' . $port;

        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(20_000);
        }

        self::fail('The fixture server did not accept a connection within five seconds.');
    }
}
