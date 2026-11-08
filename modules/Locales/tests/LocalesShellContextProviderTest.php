<?php

declare(strict_types = 1);

namespace Modules\Locales\Tests;

use Modules\Core\Application\Services\ShellContextProviderRegistry;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Locales\Application\Services\LocaleService;
use Modules\Locales\Domain\Model\Locale;
use Modules\Locales\Infrastructure\Persistence\Migrations\LocalesDatabaseMigrator;
use Modules\Locales\Interfaces\HTTP\LocalesServices;
use Modules\Locales\Interfaces\HTTP\LocalesShellContextProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LocalesShellContextProviderTest — the locale answers of the shell port.
 *
 * Settings echoes the raw stored key; this provider answers the RESOLVED
 * default, so the suite pins the resolution here with explicit closures
 * (no database): a stored valid code resolves, garbage falls back to the
 * built-in default, and htmlLang is always the language part of whatever
 * defaultLocale answered. The live stored-value matrix belongs to the
 * Settings provider test, which owns a temp-DB harness for it.
 */
final class LocalesShellContextProviderTest extends TestCase
{
    private const string MODULE = 'Locales';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the locale answers ship with this module.');
        }

        LocalesServices::reset();
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even when setUp() skipped, and setUp()
        // skips in a kernel-only tree — so this must not touch the module
        // when Locales is not installed (Settings precedent).
        if (is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            LocalesServices::reset();
        }

        parent::tearDown();
    }

    #[Test]
    public function languagesAreTheAvailableSet(): void
    {
        $this->assertSame(
            array_keys(Locale::available()),
            (new LocalesShellContextProvider())->languages(),
            'The switcher union must be the available set itself, not a second list that drifts.',
        );
        $this->assertContains('sk_SK', (new LocalesShellContextProvider())->languages());
    }

    /**
     * The shell marks a switcher entry from this answer. The default locale
     * is a different fact, and substituting it here is what pinned every
     * theme's switcher to the default entry on an English page.
     */
    #[Test]
    public function activeLocaleFollowsTheRequestResolutionNotTheDefault(): void
    {
        $provider = new LocalesShellContextProvider();
        $default = $provider->defaultLocale();
        $codes = array_keys(Locale::available());
        $other = $codes[0] === $default ? $codes[1] : $codes[0];

        LocalesServices::locale()->set($other);

        $this->assertSame($other, $provider->activeLocale());
        $this->assertSame(
            $default,
            $provider->defaultLocale(),
            'resolving a request locale must not rewrite the stored default — the two facts are independent',
        );
    }

    #[Test]
    public function htmlLangIsTheLanguageOfTheAnsweredDefault(): void
    {
        $provider = new LocalesShellContextProvider();
        $default = $provider->defaultLocale();

        $this->assertIsString($default);
        $this->assertSame(explode('_', $default)[0], $provider->htmlLang());
    }

    #[Test]
    public function everythingButLocaleAbstains(): void
    {
        $provider = new LocalesShellContextProvider();

        $this->assertNull($provider->siteName());
        $this->assertNull($provider->theme());
        $this->assertSame([], $provider->navigation('header', '/'));
        $this->assertSame([], $provider->navigation('footer', '/blog'));
    }

    #[Test]
    public function theStoredLocaleVoteSortsAfterSettings(): void
    {
        $names = array_map(
            static fn (object $provider): string => $provider::class,
            ShellContextProviderRegistry::all(),
        );

        $settings = array_search('Modules\\Settings\\Interfaces\\HTTP\\SettingsShellContextProvider', $names, true);
        $locales = array_search(LocalesShellContextProvider::class, $names, true);

        $this->assertIsInt($settings, 'The Settings provider must be installed for the ordering pin.');
        $this->assertIsInt($locales);
        $this->assertLessThan($locales, $settings, 'Settings (-100) votes first; Locales is the backstop, never the override.');
    }

    /**
     * The copy answers the `home` group out of `locales.db`, PER LOCALE — the
     * defect this method was added for, pinned against the module's own schema
     * and seed.
     *
     * Measured 2026-10-17, before `copy()` existed: a `sk_SK` site rendered
     * `<h1>Minimal PHP 8.5 Starter Kit</h1>` while `home.hero_title` sat
     * translated in `locales.db` — because the only thing that knew the Slovak
     * row was this module, and the kernel rendering the page had no way to ask.
     */
    #[Test]
    public function copyAnswersTheHomeGroupInTheRequestedLocale(): void
    {
        $this->withSeededLocalesDatabase(function (LocalesShellContextProvider $provider): void {
            $sk = $provider->copy('home', 'sk_SK');
            $en = $provider->copy('home', 'en_US');

            $this->assertSame(
                'Minimálny PHP 8.5 starter kit',
                $sk['home.hero_title'] ?? null,
                'The seeded Slovak row must reach the port — this is the string a Slovak visitor was not getting.',
            );

            $this->assertSame(
                'Minimal PHP 8.5 Starter Kit',
                $en['home.hero_title'] ?? null,
                'The same key in en_US must answer English: the locale is an input, not decoration.',
            );

            $this->assertNotSame(
                $sk['home.hero_title'] ?? null,
                $en['home.hero_title'] ?? null,
                'Two locales answering one string means the locale was dropped on the way through.',
            );
        });
    }

    /**
     * Both locales carry the SAME keys. A key seeded for one and not the other
     * is a mixed-language page — `Menej vrstiev` above an English heading — and
     * it is invisible to every gate, because a missing row is not an error in
     * any of them: it is a fallback in the theme.
     */
    #[Test]
    public function bothLocalesAnswerTheSameKeySet(): void
    {
        $this->withSeededLocalesDatabase(function (LocalesShellContextProvider $provider): void {
            $sk = array_keys($provider->copy('home', 'sk_SK'));
            $en = array_keys($provider->copy('home', 'en_US'));

            $this->assertNotSame([], $sk, 'The seeded group must not be empty — an empty answer makes every assertion below vacuous.');

            $this->assertSame(
                [],
                array_values(array_diff($sk, $en)),
                'These keys are seeded for sk_SK and missing for en_US: the English page renders a Slovak string.',
            );

            $this->assertSame(
                [],
                array_values(array_diff($en, $sk)),
                'These keys are seeded for en_US and missing for sk_SK: the Slovak page renders an English string.',
            );
        });
    }

    /**
     * The pair that failed: the theme renders `home.*` keys, and the group has
     * to carry every one of them. The rows were there on 2026-10-17 and the page
     * was still English — nothing compared the two lists.
     */
    #[Test]
    public function theSeededGroupCoversEveryKeyTheInstalledThemeRenders(): void
    {
        if (!class_exists(\Modules\Themes\Jednotka\Theme\JednotkaTheme::class)) {
            $this->markTestSkipped('jednotka is not installed — this asserts the seam between the copy and the theme that renders it.');
        }

        $constant = (new \ReflectionClass(\Modules\Themes\Jednotka\Theme\JednotkaTheme::class))->getReflectionConstant('DEFAULT_COPY');
        $this->assertNotFalse($constant, 'The theme\'s default copy is what this compares against.');

        /** @var array<string, string> $rendered */
        $rendered = (array) $constant->getValue();

        $this->withSeededLocalesDatabase(function (LocalesShellContextProvider $provider) use ($rendered): void {
            foreach (['sk_SK', 'en_US'] as $locale) {
                $missing = array_values(array_diff(array_keys($rendered), array_keys($provider->copy('home', $locale))));

                $this->assertSame(
                    [],
                    $missing,
                    sprintf(
                        'The theme renders these keys and %s has no seeded row for them, so it falls back to its English default: %s',
                        $locale,
                        implode(', ', $missing),
                    ),
                );
            }
        });
    }

    /**
     * An unseeded locale, an empty locale and an unknown group all answer
     * nothing rather than the default locale's copy. Substituting silently is
     * how a site renders the wrong language with no way to tell.
     */
    #[Test]
    public function anUnknownLocaleGroupOrEmptyInputAnswersNothing(): void
    {
        $this->withSeededLocalesDatabase(function (LocalesShellContextProvider $provider): void {
            $this->assertSame([], $provider->copy('home', 'de_DE'), 'A locale with no rows must abstain, not fall back to sk_SK.');
            $this->assertSame([], $provider->copy('home', ''), 'An empty locale means the kernel did not resolve one; guessing is not the provider\'s call.');
            $this->assertSame([], $provider->copy('', 'sk_SK'), 'An empty group is not the home group.');
            $this->assertSame([], $provider->copy('nobody.owns.this', 'sk_SK'), 'An unanswered group answers an empty map — the theme\'s own floor covers it.');
        });
    }

    /**
     * Run assertions against a throwaway `locales.db` built by the module's own
     * migrator: same schema, same seed, no shared state. The Settings provider
     * test points `DB_CONFIG` at a temp file for the same reason — the working
     * tree's database is operator state, not a fixture, and a test that reads it
     * passes on this machine and fails on a fresh checkout.
     *
     * @param \Closure(LocalesShellContextProvider): void $run
     */
    private function withSeededLocalesDatabase(\Closure $run): void
    {
        $tmpDir = sys_get_temp_dir() . '/nativa-locales-copy-' . bin2hex(random_bytes(6));
        mkdir($tmpDir, 0o775, true);

        putenv('DB_LOCALES=' . $tmpDir . '/locales.db');
        DatabaseConnectionManager::resetInstance();
        LocalesServices::reset();

        try {
            $pdo = new \PDO('sqlite:' . $tmpDir . '/locales.db');
            (new LocalesDatabaseMigrator($pdo))->run(self::quietOutput());

            $run(new LocalesShellContextProvider());
        } finally {
            putenv('DB_LOCALES');
            DatabaseConnectionManager::resetInstance();
            LocalesServices::reset();

            foreach (glob($tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($tmpDir);
        }
    }

    private static function quietOutput(): CliOutput
    {
        return new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }

    /**
     * The resolution the provider delegates to: valid stored codes resolve,
     * anything else falls back to the built-in default — never null, never
     * a fatal, pinned here without a database.
     */
    #[Test]
    public function resolutionFallsBackToTheBuiltInDefault(): void
    {
        $this->assertSame('en_US', (new LocaleService(static fn (): ?string => 'en_US'))->default()->code());
        $this->assertSame('sk_SK', (new LocaleService(static fn (): ?string => 'xx_YY'))->default()->code());
        $this->assertSame('sk_SK', (new LocaleService(static fn (): ?string => null))->default()->code());
        $this->assertSame('sk_SK', (new LocaleService())->default()->code(), 'No resolver at all still answers Slovak.');
    }
}
