<?php

declare(strict_types = 1);

namespace Modules\Settings\Tests;

use Shell\ShellContextProviderRegistry;
use Shell\ShellNavItem;
use Modules\Layout\FrontendLayout;
use Modules\Layout\ThemeRegistry;
use Modules\Layout\ThemeResolver;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use Modules\Settings\Infrastructure\Persistence\Repositories\SettingsRepository;
use Modules\Settings\Infrastructure\Persistence\Statement;
use Modules\Settings\Interfaces\HTTP\SettingsServices;
use Modules\Settings\Interfaces\HTTP\SettingsShellContextProvider;
use Modules\Core\Interfaces\CLI\CliOutput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SettingsShellContextProviderTest (Boot 23) — the menu the shell port asks for
 * and, until this provider existed, nobody answered.
 *
 * `ShellContextProviderRegistry::navigation()` takes the FIRST non-empty list
 * for a slot, and an empty answer IS the port's "no menu" answer. So a port with
 * no menu owner renders exactly like a site with no menu configured: every
 * public page came out with a navbar carrying a brand and nothing else, no
 * error and no log line. That is the failure this file pins — not the styling of
 * a link, the existence of the answer.
 *
 * It also pins what a VISITOR may see: the seed puts `Admin` (→ `/mark`) in the
 * header, and an anonymous reader must not be pointed at the admin surface
 * (202 + login is what a dead end looks like in a navbar). Signed-in editors
 * keep it, nested admin items included.
 *
 * ## Why it lives here and not under `tests/`
 *
 * Settings is a module. A kernel-only tree has no provider to construct, so the
 * suite SKIPS rather than fails — the contract TaxonomyRegistryTest uses for
 * Blog. `tests/` is inside PHPStan's scope and `modules/` is not, so a test
 * naming an optional module's class cannot break the analyser of a tree without
 * that module.
 *
 * ## The database
 *
 * The provider reads `menu_items` through `SettingsServices`, whose connection
 * is `storage/data/config.db` unless `DB_CONFIG` says otherwise. The test points
 * that at a temp file and builds the schema with the Settings migrator itself —
 * NOT through `DatabaseMigrator`, which would run every module's migrator and
 * therefore touch the real databases of the working tree.
 */
final class SettingsShellContextProviderTest extends TestCase
{
    private const string MODULE = 'Settings';

    private string $tmpDir = '';

    private \PDO $pdo;

    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the provider ships with this module.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-settings-nav-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_CONFIG=' . $this->tmpDir . '/config.db');
        DatabaseConnectionManager::resetInstance();
        SettingsServices::reset();

        $this->pdo = new \PDO('sqlite:' . $this->tmpDir . '/config.db');

        // The migrator the kernel would run, against a database of our own —
        // same schema, same seeded menu, no shared state.
        (new SettingsDatabaseMigrator($this->pdo))->run($this->quietOutput());

