<?php

declare(strict_types = 1);

namespace Tests\Discovery;

use Modules\Tooling\ModuleInstaller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shell\ShellContextProviderRegistry;

/**
 * PreferenceContentionTest — a second provider at the WINNING priority must
 * never preempt the first answer (RFC-2 keeps first-wins; the improvement is
 * the log, not a new winner).
 *
 * The fixture is a throwaway installed slice answering `theme()` at the same
 * priority the Settings provider votes at (-100), created and deleted around
 * the assertion like `ModuleLifecycleTest::createHolderFixture()`. The log
 * line itself is not asserted (`error_log` is not observable here) — what is
 * pinned is the winner's stability with and without the contender.
 *
 * The premise needs an incumbent: on a kernel-only tree no installed
 * provider votes a theme, so `$before` is null and the fixture would become
 * the only answer (correct first-wins, nothing preempted). That tree skips
 * rather than fails — the same contract the module test-suite documents for
 * missing modules. The pure tie order itself is pinned without discovery in
 * `PreferenceVoteTest`, which runs everywhere.
 */
final class PreferenceContentionTest extends TestCase
{
    private const string MODULE = 'TempVote';

    protected function tearDown(): void
    {
        self::deleteFixture();
        ShellContextProviderRegistry::reset();

        parent::tearDown();
    }

    #[Test]
    public function samePrioritySecondAnswerNeverPreemptsTheFirst(): void
    {
        ShellContextProviderRegistry::reset();
        $before = ShellContextProviderRegistry::theme();

        if (null === $before) {
            $this->markTestSkipped('No installed provider votes a theme — nothing to contend with (kernel-only tree).');
        }

        try {
            self::createFixture();
            ShellContextProviderRegistry::reset();
            $after = ShellContextProviderRegistry::theme();

            $this->assertSame(
                $before,
                $after,
                'A same-priority contender must not change the theme answer (first wins; the contender is logged, not elected).',
            );
        } finally {
            self::deleteFixture();
            ShellContextProviderRegistry::reset();
        }
    }

    private static function createFixture(): void
    {
        $dir = APP_BASE_PATH . '/src/Modules/' . self::MODULE . '/Interfaces/HTTP';

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create contention fixture dir: ' . $dir);
        }

        file_put_contents(
            $dir . '/TempVoteShellContextProvider.php',
            <<<'PHP'
                <?php

                declare(strict_types = 1);

                namespace Modules\TempVote\Interfaces\HTTP;

                use Shell\ShellContextProvider;
                use Shell\ShellNavItem;
                use Shell\AsShellContextProvider;

                /** Contention-test fixture: answers theme() at the Settings vote priority. */
                #[AsShellContextProvider(priority: -100)]
                final class TempVoteShellContextProvider implements ShellContextProvider
                {
                    public function siteName(): ?string
                    {
                        return null;
                    }

                    public function siteDescription(): ?string
                    {
                        return null;
                    }

                    public function theme(): ?string
                    {
                        return 'kernel';
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
                        return [];
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

                PHP,
        );
    }

    private static function deleteFixture(): void
    {
        $dir = APP_BASE_PATH . '/src/Modules/' . self::MODULE;

        if (is_dir($dir)) {
            ModuleInstaller::deleteTree($dir);
        }
    }
}
