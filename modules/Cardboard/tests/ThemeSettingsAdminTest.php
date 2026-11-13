<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Interfaces\HTTP\Actions\SettingsAction;
use Modules\Cardboard\Interfaces\HTTP\CardboardServices;
use Themes\ThemeSettingValues;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Themes\ThemeKernel;
use Themes\ThemeRegistry;
use Themes\ThemeResolver;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use Modules\Settings\Infrastructure\Persistence\Repositories\SettingsRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Themes\ThemeSetting;
use Themes\ThemeSettings;

/**
 * ThemeSettingsAdminTest — the admin half of the per-theme settings contract.
 *
 * Three things can go silently wrong here, and each has its own test:
 *
 * 1. **The card renders something other than the theme's schema** — a form
 *    offering a field no theme reads, or opening on a value the renderer would
 *    reject. The card is rendered from the schema and vetted the same way the
 *    page vets it, so a non-default option opens selected only when the theme
 *    accepts it.
 * 2. **A theme without settings gets an empty form** — indistinguishable from a
 *    broken one, so it says so in words.
 * 3. **The save writes the wrong theme's row.** The POST body never names a
 *    theme; the target comes from the stored public theme, which is why
 *    `activeThemeName()` is public and tested on its own.
 *
 * The save test runs against its own temp databases (config + audit), not the
 * working tree's: the action flushes the page cache and writes an audit row,
 * and a test that touches those files is a test that changes the checkout it
 * runs in.
 *
 * The theme under test is the first INSTALLED one that declares a schema, not
 * a name spelled here: this module's test must not depend on `Boson` being the
 * one that ships the contract, and a theme repo that renames its reference must
 * not turn this file red for the wrong reason.
 */
final class ThemeSettingsAdminTest extends TestCase
{
    private string $tmpDir = '';

    /** @var \PDO|null */
    private ?\PDO $pdo = null;

