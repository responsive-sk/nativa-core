<?php

declare(strict_types = 1);

namespace Modules\Auth\Tests;

use Modules\Auth\Application\Services\AuthProviderRegistry;
use Modules\Auth\Domain\Contracts\AuthProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AuthProviderRegistryTest — the discovery contracts that survive ANY tree.
 *
 * This lives with the Auth module because the registry does: a kernel-only
 * checkout skips it in `setUp()`, and the provider the workstation installs
 * (Users) is asserted through the PORT, never by name. The kernel-only run
 * asserts the other half of the same contract: no Auth class, no admin gate,
 * and `/mark` answers 404 through `AdminUnavailableMiddleware`.
 *
 * Two halves, and the second one needs files:
 *
 *  1. **Real-tree contracts.** Every entry implements the port, `first()` is
 *     either null or an instance of it, resolving twice answers the same thing
 *     (the per-process cache), and `reset()` re-resolves.
 *  2. **Fixture contracts.** The rules a real tree cannot demonstrate on
 *     demand: two providers at the SAME priority leave one winner and LOG the
 *     other (the shared `PreferenceVote` rule), and a provider whose
 *     constructor requires an argument is refused rather than instantiated
 *     with a wrong-lifetime dependency.
 *
 * The fixtures are written into the INSTALLED tree (`src/Modules/…`, which is
 * gitignored operator state, the same place `module:add` puts a module) because
 * the registry's glob is fixed to that directory: a fixture that lives anywhere
 * else would prove nothing about the real scan. They are removed in
 * `tearDown()`, whether the assertions passed or not, and `reset()` is called
 * on both ends so a cached list can never leak between tests.
 */
final class AuthProviderRegistryTest extends TestCase
{
    /** Installed-tree fixture modules this test owns. */
    private const array FIXTURE_MODULES = ['RegFixtureTie', 'RegFixtureTieTwo', 'RegFixtureDep'];

    /** The winner fixture's priority — negative, so it outranks a real module's provider (0). */
    private const int WINNING_PRIORITY = -1;

    private ?string $logFile = null;

