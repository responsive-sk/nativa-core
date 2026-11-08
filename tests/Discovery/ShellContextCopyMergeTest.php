<?php

declare(strict_types = 1);

namespace Tests\Discovery;

use Modules\Core\Application\Services\ShellContextProviderRegistry;
use Modules\Tooling\ModuleInstaller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ShellContextCopyMergeTest — the `copy` merge rule, pinned where it can fail.
 *
 * `copy()` is the one question in the shell port whose answer is a MAP, so it
 * carries the one merge rule no other question has: the UNION of every
 * provider's answer, compared per key, with the first provider to hold a key
 * winning it. That is what lets a site module translate three strings without
 * re-answering the twenty its locale module already carries.
 *
 * The failure it guards against is silent, which is why it is a test and not a
 * sentence in a docblock. Measured 2026-10-17: a `sk_SK` site rendered an
 * ENGLISH homepage. The 25 `home.*` rows were already in `locales.db` in both
 * locales, `HomepageAction` passed `['title' => $siteName]` and nothing else,
 * and `jednotka` rendered its own English defaults — every gate green, no log
 * line, no error. The kernel could not ask for copy it did not own.
 *
 * ## The fixture
 *
 * Two throwaway providers in an installed slice, created and deleted around the
 * assertions exactly the way `ModuleLifecycleTest` builds its holder fixture.
 * They answer SYNTHETIC keys (`home.merge_probe_*`) on purpose: a real key would
 * be answered by the installed Locales provider as well, and the assertions
 * would then be describing the locale's rows instead of the merge.
 *
 * Kernel-only, like everything under `tests/`: the fixtures name no module's
 * class, and the last case holds on a tree with NOTHING installed.
 */
final class ShellContextCopyMergeTest extends TestCase
{
    private const string MODULE = 'TempCopy';

    private const string GROUP = 'home';

    protected function tearDown(): void
    {
        self::deleteFixture();
        ShellContextProviderRegistry::reset();

        parent::tearDown();
    }

    /**
     * Both providers answer, and the union carries the keys only one of them
     * named — three strings from one and two from the other, not "the first
     * provider's map or nothing".
     */
    #[Test]
    public function theAnswerIsAUnionPerKeyAndTheFirstHolderOfAKeyWinsIt(): void
    {
        try {
            self::createFixture();
            ShellContextProviderRegistry::reset();

            $copy = ShellContextProviderRegistry::copy(self::GROUP, 'sk_SK');
        } finally {
            self::deleteFixture();
            ShellContextProviderRegistry::reset();
        }

        $this->assertSame(
            'first',
            $copy['home.merge_probe_a'] ?? null,
            'A key only the first provider holds must survive the merge.',
        );

        $this->assertSame(
            'first-b',
            $copy['home.merge_probe_b'] ?? null,
            'Both providers hold this key — first in priority order wins, it is not overwritten by the later one.',
        );

        $this->assertSame(
            'second-c',
            $copy['home.merge_probe_c'] ?? null,
            'A key only the LATER provider holds must still be answered: the rule is per key, not per provider.',
        );
    }

    /**
     * A provider that holds a key but stores an empty string has not answered
     * it — otherwise a half-filled translation table would blank out a string
     * the provider behind it could have supplied.
     */
    #[Test]
    public function ablankValueDoesNotMaskTheProviderBehindIt(): void
    {
        try {
            self::createFixture();
            ShellContextProviderRegistry::reset();

            $copy = ShellContextProviderRegistry::copy(self::GROUP, 'sk_SK');
        } finally {
            self::deleteFixture();
            ShellContextProviderRegistry::reset();
        }

        $this->assertSame(
            'real',
            $copy['home.merge_probe_d'] ?? null,
            'The first provider stored an empty string for this key; the later provider\'s real value must win it.',
        );
    }

    /**
     * The locale is the provider's input, so the kernel's resolved locale has to
     * arrive there — a port that dropped it would answer the default locale's
     * copy for every request and look correct on any single-locale site.
     */
    #[Test]
    public function theRequestedLocaleReachesTheProvider(): void
    {
        try {
            self::createFixture();
            ShellContextProviderRegistry::reset();

            $copy = ShellContextProviderRegistry::copy(self::GROUP, 'sk_SK');
        } finally {
            self::deleteFixture();
            ShellContextProviderRegistry::reset();
        }

        $this->assertSame(
            'sk_SK',
            $copy['home.merge_probe_locale'] ?? null,
            'The provider echoed back a locale other than the one it was asked for.',
        );
    }