    private string $theme = '';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . basename(dirname(__DIR__)))) {
            $this->markTestSkipped('Cardboard is not installed — the settings screen ships with this module.');
        }

        $theme = self::configurableTheme();

        if (null === $theme) {
            $this->markTestSkipped('No installed theme declares a settings schema — nothing to render.');
        }

        $this->theme = $theme;
    }

    protected function tearDown(): void
    {
        if ('' === $this->tmpDir) {
            parent::tearDown();

            return;
        }

        putenv('DB_CONFIG');
        putenv('DB_AUDIT');
        putenv('DB_USERS');
        DatabaseConnectionManager::resetInstance();
        CardboardServices::reset();

        if (class_exists(\Modules\Audit\Interfaces\HTTP\AuditServices::class)) {
            \Modules\Audit\Interfaces\HTTP\AuditServices::reset();
        }

        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    #[Test]
    public function theCardRendersTheActiveThemesDeclaredField(): void
    {
        $html = $this->card([]);

        self::assertStringContainsString('name="theme_settings[nav_toggle_icon]"', $html);
        self::assertStringContainsString('action="/route/mark.settings.theme"', $html);
        self::assertStringContainsString('<option value="' . $this->defaultValue() . '" selected>', $html);
        self::assertStringContainsString($this->field()->optionLabel($this->nonDefaultOption()), $html);
        self::assertStringContainsString('Theme settings — ' . $this->theme, $html);
    }

    #[Test]
    public function aStoredValueOpensSelected(): void
    {
        $value = $this->nonDefaultOption();
        $html = $this->card(['nav_toggle_icon' => $value]);

        self::assertStringContainsString('<option value="' . $value . '" selected>', $html);
        self::assertStringNotContainsString(
            '<option value="' . $this->defaultValue() . '" selected>',
            $html,
            'Two selected options would render the first one and ignore the stored value.',
        );
    }

    #[Test]
    public function anUnacceptableStoredValueFallsBackToTheDefault(): void
    {
        $html = $this->card(['nav_toggle_icon' => 'banana']);

        self::assertStringContainsString('<option value="' . $this->defaultValue() . '" selected>', $html);
        self::assertStringNotContainsString(
            'banana',
            $html,
            'A stored value the schema rejects must not appear in the form as if it were an option.',
        );
    }

    #[Test]
    public function aThemeWithoutTheContractSaysSoInsteadOfRenderingAnEmptyForm(): void
    {
        $html = SettingsAction::renderThemeSettingsCard(
            ThemeResolver::KERNEL_THEME,
            ThemeKernel::for(ThemeResolver::KERNEL_THEME),
            [],
            '',
            self::url(),
        );

        self::assertStringContainsString('kernel declares no settings', $html);
        self::assertStringNotContainsString('theme_settings[', $html);
    }

    #[Test]
    public function theCardIsBuiltFromTheThemesOwnSchema(): void
    {
        $module = ThemeKernel::for($this->theme);

        self::assertInstanceOf(ThemeSettings::class, $module);

        $keys = array_map(static fn ($field): string => $field->key, $module->settingsSchema());

        self::assertContains('nav_toggle_icon', $keys);
    }

    #[Test]
    public function theSavedThemeComesFromTheStoredVoteNotTheRequest(): void
    {
        self::assertSame($this->theme, SettingsAction::activeThemeName([ThemeResolver::THEME_SETTING => $this->theme]));
        self::assertSame(
            ThemeResolver::PUBLIC_THEME,
            SettingsAction::activeThemeName([ThemeResolver::THEME_SETTING => 'no-such-theme']),
            'A stored name this tree cannot render resolves like the public page resolves it.',
        );
        self::assertSame(ThemeResolver::PUBLIC_THEME, SettingsAction::activeThemeName([]));
    }

    #[Test]
    public function savingWritesTheRowOfTheActiveTheme(): void
    {
        $this->bootDatabases();
        $this->storeTheme($this->theme);

        $value = $this->nonDefaultOption();
        $this->save(['nav_toggle_icon' => $value]);

        $key = ThemeSettingValues::storageKey($this->theme, 'nav_toggle_icon');

        self::assertSame($value, $this->repository()->get($key), 'The active theme row is what the renderer reads.');
        self::assertNull(
            $this->repository()->get(ThemeSettingValues::storageKey(ThemeResolver::KERNEL_THEME, 'nav_toggle_icon')),
            'The row must be scoped to the active theme, not written for whichever theme is the fallback.',
        );
    }

    #[Test]
    public function anUnacceptablePostedValueStoresTheDefault(): void
    {
        $this->bootDatabases();
        $this->storeTheme($this->theme);

        $this->save(['nav_toggle_icon' => 'banana']);

        self::assertSame(
            $this->defaultValue(),
            $this->repository()->get(ThemeSettingValues::storageKey($this->theme, 'nav_toggle_icon')),
        );
    }

    #[Test]
    public function aFieldTheSchemaDoesNotDeclareIsNeverStored(): void
    {
        $this->bootDatabases();
        $this->storeTheme($this->theme);

        $this->save(['not_a_field' => 'x']);

        self::assertNull(
            $this->repository()->get(ThemeSettingValues::storageKey($this->theme, 'not_a_field')),
        );
    }

    #[Test]
    public function aSaveForAThemeWithoutASchemaWritesNothing(): void
    {
        $this->bootDatabases();
        $this->storeTheme(ThemeResolver::KERNEL_THEME);

        $this->save(['nav_toggle_icon' => 'meatball']);

        self::assertNull(
            $this->repository()->get(ThemeSettingValues::storageKey(ThemeResolver::KERNEL_THEME, 'nav_toggle_icon')),
            'The kernel theme declares no settings; a hand-made POST must not invent a row for it.',
        );
    }

    /**
     * The theme's nav-icon field, the one every configurable theme declares.
     */
    private function field(): ThemeSetting
    {
        $module = ThemeKernel::for($this->theme);
        self::assertInstanceOf(ThemeSettings::class, $module);

        foreach ($module->settingsSchema() as $field) {
            if ('nav_toggle_icon' === $field->key) {
                return $field;
            }
        }

        self::fail('The active theme declares no nav_toggle_icon field.');
    }

    private function defaultValue(): string
    {
        return $this->field()->fallbackValue();
    }

    /**
     * An option the schema accepts and that is not the default, so "selected"
     * measures the stored value rather than the fallback.
     */
    private function nonDefaultOption(): string
    {
        $field = $this->field();
        $default = $this->defaultValue();

        foreach (array_keys($field->options) as $value) {
            if ($default !== (string) $value) {
                return (string) $value;
            }
        }

        self::fail('The active theme declares no non-default nav_toggle_icon option.');
    }

    /**
     * The first installed theme that declares a schema — the one this screen
     * would actually show a block for.
     */
    private static function configurableTheme(): ?string
    {
        foreach (array_keys(ThemeRegistry::all()) as $name) {
            if (ThemeKernel::for($name) instanceof ThemeSettings) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $stored
     */
    private function card(array $stored): string
    {
        return SettingsAction::renderThemeSettingsCard(
            $this->theme,
            ThemeKernel::for($this->theme),
            $stored,
            '<input type="hidden" name="csrf_token" value="test">',
            self::url(),
        );
    }

    /**
     * @param array<string, string> $values
     */
    private function save(array $values): void
    {
        (new SettingsAction(self::router()))->saveThemeSettings(Request::create(
            'POST',
            '/mark/settings/theme',
            [],
            ['theme_settings' => $values],
        ));
    }

    /**
     * @return \Closure(string, array<string, string|int>): string
     */
    private static function url(): \Closure
    {
        return static fn (string $name): string => 'mark.settings.theme' === $name
            ? '/route/mark.settings.theme'
            : '/route/' . $name;
    }

    private static function router(): RouterInterface
    {
        return new class implements RouterInterface {
            public function match(Request $request): ?array
            {
                return null;
            }

            public function url(string $name, array $params = []): string
            {
                return 'mark.settings.theme' === $name ? '/route/mark.settings.theme' : '/route/' . $name;
            }
        };
    }

    private function bootDatabases(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/nativa-theme-settings-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        if (!class_exists(\Modules\Audit\Interfaces\HTTP\AuditServices::class)) {
            $this->markTestSkipped('Audit is not installed — the save path writes its ledger.');
        }

        putenv('DB_CONFIG=' . $this->tmpDir . '/config.db');
        putenv('DB_AUDIT=' . $this->tmpDir . '/audit.db');
        putenv('DB_USERS=' . $this->tmpDir . '/users.db');
        DatabaseConnectionManager::resetInstance();
        CardboardServices::reset();
        \Modules\Audit\Interfaces\HTTP\AuditServices::reset();

        $this->pdo = new \PDO('sqlite:' . $this->tmpDir . '/config.db');
        (new SettingsDatabaseMigrator($this->pdo))->run(self::quietOutput());
        (new \Modules\Audit\Infrastructure\Persistence\Migrations\AuditDatabaseMigrator(
            new \PDO('sqlite:' . $this->tmpDir . '/audit.db'),
        ))->run(self::quietOutput());
    }

    private function storeTheme(string $theme): void
    {
        $this->repository()->set(ThemeResolver::THEME_SETTING, $theme, 'design', false);
    }

    private function repository(): SettingsRepository
    {
        if (null === $this->pdo) {
            throw new \LogicException('bootDatabases() must run first.');
        }

        return new SettingsRepository(DatabaseConnectionManager::instance()->connect('config'));
    }

    private static function quietOutput(): CliOutput
    {
        return new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
