<?php

declare(strict_types = 1);

namespace Modules\Users\Tests;

use Modules\Core\Interfaces\HTTP\Router;
use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Domain\Permissions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AdminRoutePolicyCoverageTest — every `/mark` route this tree registers must
 * resolve a permission, and nothing outside `/mark` may.
 *
 * The policy fails CLOSED by design: `requiredPermission()` answers null for a
 * path no row classifies, and `AuthMiddleware` treats null as DENY. That is
 * the right default for a route an attacker invents and the wrong one for a
 * route WE ship — a legitimate administrator would meet a 403 with no policy
 * row to point at, and nothing in the suite would notice, because each
 * individual route test signs in with the `admin` role that bypasses the
 * table entirely.
 *
 * So this test walks the router rather than the table: one assertion per
 * registered route, taken from the same scan the application dispatches with.
 * A new `/mark/whatever` that nobody classified fails here, with the path in
 * the message, before it fails a human being in production.
 *
 * The inverse half matters too: a permission row that accidentally matches a
 * public path would put the blog behind the admin gate. The enumerated public
 * set below is the one the kernel-only boot test already pins.
 *
 * Like every module test it drives the INSTALLED slice and skips when the
 * module is not installed.
 */
final class AdminRoutePolicyCoverageTest extends TestCase
{
    /** The public surface that must stay public (the kernel-only boot set). */
    private const array PUBLIC_PATHS = [
        '/',
        '/blog',
        '/docs',
        '/login',
        '/register',
        '/sitemap.xml',
        '/robots.txt',
        '/markish',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Users'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }
    }

    /**
     * The scan must find routes, or the loop below asserts nothing.
     *
     * The second assertion is the one that cannot go quiet: a walker that
     * stopped after Core would still return routes (`/`, `/docs`,
     * `/sitemap.xml`) and every admin assertion would then pass over an empty
     * set. This tree registers 77 `/mark` routes; 50 is the floor below which
     * the scan is traversing only part of it.
     */
    #[Test]
    public function theRouterReportsTheRoutesThisTreeServes(): void
    {
        $paths = $this->registeredPaths();

        self::assertNotEmpty(
            $paths,
            'the router registered no routes at all — the walk over src/Modules stopped working, '
            . 'and every assertion below would then pass vacuously',
        );

        $admin = array_filter($paths, static fn (string $path): bool => AdminRoutePolicy::isAdminPath($path));

        self::assertGreaterThan(
            50,
            \count($admin),
            'the router found only ' . \count($admin) . ' admin routes where this tree registers 77 — '
            . 'the walk is covering src/Modules/Core alone, so the coverage loop below would assert '
            . 'nothing',
        );
    }

    #[Test]
    public function everyAdminRouteResolvesAPermission(): void
    {
        $uncovered = [];

        foreach ($this->registeredPaths() as $path) {
            if (!AdminRoutePolicy::isAdminPath($path)) {
                continue;
            }

            if (null === AdminRoutePolicy::requiredPermission($path)) {
                $uncovered[] = $path;
            }
        }

        self::assertSame(
            [],
            $uncovered,
            "these registered /mark routes resolve no permission row, so a legitimate admin "
            . "would be refused with 'no permission policy covers this path'. Add a TABLE row in "
            . "modules/Users/Domain/AdminRoutePolicy.php:\n" . implode("\n", $uncovered) . "\n",
        );
    }

    #[Test]
    public function noPublicPathIsCaughtByThePolicy(): void
    {
        foreach (self::PUBLIC_PATHS as $path) {
            self::assertFalse(
                AdminRoutePolicy::isAdminPath($path),
                $path . ' is registered as public but AdminRoutePolicy calls it an admin path — '
                . 'the blog (or the login page) would be put behind the admin gate',
            );

            self::assertNull(
                AdminRoutePolicy::requiredPermission($path),
                $path . ' resolves a permission although it is a public path',
            );
        }
    }

    /**
     * The longest-prefix table must actually answer for its own prefixes — a
     * row that stopped matching is a gate that silently denies every path
     * under it.
     */
    #[Test]
    public function everyTablePrefixStillResolvesItsOwnPermission(): void
    {
        $prefixes = AdminRoutePolicy::prefixes();

        self::assertNotEmpty($prefixes, 'AdminRoutePolicy classifies nothing — the table is empty');

        foreach ($prefixes as $prefix) {
            self::assertNotNull(
                AdminRoutePolicy::requiredPermission($prefix),
                'the row for ' . $prefix . ' no longer resolves itself — the prefix matcher drifted '
                . 'from the table, and every path under that prefix now fails closed',
            );
        }
    }

    /**
     * One owner for the vocabulary: a permission name spelled out of
     * `Permissions` is a grant nothing can ever hold.
     */
    #[Test]
    public function thePolicyUsesOnlyNamesPermissionsOwns(): void
    {
        $used = array_values(array_unique(array_map(
            static fn (string $prefix): ?string => AdminRoutePolicy::requiredPermission($prefix),
            AdminRoutePolicy::prefixes(),
        )));

        $unknown = array_diff($used, Permissions::all());

        self::assertSame(
            [],
            array_values($unknown),
            'AdminRoutePolicy uses permission names Permissions does not own — a role can never '
            . 'be granted them, so the matching routes are unreachable',
        );
    }

    /**
     * Every path the installed tree registers, deduplicated, in registration
     * order — the same `toEntries()` payload the discovery cache stores, so
     * this walks what dispatch walks.
     *
     * @return list<string>
     */
    private function registeredPaths(): array
    {
        $entries = (new Router([APP_BASE_PATH . '/src/Modules']))->toEntries();

        $paths = array_values(array_unique(array_map(
            static fn (array $entry): string => (string) $entry['path'],
            $entries,
        )));

        sort($paths);

        return $paths;
    }
}
