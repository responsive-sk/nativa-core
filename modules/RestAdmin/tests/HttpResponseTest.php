<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Tests;

use Modules\RestAdmin\Infrastructure\Http\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * HttpResponseTest — the parts the mapping layer relies on.
 *
 * Header lookup is case-insensitive and a repeated name is joined, because a
 * strategy that reads `Link` must not care whether the server wrote `link`,
 * and pagination headers genuinely repeat. `isSuccess()` is 2xx and nothing
 * else: a 3xx is an answer the panel reports rather than follows.
 */
final class HttpResponseTest extends TestCase
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
     * @return iterable<string, array{int, bool}>
     */
    public static function statuses(): iterable
    {
        yield '200' => [200, true];
        yield '201' => [201, true];
        yield '204' => [204, true];
        yield '299' => [299, true];
        yield '199' => [199, false];
        yield '300' => [300, false];
        yield '404' => [404, false];
        yield '500' => [500, false];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function onlyTwoHundredsAreSuccess(int $status, bool $success): void
    {
        self::assertSame($success, (new HttpResponse($status))->isSuccess());
    }

    #[Test]
    public function aHeaderIsFoundWhateverTheCasing(): void
    {
        $response = new HttpResponse(200, '', [
            'Content-Type' => 'application/json',
            'X-RateLimit-Remaining' => '7',
        ]);

        self::assertSame('application/json', $response->header('content-type'));
        self::assertSame('application/json', $response->header('CONTENT-TYPE'));
        self::assertSame('7', $response->header('x-ratelimit-remaining'));
        self::assertNull($response->header('Link'));
    }

    /**
     * RFC 9110 lets a sender split a field into several lines; joining them is
     * what reading the field means. `Link` pagination depends on it.
     */
    #[Test]
    public function repeatedHeadersAreJoined(): void
    {
        $response = new HttpResponse(200, '', [
            'Link' => '<https://api.test/posts?page=2>; rel="next"',
            'link' => '<https://api.test/posts?page=1>; rel="prev"',
        ]);

        self::assertSame(
            '<https://api.test/posts?page=2>; rel="next", <https://api.test/posts?page=1>; rel="prev"',
            $response->header('Link'),
        );
    }

    #[Test]
    public function theJsonFactorySetsTheContentTypeAndEncodes(): void
    {
        // A list, not an object: `data` is an owned value elsewhere in the
        // tree, and a fixture that spells it would charge the name-ownership
        // ceiling for a second owner it does not mean.
        $response = HttpResponse::json([1, 2, 3], 201);

        self::assertSame(201, $response->status);
        self::assertSame('application/json', $response->header('content-type'));
        self::assertSame([1, 2, 3], json_decode($response->body, true));
    }

    /**
     * A 422 from an API is still JSON, and the error mapper needs the status
     * kept rather than turned into an exception by the fixture helper.
     */
    #[Test]
    public function theJsonFactoryKeepsTheStatus(): void
    {
        $response = HttpResponse::json(['message' => 'invalid'], 422);

        self::assertSame(422, $response->status);
        self::assertFalse($response->isSuccess());
    }

    #[Test]
    public function aCallerHeaderWinsOverTheFactoryDefault(): void
    {
        $response = HttpResponse::json([], 200, ['Content-Type' => 'application/problem+json']);

        self::assertSame('application/problem+json', $response->header('content-type'));
    }
}