    /**
     * A group nobody answers is `[]`, not an error and not a skip — the port's
     * own "absence is a first-class answer" contract, which is what keeps a
     * theme's own floor working on a tree with no translation module at all.
     */
    #[Test]
    public function agroupNobodyAnswersIsEmptyRatherThanAnError(): void
    {
        $this->assertSame(
            [],
            ShellContextProviderRegistry::copy('group.nobody.owns', 'sk_SK'),
            'An unanswered group must answer an empty map, so the renderer falls back to its own copy.',
        );
    }

    private static function createFixture(): void
    {
        $dir = APP_BASE_PATH . '/src/Modules/' . self::MODULE . '/Interfaces/HTTP';

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create copy-merge fixture dir: ' . $dir);
        }

        file_put_contents($dir . '/TempCopyFirstShellContextProvider.php', <<<'PHP'
            <?php

            declare(strict_types = 1);

            namespace Modules\TempCopy\Interfaces\HTTP;

            use Modules\Core\Domain\Contracts\ShellContextProvider;
            use Modules\Core\Domain\ShellNavItem;
            use Modules\Core\Infrastructure\Attributes\AsShellContextProvider;

            /** Merge-test fixture: holds three keys and a blank, and echoes its locale. */
            #[AsShellContextProvider(priority: 0)]
            final class TempCopyFirstShellContextProvider implements ShellContextProvider
            {
                public function siteName(): ?string
                {
                    return null;
                }

                public function theme(): ?string
                {
                    return null;
                }

                public function defaultLocale(): ?string
                {
                    return null;
                }

                public function activeLocale(): ?string
                {
                    return null;
                }

                public function htmlLang(): ?string
                {
                    return null;
                }

                /** @return list<string> */
                public function languages(): array
                {
                    return [];
                }

                /** @return array<string, string> */
                public function copy(string $group, string $locale): array
                {
                    return [
                        'home.merge_probe_a'      => 'first',
                        'home.merge_probe_b'      => 'first-b',
                        'home.merge_probe_d'      => '',
                        'home.merge_probe_locale' => $locale,
                    ];
                }

                /** @return list<ShellNavItem> */
                public function navigation(string $slot, string $currentPath): array
                {
                    return [];
                }

                /** @return array<string, string> */
                public function themeSettings(): array
                {
                    return [];
                }
            }

            PHP);

        file_put_contents($dir . '/TempCopySecondShellContextProvider.php', <<<'PHP'
            <?php

            declare(strict_types = 1);

            namespace Modules\TempCopy\Interfaces\HTTP;

            use Modules\Core\Domain\Contracts\ShellContextProvider;
            use Modules\Core\Domain\ShellNavItem;
            use Modules\Core\Infrastructure\Attributes\AsShellContextProvider;

            /** Merge-test fixture: the later provider — one shared key, one of its own, one that fills a blank. */
            #[AsShellContextProvider(priority: 10)]
            final class TempCopySecondShellContextProvider implements ShellContextProvider
            {
                public function siteName(): ?string
                {
                    return null;
                }

                public function theme(): ?string
                {
                    return null;
                }

                public function defaultLocale(): ?string
                {
                    return null;
                }

                public function activeLocale(): ?string
                {
                    return null;
                }

                public function htmlLang(): ?string
                {
                    return null;
                }

                /** @return list<string> */
                public function languages(): array
                {
                    return [];
                }

                /** @return array<string, string> */
                public function copy(string $group, string $locale): array
                {
                    return [
                        'home.merge_probe_b' => 'second-b',
                        'home.merge_probe_c' => 'second-c',
                        'home.merge_probe_d' => 'real',
                    ];
                }

                /** @return list<ShellNavItem> */
                public function navigation(string $slot, string $currentPath): array
                {
                    return [];
                }

                /** @return array<string, string> */
                public function themeSettings(): array
                {
                    return [];
                }
            }

            PHP);
    }

    private static function deleteFixture(): void
    {
        $dir = APP_BASE_PATH . '/src/Modules/' . self::MODULE;

        if (is_dir($dir)) {
            ModuleInstaller::deleteTree($dir);
        }
    }
}
