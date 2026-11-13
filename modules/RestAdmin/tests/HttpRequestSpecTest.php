<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Tests;

use Modules\RestAdmin\Infrastructure\Http\HttpRequestSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * HttpRequestSpecTest — the decisions made before a socket exists.
 *
 * Two of them are security-shaped and cheap to get wrong: a header value
 * carrying CR/LF can start another header (request splitting), and a spec is
 * the object a crash report prints, so an Authorization header or a form body
 * must not survive `var_dump()`.
 */
final class HttpRequestSpecTest extends TestCase
{
    private const string MODULE = 'RestAdmin';

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — its slice ships with the module.');
        }
    }

    #[Test]
    public function theMethodIsNormalisedAndChecked(): void
    {
        self::assertSame('PATCH', (new HttpRequestSpec('patch', 'https://api.test/posts/1'))->method);

        $this->expectException(\InvalidArgumentException::class);

        new HttpRequestSpec('TRACE', 'https://api.test/posts');
    }

    #[Test]
    public function headerNamesAreLowerCasedBecauseHttpOnesAreCaseInsensitive(): void
    {
        $request = new HttpRequestSpec('GET', 'https://api.test/posts', [
            'Accept' => 'application/json',
            'X-Service' => 'blog',
        ]);

        self::assertSame(
            ['accept' => 'application/json', 'x-service' => 'blog'],
            $request->headers,
        );
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function splittingHeaders(): iterable
    {
        yield 'crlf in value' => [['X-Service' => "blog\r\nX-Admin: 1"]];
        yield 'lf in value' => [['X-Service' => "blog\nX-Admin: 1"]];
        yield 'cr in name' => [["X-Service\r\nX-Admin" => '1']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('splittingHeaders')]
    public function aHeaderThatCouldStartAnotherHeaderIsRefused(array $headers): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HttpRequestSpec('GET', 'https://api.test/posts', $headers);
    }

    #[Test]
    public function theJsonFactorySetsBothJsonHeadersAndEncodesTheBody(): void
    {
        $request = HttpRequestSpec::json('post', 'https://api.test/posts', ['title' => 'Hello']);

        self::assertSame('POST', $request->method);
        self::assertSame('application/json', $request->headers['content-type']);
        self::assertSame('application/json', $request->headers['accept']);
        self::assertSame('{"title":"Hello"}', $request->body);
    }

    #[Test]
    public function aCallerHeaderWinsOverTheJsonFactoryDefault(): void
    {
        $request = HttpRequestSpec::json('GET', 'https://api.test/posts', [], ['Accept' => 'application/vnd.api+json']);

        self::assertSame('application/vnd.api+json', $request->headers['accept']);
    }

    /**
     * The redaction is the assertion: a dumped spec names the credential's
     * presence, never its value, and the body reports its size instead of the
     * password it may carry.
     */
    #[Test]
    public function inspectionRedactsCredentials(): void
    {
        $request = new HttpRequestSpec(
            'POST',
            'https://api.test/login',
            ['Authorization' => 'Bearer super-secret-token', 'X-Api-Key' => 'key-123'],
            '{"password":"hunter2"}',
        );

        $dump = print_r($request->__debugInfo(), true);

        self::assertStringNotContainsString('super-secret-token', $dump);
        self::assertStringNotContainsString('key-123', $dump);
        self::assertStringNotContainsString('hunter2', $dump);
        self::assertStringContainsString('***redacted***', $dump);
        self::assertStringContainsString('(22 bytes)', $dump);
    }
}
