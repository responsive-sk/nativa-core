<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Tests;

use Modules\RestAdmin\Application\Mapping\ErrorMapper;
use Modules\RestAdmin\Infrastructure\Http\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ErrorMapperTest — the operator's next step, derived from the status.
 *
 * The assertions are deliberately about what the operator is TOLD, not about a
 * status-to-string table: a 401 says which env variable to check, a 5xx does
 * not repeat the service's body (it may contain internals), a 429 names the
 * retry delay when the service sends one, and a 422 keeps its field messages in
 * all three example shapes.
 *
 * A 422 with no field shape still gets a message under `_general`; the form
 * renders it above the inputs rather than pretending the answer was fine.
 */
final class ErrorMapperTest extends TestCase
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
     * @return iterable<string, array{int, string}>
     */
    public static function statuses(): iterable
    {
        yield '401' => [401, 'auth'];
        yield '403' => [403, 'forbidden'];
        yield '404' => [404, 'not_found'];
        yield '409' => [409, 'conflict'];
        yield '422' => [422, 'validation'];
        yield '429' => [429, 'rate_limit'];
        yield '418' => [418, 'client'];
        yield '500' => [500, 'server'];
        yield '503' => [503, 'server'];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function theKindNamesWhatWentWrong(int $status, string $kind): void
    {
        self::assertSame($kind, (new ErrorMapper())->map(new HttpResponse($status))->kind);
    }

    #[Test]
    public function aLaravelValidationBodyKeepsItsFieldMessages(): void
    {
        $error = (new ErrorMapper())->map(new HttpResponse(422, $this->fixture('error-422-laravel.json')));

        self::assertSame(['The title field is required.'], $error->fieldErrors['title']);
        self::assertSame(['The published at must be a date.'], $error->fieldErrors['published_at']);
        self::assertStringContainsString('invalid', strtolower($error->message));
    }

    #[Test]
    public function aDjangoFlatValidationBodyKeepsItsFieldMessages(): void
    {
        $error = (new ErrorMapper())->map(new HttpResponse(422, $this->fixture('error-422-django.json')));

        self::assertSame(['This field is required.'], $error->fieldErrors['title']);
        self::assertSame(['This field must be unique.'], $error->fieldErrors['slug']);
    }

    #[Test]
    public function aShopValidationBodyMapsItsObjectList(): void
    {
        $error = (new ErrorMapper())->map(new HttpResponse(422, $this->fixture('error-422-list.json')));

        self::assertSame(['This field is required.'], $error->fieldErrors['name']);
        self::assertSame(['The price must be positive.'], $error->fieldErrors['price']);
    }

    /**
     * A body that only says "Validation Error" must not be read as a FIELD
     * called `detail`; there is no field, so the message goes above the form.
     */
    #[Test]
    public function aValidationBodyWithoutFieldsStillHasAMessage(): void
    {
        $error = (new ErrorMapper())->map(new HttpResponse(422, '{"detail":"Validation Error"}'));

        self::assertSame(['Validation Error'], $error->fieldErrors['_general']);
    }

    #[Test]
    public function aMissingTokenPointsAtTheEnvironmentVariable(): void
    {
        $error = (new ErrorMapper())->map(new HttpResponse(401, $this->fixture('error-401.json')));

        self::assertSame('auth', $error->kind);
        self::assertStringContainsString('env variable', $error->message);
        self::assertStringNotContainsString('Unauthenticated.', $error->message);
    }

    #[Test]
    public function aRateLimitUsesTheRetryAfterTheServiceSent(): void
    {
        $error = (new ErrorMapper())->map(new HttpResponse(429, '', ['Retry-After' => '12']));

        self::assertSame('rate_limit', $error->kind);
        self::assertStringContainsString('12 seconds', $error->message);
    }

    /**
     * The body of a 5xx is not repeated: it routinely contains SQL, stack
     * traces and internal hostnames, and the panel is not a debugger.
     */
    #[Test]
    public function aServerFailureDoesNotReproduceItsBody(): void
    {
        $error = (new ErrorMapper())->map(new HttpResponse(500, '{"message":"SQLSTATE[HY000] secret host"}'));

        self::assertSame('server', $error->kind);
        self::assertStringNotContainsString('SQLSTATE', $error->message);
        self::assertStringNotContainsString('secret host', $error->message);
        self::assertStringContainsString('HTTP 500', $error->message);
    }

    #[Test]
    public function aNonJsonFailureIsDescribedWithoutEchoingIt(): void
    {
        $error = (new ErrorMapper())->map(new HttpResponse(502, '<html>upstream crashed</html>'));

        self::assertSame('server', $error->kind);
        self::assertStringNotContainsString('<html>', $error->message);
    }

    #[Test]
    public function aForbiddenAnswerSaysTheActionIsRefused(): void
    {
        $error = (new ErrorMapper())->map(new HttpResponse(403));

        self::assertSame('forbidden', $error->kind);
        self::assertStringContainsString('refused this action', $error->message);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }
}
