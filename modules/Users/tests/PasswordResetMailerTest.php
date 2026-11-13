<?php

declare(strict_types = 1);

namespace Modules\Users\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Security\Application\Services\RateLimiter;
use Modules\Users\Application\Services\PasswordResetService;
use Modules\Users\Domain\Contracts\PasswordResetMailer;
use Modules\Users\Infrastructure\Mail\DevLogPasswordResetMailer;
use Modules\Users\Infrastructure\Mail\HttpPasswordResetMailer;
use Modules\Users\Infrastructure\Mail\NullPasswordResetMailer;
use Modules\Users\Infrastructure\Mail\PasswordResetMailerFactory;
use Modules\Users\Infrastructure\Persistence\Migrations\UsersDatabaseMigrator;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;
use Modules\Users\Infrastructure\Persistence\Statement;
use Modules\Users\Interfaces\HTTP\Actions\Auth\PasswordResetRequestAction;
use Modules\Users\Interfaces\HTTP\AuthServices;
use PDO;
use Modules\Core\Interfaces\CLI\CliOutput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PasswordResetMailerTest — audit finding #1: `POST /forgot-password` used to
 * append a plaintext reset token for a live account to `storage/logs/app.log`
 * in EVERY environment, and the success page told the visitor the file by
 * name. Reading the log was enough to take the account over.
 *
 * The reproduction before the fix was an HTTP probe against the real route with
 * `APP_ENV=production` — it exited 1 with a `[mailer:dev] … token: <64 hex>`
 * line in the log. These assertions are the same claim inside the suite:
 *
 *  - production answers the flow, keeps its token row, and never touches the
 *    log — the file's bytes are compared, not just a "no string" look;
 *  - development still delivers where a developer looks, because a transport
 *    the starter kit does not have must not silently swallow the link;
 *  - an environment nobody classified (staging, a typo) is not development;
 *  - the service itself can no longer write a log at all: it hands the raw
 *    token to the injected port, which is the only thing that ever sees it.
 *
 * ## The two files these tests touch
 *
 * `DB_USERS` points at a temp database built by the module's own migrator (the
 * `AdminAccessControlTest` pattern), so the flow runs against real tables. The
 * dev-developer case drives the REAL `storage/logs/app.log`, because the path
 * the dev transport writes to is part of what the factory decides — so that one
 * test snapshots the file first and restores those exact bytes in tearDown(),
 * whether it existed or not.
 *
 * Like every module test it drives the INSTALLED copy and skips when the
 * module (or its dependencies) is not installed.
 */
final class PasswordResetMailerTest extends TestCase
{
    private const string EMAIL = 'mailer-probe@example.test';

    private string $tmpDir = '';

    private string $logFile = '';

    /** The log file's bytes before the test, or null when it did not exist. */
    private ?string $logBefore = null;

    /** The APP_ENV value before the test, or null when it was unset. */
    private ?string $appEnvBefore = null;

    /** The mail-transport keys before the test, by key, null when unset. */
    private array $mailEnvBefore = [];

    /** The keys the HTTP transport is configured from (`PasswordResetMailerFactory::http()`). */
    private const array MAIL_ENV_KEYS = ['MAIL_API_URL', 'MAIL_API_KEY', 'MAIL_FROM', 'APP_URL'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Users', 'Security', 'Validation'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-reset-mailer-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_USERS=' . $this->tmpDir . '/users.db');
        DatabaseConnectionManager::resetInstance();
        AuthServices::reset();

        (new UsersDatabaseMigrator(new PDO('sqlite:' . $this->tmpDir . '/users.db')))
            ->run($this->quietOutput());

        $this->logFile = AppPaths::instance()->logs('app.log');
        $this->logBefore = is_file($this->logFile) ? (string) file_get_contents($this->logFile) : null;

        $appEnv = getenv('APP_ENV');
        $this->appEnvBefore = false === $appEnv ? null : $appEnv;