        // The migrator keeps the raw handle for its DDL; the repositories and
        // the fixture SQL read and write through the routed connection.
        $this->connection = DatabaseConnectionManager::instance()->connect('config');
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even when setUp() skipped, and setUp()
        // skips in a kernel-only tree — so this must not touch the module
        // (or the tmp dir it never created) when Settings is not installed.
        if (is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            putenv('DB_CONFIG');
            DatabaseConnectionManager::resetInstance();
            SettingsServices::reset();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function headerSlotAnswersTheSeededMenuAsKernelOwnedItems(): void
    {
        $items = (new SettingsShellContextProvider())->navigation('header', '/');

        $this->assertSame(
            ['Home'],
            self::labels($items),
            'The header slot must answer the seeded menu minus what a guest may not see.',
        );

        // Kernel-owned values, not the module's DTO: the shell cannot carry a
        // module's type (see ShellNavItem's docblock).
        $this->assertInstanceOf(ShellNavItem::class, $items[0]);
        $this->assertSame('/', $items[0]->href);
        $this->assertTrue($items[0]->active, 'On `/` the Home item is the current one.');
    }

    #[Test]
    public function theItemUnderTheCurrentPathIsTheActiveOne(): void
    {
        $this->insertMenuItem('Articles', '/blog', 'header', 15);

        $byLabel = self::byLabel((new SettingsShellContextProvider())->navigation('header', '/blog/nativa-php-core'));

        $this->assertTrue($byLabel['Articles']->active, 'An article is under /blog, so Articles is current.');
        $this->assertFalse($byLabel['Home']->active, 'A prefix match must not light up the root item.');
    }

    #[Test]
    public function footerAnswersItsOwnLocationAndAnUnknownSlotAnswersNothing(): void
    {
        $provider = new SettingsShellContextProvider();

        $this->assertSame(
            [],
            self::labels($provider->navigation('footer', '/')),
            'The footer slot must answer only rows seeded for it; docs belongs to a module.',
        );

        $this->assertSame([], $provider->navigation('sidebar', '/'), 'A slot with no rows is the port\'s no-menu answer.');
    }

    /**
     * The seed used to ship `Privacy -> /privacy` and `Cookies -> /cookies`,
     * then `Articles -> /blog` and `Admin -> /mark`. A fresh export serves none
     * of those paths — `/` is the only route a menu can point at — so the
     * header and footer offered 404s on a brand-new site. This pins the rule
     * that replaced them: every seeded public href is a route this tree owns.
     */
    #[Test]
    public function theSeedAdvertisesNoRouteTheKernelDoesNotServe(): void
    {
        $this->assertSame(
            ['/'],
            $this->seededPublicHrefs(),
            'A seeded menu item points at a path this tree does not serve.',
        );
    }

    /**
     * The seed puts `Admin` in the header location. A reader who is not signed
     * in must not be handed a link into the admin surface.
     */
    #[Test]
    public function adminLinksAreHiddenFromAnonymousVisitors(): void
    {
        $this->insertMenuItem('Admin', '/mark', 'header', 20);

        $this->assertNotContains(
            'Admin',
            self::labels((new SettingsShellContextProvider())->navigation('header', '/')),
            'An anonymous visitor was handed the admin link.',
        );
    }

    /**
     * The recursion, which the seed's own header does not exercise: a child that
     * points at the admin surface is dropped while its parent stays.
     */
    #[Test]
    public function aNestedAdminItemIsDroppedWhileItsParentStays(): void
    {
        $this->insertMenuItem('Tools', '/tools', 'header', 30);
        $this->insertMenuItem('Moderation', '/mark/comments', 'header', 10, 'Tools');

        $guest = self::byLabel((new SettingsShellContextProvider())->navigation('header', '/'));

        $this->assertArrayHasKey('Tools', $guest, 'The parent does not point at the admin surface and must stay.');
        $this->assertSame([], $guest['Tools']->children, 'A nested admin link reached an anonymous visitor.');

        $this->signIn();

        try {
            $signedIn = self::byLabel((new SettingsShellContextProvider())->navigation('header', '/'));
            $this->assertSame(
                ['Moderation'],
                self::labels($signedIn['Tools']->children),
                'A signed-in editor must keep the nested admin link.',
            );
        } finally {
            $this->signOut();
        }
    }

    #[Test]
    public function anAdminLinkStaysForASignedInEditor(): void
    {
        $this->insertMenuItem('Admin', '/mark', 'header', 20);

        $this->assertNotContains('Admin', self::labels((new SettingsShellContextProvider())->navigation('header', '/')));

        $this->signIn();

        try {
            $this->assertContains(
                'Admin',
                self::labels((new SettingsShellContextProvider())->navigation('header', '/')),
                'The signed-in editor lost the admin link — the item exists for exactly this visitor.',
            );
        } finally {
            $this->signOut();
        }
    }

    #[Test]
    public function theProviderAnswersTheMenuAndTheStoredAdminChoices(): void
    {
        $provider = new SettingsShellContextProvider();

        // The site name is the fourth stored admin choice. Without this answer
        // the port had none, and every public surface shipped the kernel's
        // `Nativa PHP Core` literal — on a site the admin had renamed.
        $this->assertSame(
            FrontendLayout::DEFAULT_SITE_NAME,
            $provider->siteName(),
            'The migrator seeds site_name; the provider must echo it, not abstain.',
        );
        // Narrowed, not deleted: the admin theme vote REQUIRES theme() to answer
        // a valid stored value (fix-settings-theme-switch). The seed stores
        // `kernel`, so the seeded answer is the kernel theme — not null.
        $this->assertSame(
            ThemeResolver::KERNEL_THEME,
            $provider->theme(),
            'The migrator seeds theme=kernel; the provider must echo the stored value.',
        );
        // Narrowed like theme() above: the admin locale vote REQUIRES
        // defaultLocale()/htmlLang() to echo a well-formed stored value.
        // The migrator seeds locale=sk_SK, so the seeded answers are Slovak.
        $this->assertSame(
            'sk_SK',
            $provider->defaultLocale(),
            'The migrator seeds locale=sk_SK; the provider must echo the stored value.',
        );
        $this->assertSame('sk', $provider->htmlLang(), 'The language part of the voted default, not the kernel fallback.');
        $this->assertSame([], $provider->languages());
    }

    #[Test]
    public function aStoredSiteNameIsAnswered(): void
    {
        $this->storeSiteName('responsive.sk');

        $this->assertSame('responsive.sk', (new SettingsShellContextProvider())->siteName());
    }

    /**
     * The admin field is free text. A blank name must abstain so the caller's
     * fallback still titles the document — an empty `<title>` is worse than the
     * kernel's default.
     */
    #[Test]
    public function aBlankSiteNameAbstains(): void
    {
        $this->storeSiteName("   ");

        $this->assertNull((new SettingsShellContextProvider())->siteName());
    }

    #[Test]
    public function aStoredSiteDescriptionIsAnswered(): void
    {
        $this->storeSiteDescription('A small site with a readable runtime.');

        $this->assertSame(
            'A small site with a readable runtime.',
            (new SettingsShellContextProvider())->siteDescription(),
        );
    }

    #[Test]
    public function aBlankSiteDescriptionAbstains(): void
    {
        $this->storeSiteDescription("   ");

        $this->assertNull((new SettingsShellContextProvider())->siteDescription());
    }

    #[Test]
    public function aMissingSiteDescriptionAbstains(): void
    {
        (new SettingsRepository($this->connection))->delete('site_description');
        SettingsServices::reset();

        $this->assertNull((new SettingsShellContextProvider())->siteDescription());
    }

    #[Test]
    public function theLayoutUsesTheStoredDescriptionWhenTheActionDoesNotSupplyOne(): void
    {
        $this->storeSiteDescription('Stored shell description');
        ShellContextProviderRegistry::reset();

        $html = FrontendLayout::render('T', 'C', theme: ThemeResolver::KERNEL_THEME);

        $this->assertStringContainsString(
            '<meta name="description" content="Stored shell description">',
            $html,
        );
    }

    #[Test]
    public function anExplicitDescriptionWinsOverTheStoredOne(): void
    {
        $this->storeSiteDescription('Stored shell description');
        ShellContextProviderRegistry::reset();

        $html = FrontendLayout::render(
            'T',
            'C',
            description: 'Page description',
            theme: ThemeResolver::KERNEL_THEME,
        );

        $this->assertStringContainsString('<meta name="description" content="Page description">', $html);
        $this->assertStringNotContainsString('Stored shell description', $html);
    }

    #[Test]
    public function aBlankStoredDescriptionFallsBackToTheKernelLiteral(): void
    {
        $this->storeSiteDescription("   ");
        ShellContextProviderRegistry::reset();

        $html = FrontendLayout::render('T', 'C', theme: ThemeResolver::KERNEL_THEME);

        $this->assertStringContainsString(
            '<meta name="description" content="' . FrontendLayout::DEFAULT_DESCRIPTION . '">',
            $html,
        );
    }

    #[Test]
    public function aStoredLocaleIsAnswered(): void
    {
        $this->storeLocale('en_US');

        $provider = new SettingsShellContextProvider();

        $this->assertSame('en_US', $provider->defaultLocale());
        $this->assertSame('en', $provider->htmlLang());
    }

    /**
     * Settings votes on the stored DEFAULT only. If it answered the request
     * locale it would outrank the module that actually resolves one, which
     * is how the switcher ended up pinned to the default entry.
     */
    #[Test]
    public function theRequestLocaleIsNotSettingsToAnswer(): void
    {
        $this->assertNull(
            (new SettingsShellContextProvider())->activeLocale(),
            'The resolved request locale belongs to the module that owns locales; Settings answers the stored default only.',
        );
    }

    #[Test]
    public function aStoredGarbageLocaleAbstains(): void
    {
        $this->storeLocale('not-a-locale');

        $provider = new SettingsShellContextProvider();

        $this->assertNull($provider->defaultLocale(), 'Free-text admin field — only ll_CC echoes, the rest keeps the kernel degradation.');
        $this->assertNull($provider->htmlLang());
    }

    #[Test]
    public function aMissingLocaleKeyAbstains(): void
    {
        Statement::prepare($this->connection, 'DELETE FROM settings WHERE setting_key = :key')->execute([':key' => 'locale']);
        SettingsServices::reset();

        $provider = new SettingsShellContextProvider();

        $this->assertNull($provider->defaultLocale());
        $this->assertNull($provider->htmlLang());
    }

    #[Test]
    public function aStoredRegistryThemeIsAnswered(): void
    {
        $names = array_keys(ThemeRegistry::all());

        if ([] === $names) {
            $this->markTestSkipped('No module-carried theme installed — nothing resolvable beyond the whitelist.');
        }

        $this->storeTheme($names[0]);

        $this->assertSame($names[0], (new SettingsShellContextProvider())->theme());
    }

    #[Test]
    public function aStoredGarbageThemeAbstains(): void
    {
        $this->storeTheme('no-such-theme');

        $this->assertNull(
            (new SettingsShellContextProvider())->theme(),
            'A stale stored name (theme module removed) must keep the fixture/kernel'
            . ' degradation instead of pre-empting it with a name nothing can render.',
        );
    }

    #[Test]
    public function aMissingThemeKeyAbstains(): void
    {
        Statement::prepare($this->connection, 'DELETE FROM settings WHERE setting_key = :key')->execute([':key' => 'theme']);
        SettingsServices::reset();

        $this->assertNull((new SettingsShellContextProvider())->theme());
    }

    /**
     * The theme-settings half of the port: only the ACTIVE theme's rows.
     *
     * The kernel theme is the active one here on purpose — this module's test
     * must not depend on another module's theme being installed, and the rule
     * under test is the prefix filter, not a particular theme. A row belonging
     * to another theme is exactly the failure this pins: it would hand Boson
     * the meatball Mono was configured with.
     */
    #[Test]
    public function themeSettingsAnswersOnlyTheActiveThemesRows(): void
    {
        $this->storeTheme(ThemeResolver::KERNEL_THEME);

        $repository = new SettingsRepository($this->connection);
        $repository->set('theme.kernel.nav_toggle_icon', 'meatball', 'design', false);
        $repository->set('theme.other.nav_toggle_icon', 'burger', 'design', false);
        SettingsServices::reset();

        $this->assertSame(
            ['nav_toggle_icon' => 'meatball'],
            (new SettingsShellContextProvider())->themeSettings(),
            'The storage prefix carries the theme name; another theme\'s row is not an answer.',
        );
    }

    #[Test]
    public function themeSettingsAreEmptyUntilARowIsStored(): void
    {
        $this->storeTheme(ThemeResolver::KERNEL_THEME);

        $this->assertSame(
            [],
            (new SettingsShellContextProvider())->themeSettings(),
            'A fresh install stores nothing; defaults belong to the theme schema, not to this port.',
        );
    }

    #[Test]
    public function resolvePathFollowsTheStoredTheme(): void
    {
        $stored = ThemeResolver::KERNEL_THEME;
        $names = array_keys(ThemeRegistry::all());

        if ([] !== $names) {
            $stored = $names[0];
        }

        $this->storeTheme($stored);
        ShellContextProviderRegistry::reset();

        $this->assertSame(
            $stored,
            ThemeResolver::resolvePath('/'),
            'The mechanism proof: stored theme → registry vote → resolved theme.',
        );
    }

    #[Test]
    public function theStoredThemeVoteSortsBeforeFixtureVotes(): void
    {
        ShellContextProviderRegistry::reset();

        $settingsIndex = null;

        foreach (ShellContextProviderRegistry::all() as $index => $provider) {
            if ($provider instanceof SettingsShellContextProvider) {
                $settingsIndex = $index;

                continue;
            }

            if (null !== $provider->theme()) {
                $this->assertNotNull($settingsIndex, 'A theme vote sorts before the Settings provider.');
                $this->assertLessThan(
                    $index,
                    $settingsIndex,
                    sprintf('The %s vote would beat the admin choice on discovery order.', $provider::class),
                );
            }
        }

        $this->assertNotNull($settingsIndex, 'The Settings provider must be discovered.');
    }

    /**
     * Every href the Settings migrator seeds into the public slots, sorted —
     * the navigation a visitor can actually see.
     *
     * @return list<string>
     */
    private function seededPublicHrefs(): array
    {
        /** @var list<string> $hrefs */
        $hrefs = Statement::query($this->connection,
            "SELECT href FROM menu_items WHERE location IN ('header', 'footer')",
        )->fetchAll(\PDO::FETCH_COLUMN);

        sort($hrefs);

        return $hrefs;
    }

    /**
     * @param list<ShellNavItem> $items
     *
     * @return list<string>
     */
    private static function labels(array $items): array
    {
        return array_map(static fn (ShellNavItem $item): string => $item->label, $items);
    }

    /**
     * @param list<ShellNavItem> $items
     *
     * @return array<string, ShellNavItem>
     */
    private static function byLabel(array $items): array
    {
        $byLabel = [];

        foreach ($items as $item) {
            $byLabel[$item->label] = $item;
        }

        return $byLabel;
    }

    /**
     * The session the app itself would have: started, populated with the key the
     * kernel's shell chrome reads, and announced by cookie — which is what the
     * provider's own guard requires before it looks at `$_SESSION` at all.
     */
    private function signIn(): void
    {
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        $_SESSION['auth'] = ['user_id' => 'test-editor'];
        $_COOKIE[session_name()] = session_id();
    }

    private function signOut(): void
    {
        $_SESSION = [];
        session_destroy();
        unset($_COOKIE[session_name()]);
    }

    /**
     * Write the `theme` key the way `saveSite()` does, then drop the cached
     * service so the provider reads the new row, not the old snapshot.
     */
    private function storeTheme(string $value): void
    {
        (new SettingsRepository($this->connection))->set('theme', $value, 'design', false);
        SettingsServices::reset();
    }

    /**
     * Write the `site_name` key the way `saveSite()` does, then drop the cached
     * service so the provider reads the new row, not the old snapshot.
     */
    private function storeSiteName(string $value): void
    {
        (new SettingsRepository($this->connection))->set('site_name', $value, 'general', false);
        SettingsServices::reset();
    }

    /**
     * Write the `site_description` key the way `saveSite()` does, then drop the
     * cached service so the provider reads the new row.
     */
    private function storeSiteDescription(string $value): void
    {
        (new SettingsRepository($this->connection))->set('site_description', $value, 'general', false);
        SettingsServices::reset();
    }

    /**
     * Write the `locale` key the way `saveSite()` does, then drop the cached
     * service so the provider reads the new row, not the old snapshot.
     */
    private function storeLocale(string $value): void
    {
        (new SettingsRepository($this->connection))->set('locale', $value, 'general', false);
        SettingsServices::reset();
    }

    private function insertMenuItem(string $label, string $href, string $location, int $sort, ?string $parentLabel = null): void
    {
        $parent = null;

        if (null !== $parentLabel) {
            $stmt = Statement::prepare($this->connection, 'SELECT id FROM menu_items WHERE label = :label AND location = :loc');
            $stmt->execute([':label' => $parentLabel, ':loc' => $location]);
            $parent = $stmt->fetchColumn();
            $this->assertIsString($parent, 'The parent row must exist before its child is inserted.');
        }

        $stmt = Statement::prepare($this->connection,
            'INSERT INTO menu_items (id, label, href, sort_order, location, icon, parent_item_id, is_active, created_at, updated_at)
             VALUES (:id, :label, :href, :sort, :loc, :icon, :parent, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        );
        $stmt->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':label' => $label,
            ':href' => $href,
            ':sort' => $sort,
            ':loc' => $location,
            ':icon' => '',
            ':parent' => $parent,
        ]);
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