    private string|false $logBefore = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(AuthProviderRegistry::class)) {
            $this->markTestSkipped('Auth is not installed — the registry ships with this module.');
        }

        AuthProviderRegistry::reset();
    }

    protected function tearDown(): void
    {
        self::removeFixtures();

        if (null !== $this->logFile) {
            @unlink($this->logFile);
            $this->logFile = null;
        }

        if (false !== $this->logBefore) {
            // Routing `error_log()` to a probe file is the only way to assert
            // what the registry wrote; the ini value is restored here.
            // @mago-expect lint:no-ini-set
            ini_set('error_log', (string) $this->logBefore);
            $this->logBefore = false;
        }

        if (class_exists(AuthProviderRegistry::class)) {
            AuthProviderRegistry::reset();
        }

        parent::tearDown();
    }

    /**
     * Whatever this tree installs, discovery answers through the port — and the
     * kernel-only case (no module ships a provider) is a NULL, never an
     * exception and never a placeholder: absence removes the middleware, not
     * the request.
     */
    #[Test]
    public function everyDiscoveredProviderImplementsThePortAndAbsenceIsNull(): void
    {
        $providers = AuthProviderRegistry::all();

        foreach ($providers as $provider) {
            self::assertInstanceOf(
                AuthProvider::class,
                $provider,
                'discovery returned something that is not an AuthProvider — the port is the only contract the composition root may rely on',
            );
        }

        $first = AuthProviderRegistry::first();

        if ([] === $providers) {
            self::assertNull(
                $first,
                'a tree with no provider must answer null, so the composition root wires nothing and /mark stays a 404 rather than an unguarded 200',
            );

            self::markTestSkipped('no module ships an #[AsAuthProvider] here (kernel-only checkout).');
        }

        self::assertInstanceOf(AuthProvider::class, $first, 'first() must be one of the discovered providers');
        self::assertContains($first, $providers, 'first() must be an entry of all(), not a second discovery path');
    }

    /**
     * Resolution is cached per process — a request must not rescan the tree to
     * ask the same question twice — and `reset()` is the seam the harness uses
     * between databases, so it must actually re-resolve.
     */
    #[Test]
    public function resolutionIsCachedPerProcessAndResetReResolves(): void
    {
        $first = AuthProviderRegistry::first();
        $again = AuthProviderRegistry::first();

        self::assertSame($first, $again, 'two resolutions in one process must answer the same instance');

        AuthProviderRegistry::reset();

        $afterReset = AuthProviderRegistry::first();

        if (null === $first) {
            self::assertNull($afterReset);

            return;
        }

        self::assertInstanceOf(AuthProvider::class, $afterReset);
        self::assertNotSame($first, $afterReset, 'reset() must clear the cache — the harness swaps DB_* env and needs a fresh provider');
    }

    /**
     * The contention rule, exercised for real: two providers at the winning
     * priority leave ONE winner and name the other in the log. A silent coin
     * flip between two modules claiming the auth surface is the failure this
     * pins.
     */
    #[Test]
    public function tiedProvidersKeepOneWinnerAndLogTheOther(): void
    {
        $this->captureErrorLog();

        $tie = 'Modules\RegFixtureTie\Interfaces\HTTP\TieAuthProvider';
        $tieTwo = 'Modules\RegFixtureTieTwo\Interfaces\HTTP\TieTwoAuthProvider';

        self::writeFixtureProvider('RegFixtureTie', 'TieAuthProvider', self::WINNING_PRIORITY);
        self::writeFixtureProvider('RegFixtureTieTwo', 'TieTwoAuthProvider', self::WINNING_PRIORITY);
        self::writeFixtureProvider('RegFixtureDep', 'DepAuthProvider', self::WINNING_PRIORITY - 1, withDependency: true);

        AuthProviderRegistry::reset();

        $winner = AuthProviderRegistry::first();
        $log = (string) @file_get_contents((string) $this->logFile);

        self::assertNotNull($winner, 'the fixture providers must be discovered — the scan reads the installed tree');
        self::assertContains(
            $winner::class,
            [$tie, $tieTwo],
            'the two priority -1 fixtures must be the candidates: they outrank the installed module provider (0) and the refused fixture is not one at all',
        );

        $loser = $winner::class === $tie ? $tieTwo : $tie;

        self::assertStringContainsString(
            sprintf('[auth] provider %s ignored (same priority as %s)', $loser, $winner::class),
            $log,
            'a provider that lost a same-priority tie must be named in the log, never dropped quietly (log was: ' . $log . ')',
        );
        self::assertStringNotContainsString(
            'Modules\RegFixtureDep\Interfaces\HTTP\DepAuthProvider',
            $log,
            'a provider that was REFUSED is not a contender — logging it as a tie would hide the refusal',
        );
    }

    /**
     * A provider whose constructor requires an argument is refused, not
     * constructed. Discovery instantiates while it scans, and a dependency
     * captured at that moment outlives the database it was taken from — the
     * harness swaps `DB_*` between cases, so this is a correctness rule, not
     * a style one.
     */
    #[Test]
    public function aProviderRequiringAConstructorArgumentIsRefused(): void
    {
        self::writeFixtureProvider('RegFixtureDep', 'DepAuthProvider', self::WINNING_PRIORITY, withDependency: true);

        AuthProviderRegistry::reset();

        $classes = array_map(
            static fn (AuthProvider $provider): string => $provider::class,
            AuthProviderRegistry::all(),
        );

        self::assertNotContains(
            'Modules\\RegFixtureDep\\Interfaces\\HTTP\\DepAuthProvider',
            $classes,
            'a provider with required constructor parameters must be skipped — instantiating it would capture a dependency from the wrong moment',
        );
    }

    /**
     * The text gate is a pre-filter, not a validator: a `*AuthProvider.php` file
     * with no `#[AsAuthProvider]` attribute is never loaded as one, so an
     * unmarked class cannot join the pipeline by being named helpfully.
     */
    #[Test]
    public function anUnmarkedFileIsNotAProvider(): void
    {
        self::writeFixtureFile('RegFixtureTie', 'Interfaces/HTTP/UnmarkedAuthProvider.php', <<<'SOURCE'
            <?php

            declare(strict_types = 1);

            namespace Modules\RegFixtureTie\Interfaces\HTTP;

            use Modules\Auth\Domain\AuthIdentity;
            use Modules\Auth\Domain\Contracts\AuthProvider;
            use Modules\Core\Infrastructure\Http\Request;

            final readonly class UnmarkedAuthProvider implements AuthProvider
            {
                public function identity(Request $request): ?AuthIdentity
                {
                    return null;
                }

                public function isProtectedPath(string $path): bool
                {
                    return false;
                }

                public function requiredPermission(string $path): ?string
                {
                    return null;
                }

                /** @return list<string> */
                public function guestOnlyPaths(): array
                {
                    return [];
                }

                /** @param array<string, mixed> $context */
                public function noteEvent(string $event, array $context): void
                {
                }
            }
            SOURCE);

        AuthProviderRegistry::reset();

        $classes = array_map(
            static fn (AuthProvider $provider): string => $provider::class,
            AuthProviderRegistry::all(),
        );

        self::assertNotContains(
            'Modules\\RegFixtureTie\\Interfaces\\HTTP\\UnmarkedAuthProvider',
            $classes,
            'the attribute is the label; a file named AuthProvider without it is not one',
        );
    }

    /**
     * Redirect `error_log()` into a file this test owns, so the contention
     * assertion can read what the registry printed instead of scraping stderr.
     */
    private function captureErrorLog(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'auth-registry-log-');

        if (false === $file) {
            self::fail('could not create a temporary error log for the contention assertion');
        }

        $this->logFile = $file;
        $this->logBefore = ini_get('error_log');

        // The probe needs `error_log()` itself, so the destination is an ini
        // value; `tearDown()` puts the previous one back.
        // @mago-expect lint:no-ini-set
        ini_set('error_log', $file);
    }

    private static function writeFixtureProvider(
        string $module,
        string $class,
        int $priority,
        bool $withDependency = false,
    ): void {
        $constructor = $withDependency
            ? "\n    public function __construct(private string \$required)\n    {\n    }\n"
            : '';

        self::writeFixtureFile($module, 'Interfaces/HTTP/' . $class . '.php', sprintf(<<<'SOURCE'
            <?php

            declare(strict_types = 1);

            namespace Modules\%s\Interfaces\HTTP;

            use Modules\Auth\Domain\AuthIdentity;
            use Modules\Auth\Domain\Contracts\AuthProvider;
            use Modules\Auth\Infrastructure\Attributes\AsAuthProvider;
            use Modules\Core\Infrastructure\Http\Request;

            #[AsAuthProvider(priority: %d)]
            final readonly class %s implements AuthProvider
            {%s
                public function identity(Request $request): ?AuthIdentity
                {
                    return null;
                }

                public function isProtectedPath(string $path): bool
                {
                    return false;
                }

                public function requiredPermission(string $path): ?string
                {
                    return null;
                }

                /** @return list<string> */
                public function guestOnlyPaths(): array
                {
                    return [];
                }

                /** @param array<string, mixed> $context */
                public function noteEvent(string $event, array $context): void
                {
                }
            }
            SOURCE, $module, $priority, $class, $constructor));
    }

    private static function writeFixtureFile(string $module, string $relative, string $source): void
    {
        $path = APP_BASE_PATH . '/src/Modules/' . $module . '/' . $relative;
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            self::fail('could not create the fixture directory ' . $dir);
        }

        file_put_contents($path, $source);
    }

    /** Delete every fixture this test could have created, files first. */
    private static function removeFixtures(): void
    {
        foreach (self::FIXTURE_MODULES as $module) {
            $dir = APP_BASE_PATH . '/src/Modules/' . $module;

            if (!is_dir($dir)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }

            @rmdir($dir);
        }
    }
}