        foreach (self::MAIL_ENV_KEYS as $key) {
            $value = getenv($key);
            $this->mailEnvBefore[$key] = false === $value ? null : $value;
        }
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even when setUp() skipped (module absent) —
        // then there is no env, no tmp dir and nothing to restore.
        if ('' !== $this->tmpDir && is_dir($this->tmpDir)) {
            $this->restoreLog();

            putenv('DB_USERS');

            if (null === $this->appEnvBefore) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $this->appEnvBefore);
            }

            foreach (self::MAIL_ENV_KEYS as $key) {
                $before = $this->mailEnvBefore[$key] ?? null;
                putenv(null === $before ? $key : $key . '=' . $before);
            }

            DatabaseConnectionManager::resetInstance();
            AuthServices::reset();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    /**
     * The finding, as an assertion: the route still works in production — the
     * token row is written, the answer stays neutral — and the log file's bytes
     * are exactly what they were before the request.
     */
    #[Test]
    public function productionAnswersTheFlowWithoutWritingTheTokenToTheLog(): void
    {
        putenv('APP_ENV=production');
        AuthServices::reset();

        self::assertFalse(
            PasswordResetMailerFactory::writesToLog(),
            'APP_ENV=production must not select the dev transport',
        );

        $response = (new PasswordResetRequestAction())->submit(
            Request::create('POST', '/forgot-password', [], ['email' => self::EMAIL]),
        );

        self::assertSame(200, $response->getStatusCode(), 'the reset request must still answer');
        self::assertSame(
            $this->logBefore,
            self::logContents($this->logFile),
            'POST /forgot-password with APP_ENV=production must not touch storage/logs/app.log — a plaintext token in there is a working credential for the account it names (before the fix: a [mailer:dev] line with the token)',
        );
        self::assertSame(
            1,
            self::resetTokenCount(),
            'the token row must still be written — the fix removes the log write, not the flow',
        );
        self::assertStringContainsString(
            'If an account exists for that email, a reset link has been sent.',
            (string) $response->getContent(),
        );
        self::assertStringNotContainsString(
            'storage/logs/app.log',
            (string) $response->getContent(),
            'the success page must not name a log file in an environment where nothing is written to one',
        );
    }

    /**
     * The other half of the gate: with APP_ENV=development the link really is
     * in the file a developer looks at, and the page says so. Without this the
     * "fix" would be indistinguishable from a mailer that drops everything.
     */
    #[Test]
    public function developmentDeliversTheLinkToTheLogFile(): void
    {
        putenv('APP_ENV=development');
        AuthServices::reset();

        self::assertTrue(PasswordResetMailerFactory::writesToLog());

        $response = (new PasswordResetRequestAction())->submit(
            Request::create('POST', '/forgot-password', [], ['email' => self::EMAIL]),
        );

        $added = substr(self::logContents($this->logFile), strlen((string) $this->logBefore));

        self::assertMatchesRegularExpression(
            '/\[mailer:dev\] Password reset for ' . preg_quote(self::EMAIL, '/') . ' → token: [0-9a-f]{64} \(valid 1 h\)/',
            $added,
            'the dev transport must append the link to storage/logs/app.log (got: ' . $added . ')',
        );
        self::assertStringContainsString('storage/logs/app.log', (string) $response->getContent());
    }

    /**
     * A whitelist, not "everything that is not production": an environment
     * nobody classified must not be the one that keeps a credential on disk.
     * The unset case is asserted too — this tree reads an unset APP_ENV as
     * development (same as the HSTS gate), which is what makes a fresh checkout
     * work without a `.env`.
     */
    #[Test]
    public function onlyEnvironmentsNamedAsDevelopmentKeepTheDevTransport(): void
    {
        $cases = [
            'production'  => false,
            'Production'  => false,
            'staging'     => false,
            'prodction'   => false,
            'development' => true,
            'local'       => true,
            'testing'     => true,
        ];

        foreach ($cases as $value => $expected) {
            putenv('APP_ENV=' . $value);

            self::assertSame(
                $expected,
                PasswordResetMailerFactory::writesToLog(),
                sprintf('APP_ENV=%s must%s select the dev transport', $value, $expected ? '' : ' not'),
            );
            self::assertInstanceOf(
                $expected ? DevLogPasswordResetMailer::class : NullPasswordResetMailer::class,
                PasswordResetMailerFactory::create(),
                sprintf('APP_ENV=%s resolved the wrong transport', $value),
            );
        }

        putenv('APP_ENV');

        self::assertTrue(
            PasswordResetMailerFactory::writesToLog(),
            'an unset APP_ENV is this tree\'s development default',
        );
    }

    /**
     * The dev transport's own contract, on a file the test owns: the line, the
     * address, the token and the TTL — the shape an operator (and this suite)
     * reads out of the log.
     */
    #[Test]
    public function theDevTransportWritesTheLinkIntoTheFileItWasGiven(): void
    {
        $file = $this->tmpDir . '/dev-mail.log';
        $token = str_repeat('a', 64);

        (new DevLogPasswordResetMailer($file))->sendResetLink(self::EMAIL, $token, 1);

        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \[mailer:dev\] Password reset for '
            . preg_quote(self::EMAIL, '/') . ' → token: ' . $token . ' \(valid 1 h\)$/m',
            (string) file_get_contents($file),
        );
    }

    /**
     * The root cause, not its symptom: the SERVICE has no log write left. Even
     * with a port that delivers nothing, asking for a reset writes no file —
     * and the port receives the raw token while the database keeps only its
     * hash, so the credential exists in exactly two places by design (the link
     * in transit and the hash at rest).
     */
    #[Test]
    public function theServiceHandsTheTokenToThePortAndWritesNothingItself(): void
    {
        $recorder = new class () implements PasswordResetMailer {
            /** @var list<array{email: string, token: string, ttl: int}> */
            public array $calls = [];

            public function sendResetLink(string $email, #[\SensitiveParameter] string $token, int $ttlHours): void
            {
                $this->calls[] = ['email' => $email, 'token' => $token, 'ttl' => $ttlHours];
            }
        };

        $db = DatabaseConnectionManager::instance()->connect('users');

        $service = new PasswordResetService(
            $db,
            new UserRepository($db),
            new PasswordHasher(),
            $recorder,
            new RateLimiter($db),
        );

        $service->requestReset('  Mailer-Probe@Example.Test  ', '127.0.0.1');

        self::assertCount(1, $recorder->calls, 'the link must be handed to the port exactly once');
        self::assertSame(self::EMAIL, $recorder->calls[0]['email'], 'the address must be normalised before delivery');
        self::assertSame(1, $recorder->calls[0]['ttl']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $recorder->calls[0]['token']);

        $stored = Statement::query($db, 'SELECT token FROM password_reset_tokens')->fetchColumn();

        self::assertSame(
            hash('sha256', $recorder->calls[0]['token']),
            $stored,
            'the database must keep the hash of the delivered token, never the token itself',
        );
        self::assertSame(
            $this->logBefore,
            self::logContents($this->logFile),
            'the service must not write a log of its own — that was the finding',
        );
    }

    /**
     * The way out of the state §7 left: a deployment that configures the three
     * keys gets a REAL transport in production — and one that configures only
     * part of them is refused loudly rather than delivering a link built on a
     * missing base URL.
     */
    #[Test]
    public function productionUsesTheConfiguredHttpTransportAndRefusesAHalfConfiguredOne(): void
    {
        putenv('APP_ENV=production');
        putenv('MAIL_API_URL=https://mail.example.test/send');
        putenv('MAIL_API_KEY=secret-key');
        putenv('MAIL_FROM=no-reply@example.test');
        putenv('APP_URL=https://app.example.test');

        self::assertInstanceOf(
            HttpPasswordResetMailer::class,
            PasswordResetMailerFactory::create(),
            'a fully configured production transport must be the mailer the service receives',
        );

        // Half a transport: the endpoint and the sender are there, the base URL
        // the link needs is not. A link nobody can follow is not a delivery, so
        // this must resolve to "nothing is delivered", not to a guessed host.
        putenv('APP_URL');

        $logFile = $this->tmpDir . '/factory.log';
        $logBefore = ini_get('error_log');
        // Routing `error_log()` to a probe file is the only way to assert what
        // the factory wrote; the previous value is restored in the finally.
        // @mago-expect lint:no-ini-set
        ini_set('error_log', $logFile);

        try {
            self::assertNull(
                PasswordResetMailerFactory::http(),
                'a transport missing APP_URL must not be used — the link would point at a host this tree does not own',
            );
            self::assertInstanceOf(
                NullPasswordResetMailer::class,
                PasswordResetMailerFactory::create(),
                'a half-configured deployment gets the null mailer, not a broken link',
            );
        } finally {
            // @mago-expect lint:no-ini-set
            ini_set('error_log', false === $logBefore ? '' : $logBefore);
        }

        self::assertStringContainsString(
            'missing APP_URL',
            (string) file_get_contents($logFile),
            'the refusal must name the missing key — otherwise "mail is configured, why is nothing arriving" is unanswerable',
        );
    }

    /**
     * The transport's documented contract, asserted without network egress: the
     * endpoint, the bearer header, the JSON shape, and the link a reader can
     * actually click.
     */
    #[Test]
    public function theHttpTransportPostsTheDocumentedContractWithAClickableLink(): void
    {
        $token = str_repeat('b', 64);
        $seen = [];

        $mailer = new HttpPasswordResetMailer(
            'https://mail.example.test/send',
            'secret-key',
            'no-reply@example.test',
            'https://app.example.test/',
            static function (string $endpoint, array $headers, string $body) use (&$seen): array {
                $seen = ['endpoint' => $endpoint, 'headers' => $headers, 'body' => $body];

                return ['status' => 202, 'body' => '{}'];
            },
        );

        $mailer->sendResetLink(self::EMAIL, $token, 1);

        self::assertSame('https://mail.example.test/send', $seen['endpoint'] ?? null);
        self::assertContains('Authorization: Bearer secret-key', $seen['headers'] ?? []);
        self::assertContains('Content-Type: application/json', $seen['headers'] ?? []);

        /** @var array{from: string, to: string, subject: string, text: string} $payload */
        $payload = json_decode((string) ($seen['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('no-reply@example.test', $payload['from']);
        self::assertSame(self::EMAIL, $payload['to']);
        self::assertNotSame('', $payload['subject']);
        self::assertStringContainsString(
            'https://app.example.test/reset-password?token=' . $token,
            $payload['text'],
            'the mail must carry the link the reset page reads, built from APP_URL (a trailing slash on the base must not double up)',
        );
        self::assertStringContainsString('expires in 1 hour(s)', $payload['text']);
    }

    /**
     * The failure path, through the REAL route: a transport that cannot deliver
     * must not break the flow, must not change the answer, and — the point of
     * §7 — must NEVER fall back to writing the token into the log.
     *
     * `MAIL_API_URL` points at a port nothing listens on, so the connection is
     * refused for real instead of being faked.
     */
    #[Test]
    public function aFailingTransportKeepsTheFlowNeutralAndNeverFallsBackToTheLog(): void
    {
        putenv('APP_ENV=production');
        putenv('MAIL_API_URL=http://127.0.0.1:9/nowhere');
        putenv('MAIL_FROM=no-reply@example.test');
        putenv('APP_URL=https://app.example.test');
        AuthServices::reset();

        self::assertInstanceOf(
            HttpPasswordResetMailer::class,
            PasswordResetMailerFactory::create(),
            'the configured transport is the one under test here',
        );

        $response = (new PasswordResetRequestAction())->submit(
            Request::create('POST', '/forgot-password', [], ['email' => self::EMAIL]),
        );

        self::assertSame(200, $response->getStatusCode(), 'a dead transport must not turn into a 500');
        self::assertSame(
            1,
            self::resetTokenCount(),
            'the token row is still written — delivery failing does not un-request the reset',
        );
        self::assertSame(
            $this->logBefore,
            self::logContents($this->logFile),
            'an undeliverable reset link must never be parked in storage/logs/app.log as a fallback — that fallback IS audit finding #1',
        );
    }

    private static function logContents(string $file): ?string
    {
        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    private function resetTokenCount(): int
    {
        return (int) Statement::query(
            DatabaseConnectionManager::instance()->connect('users'),
            'SELECT COUNT(*) FROM password_reset_tokens',
        )->fetchColumn();
    }

    /**
     * Put the log file back exactly as found — the dev case is the only test
     * that appends to it, and a suite must not leave a token behind in the
     * working tree (nor create the file when it did not exist).
     */
    private function restoreLog(): void
    {
        if (null === $this->logBefore) {
            if (is_file($this->logFile)) {
                @unlink($this->logFile);
            }

            return;
        }

        if (self::logContents($this->logFile) !== $this->logBefore) {
            file_put_contents($this->logFile, $this->logBefore);
        }
    }

    /**
     * A `CliOutput` that swallows every line.
     *
     * This was a `Symfony\...\NullOutput` until the migrator contract stopped
     * accepting a dev-only union arm (Boot 32): the kernel's runtime has no
     * Composer dependencies, so a test asserting a kernel contract should not
     * need one to satisfy it either.
     */
    private function quietOutput(): CliOutput
    {
        return new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
