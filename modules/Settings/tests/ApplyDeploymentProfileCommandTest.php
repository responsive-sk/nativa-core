<?php

declare(strict_types = 1);

namespace Modules\Settings\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use Modules\Layout\ThemeResolver;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use Modules\Settings\Infrastructure\Persistence\Statement;
use Modules\Settings\Interfaces\CLI\Command\ApplyDeploymentProfileCommand;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ApplyDeploymentProfileCommandTest — the build-time identity, pinned.
 *
 * The command exists because a rebuilt artifact used to arrive as the kernel's
 * own site: `migrate` seeds the kernel theme and the kernel's site name, and
 * nothing copies the developer's `config.db`, so `config/deploy.php` is the only
 * thing that can tell a build which site it is. These cases pin the refusals
 * first — an unknown key, a missing file, an empty profile — because the failure
 * mode this command has to make impossible is a build that LOOKS green while
 * shipping the defaults.
 *
 * The values are deliberately sentinels (`profile-theme`) and not this
 * repository's own deployment: this file is about the WRITING — which rows move,
 * which do not, and what happens when the profile lies — while the theme key
 * itself is read from its owner, `ThemeResolver::THEME_SETTING`. A test that
 * spelled `'boson'` here would pin one operator's profile into the module and
 * fail the moment the profile changed, which is the opposite of what it is for;
 * the build's own profile is verified by building.
 *
 * The database is the module's own migrator against a temp file (the shape
 * SettingsShellContextProviderTest uses), so the vocabulary the profile is
 * checked against is the vocabulary a deployment actually gets.
 */
final class ApplyDeploymentProfileCommandTest extends TestCase
{
    private string $tmpDir = '';

    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        // The INSTALLED slice, not the catalogue. `Modules\…` autoloads from
        // `src/Modules/` alone (composer.json psr-4), so a checkout that has
        // the catalogue under `modules/` and nothing installed used to pass
        // this guard and then die on the next line with "Class
        // SettingsDatabaseMigrator not found" — the kernel-only CI job reported
        // ERRORS where every other module test reports a skip. The guard has
        // to ask the same question the autoloader answers.
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Settings')) {
            $this->markTestSkipped('Settings is not installed — the command ships with the installed slice.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-deploy-profile-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        $this->pdo = new PDO('sqlite:' . $this->tmpDir . '/config.db');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new SettingsDatabaseMigrator($this->pdo))->run($this->recorder());
    }

    protected function tearDown(): void
    {
        if ('' !== $this->tmpDir && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function itAppliesTheProfileAndLeavesTheRowsOwnMetadataAlone(): void
    {
        $path = $this->profileOf([
            ThemeResolver::THEME_SETTING => 'profile-theme',
            'site_name'                  => 'profile.example',
        ]);
        $recorder = $this->recorder();

        $exit = (new ApplyDeploymentProfileCommand($this->db()))->run(['settings:profile', $path], $recorder);

        $this->assertSame(0, $exit, $recorder->text());
        $this->assertSame('profile-theme', $this->setting(ThemeResolver::THEME_SETTING)['setting_value']);
        $this->assertSame('profile.example', $this->setting('site_name')['setting_value']);

        // The seed's metadata is not the profile's business: the theme row is a
        // non-public design row, and a profile that flipped those flags would
        // change what the frontend may read.
        $theme = $this->setting(ThemeResolver::THEME_SETTING);

        $this->assertSame('design', $theme['group_name']);
        $this->assertSame(0, (int) $theme['is_public']);
        $this->assertSame('string', $theme['value_type']);

        $siteName = $this->setting('site_name');

        $this->assertSame('general', $siteName['group_name']);
        $this->assertSame(1, (int) $siteName['is_public']);
    }

    #[Test]
    public function aSecondRunIsIdempotent(): void
    {
        $path = $this->profileOf([ThemeResolver::THEME_SETTING => 'profile-theme']);
        $command = new ApplyDeploymentProfileCommand($this->db());

        $this->assertSame(0, $command->run(['settings:profile', $path], $this->recorder()));
        $this->assertSame(0, $command->run(['settings:profile', $path], $this->recorder()));
        $this->assertSame('profile-theme', $this->setting(ThemeResolver::THEME_SETTING)['setting_value']);
    }

    #[Test]
    public function anUnknownKeyRefusesTheWholeProfileAndWritesNothing(): void
    {
        $path = $this->profileOf([
            ThemeResolver::THEME_SETTING => 'profile-theme',
            'sitename'                   => 'profile.example',
        ]);
        $recorder = $this->recorder();

        $exit = (new ApplyDeploymentProfileCommand($this->db()))->run(['settings:profile', $path], $recorder);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('sitename', $recorder->text());
        $this->assertSame(
            ThemeResolver::KERNEL_THEME,
            $this->setting(ThemeResolver::THEME_SETTING)['setting_value'],
            'A refused profile must not half-apply.',
        );
        $this->assertNull($this->setting('sitename'), 'The command must not invent the row it was told about.');
    }

    #[Test]
    public function aMissingProfileIsRefusedWithItsPath(): void
    {
        $path = $this->tmpDir . '/no-such-profile.php';
        $recorder = $this->recorder();

        $exit = (new ApplyDeploymentProfileCommand($this->db()))->run(['settings:profile', $path], $recorder);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString($path, $recorder->text());
        $this->assertSame(ThemeResolver::KERNEL_THEME, $this->setting(ThemeResolver::THEME_SETTING)['setting_value']);
    }

    #[Test]
    public function aProfileThatReturnsNoArrayIsRefused(): void
    {
        $recorder = $this->recorder();

        $exit = (new ApplyDeploymentProfileCommand($this->db()))->run(
            ['settings:profile', $this->rawProfile("<?php\n\nreturn 'profile-theme';\n")],
            $recorder,
        );

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('must return an array', $recorder->text());
    }

    #[Test]
    public function anEmptyProfileIsRefusedBecauseItWouldShipTheDefaults(): void
    {
        $recorder = $this->recorder();

        $exit = (new ApplyDeploymentProfileCommand($this->db()))->run(
            ['settings:profile', $this->profileOf([])],
            $recorder,
        );

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('empty array', $recorder->text());
    }

    #[Test]
    public function aNonScalarValueIsRefused(): void
    {
        $recorder = $this->recorder();

        $exit = (new ApplyDeploymentProfileCommand($this->db()))->run(
            ['settings:profile', $this->profileOf([ThemeResolver::THEME_SETTING => ['profile-theme']])],
            $recorder,
        );

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('string, number or boolean', $recorder->text());
        $this->assertSame(ThemeResolver::KERNEL_THEME, $this->setting(ThemeResolver::THEME_SETTING)['setting_value']);
    }

    /**
     * A `boolean` row is read as a boolean, so the profile may say `true`; the
     * stored value is still the '1'/'0' the row's own readers compare against.
     */
    #[Test]
    public function booleanRowsTakeTrueAndFalse(): void
    {
        $command = new ApplyDeploymentProfileCommand($this->db());

        $this->assertSame(0, $command->run(
            ['settings:profile', $this->profileOf(['maintenance_mode' => true])],
            $this->recorder(),
        ));
        $this->assertSame('1', $this->setting('maintenance_mode')['setting_value']);
        $this->assertSame('boolean', $this->setting('maintenance_mode')['value_type']);

        $this->assertSame(0, $command->run(
            ['settings:profile', $this->profileOf(['maintenance_mode' => false])],
            $this->recorder(),
        ));
        $this->assertSame('0', $this->setting('maintenance_mode')['setting_value']);
    }

    #[Test]
    public function theFileOptionSpellingIsAcceptedToo(): void
    {
        $path = $this->profileOf(['site_name' => 'profile.example']);

        $exit = (new ApplyDeploymentProfileCommand($this->db()))->run(
            ['settings:profile', '--file=' . $path],
            $this->recorder(),
        );

        $this->assertSame(0, $exit);
        $this->assertSame('profile.example', $this->setting('site_name')['setting_value']);
    }

    /**
     * A profile written from an array, the way `config/deploy.php` is written by
     * hand — the array is exported, not interpolated into a string.
     *
     * @param array<string, mixed> $settings
     */
    private function profileOf(array $settings): string
    {
        return $this->rawProfile("<?php\n\ndeclare(strict_types = 1);\n\nreturn " . var_export($settings, true) . ";\n");
    }

    private function rawProfile(string $php): string
    {
        $path = $this->tmpDir . '/profile-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, $php);

        return $path;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function setting(string $key): ?array
    {
        $statement = Statement::prepare($this->db(), 'SELECT * FROM settings WHERE setting_key = :key');
        $statement->execute([':key' => $key]);
        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return false === $row ? null : $row;
    }

    /**
     * A `CliOutput` that keeps what it was told — a refusal's wording is part
     * of its contract (it names the key, the path, the reason).
     */
    private function recorder(): CliOutput
    {
        return new class () implements CliOutput {
            /** @var list<string> */
            public array $lines = [];

            public function writeln(string $message): void
            {
                $this->lines[] = $message;
            }

            public function text(): string
            {
                return implode("\n", $this->lines);
            }
        };
    }

    /**
     * The command takes the connection contract now; this wraps the test's
     * temp-file handle without routing anywhere.
     */
    private function db(): PdoConnection
    {
        return new PdoConnection($this->pdo, 'config');
    }
}
