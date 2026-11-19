<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Interfaces\HTTP\Actions\SettingsAction;
use Modules\Database\Infrastructure\Persistence\ConnectionSummary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DatabaseSettingsCardTest — the Settings → Database tab.
 *
 * The card answers a diagnostic question from the environment, and the screen
 * it lands on is a browser, so two things are tested harder than the rest:
 *
 * 1. **The password never reaches the HTML.** `DB_DSN` carries credentials in
 *    production; a redactor that only handled the exact spelling in one docblock
 *    would leak the real one. Every key spelling is masked, and the rendered
 *    card is checked for the secret itself, not for the placeholder.
 * 2. **The card describes the engine that is actually configured**, including
 *    the default (`DB_DRIVER` unset means SQLite) and the fact that the prefix
 *    belongs to MariaDB only.
 *
 * The summary reads `getenv()`, so each test sets the three keys and restores
 * them afterwards — a leaked `DB_DRIVER=pgsql` would change every later test in
 * the same process.
 *
 * The file lives in the module catalogue, so it skips where the module is not
 * installed (`src/Modules/Cardboard` absent — the nativa-core export); there the
 * screen it describes does not exist either.
 */
final class DatabaseSettingsCardTest extends TestCase
{
    private const string MODULE = 'Cardboard';

    /** @var array<string, string|false> */
    private array $previous = [];

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the settings screen ships with this module.');
        }

        foreach (['DB_DRIVER', 'DB_DSN', 'DB_DATABASE_PREFIX'] as $key) {
            $this->previous[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            false === $value ? putenv($key) : putenv($key . '=' . $value);
        }
    }

    #[Test]
    public function itMasksEveryPasswordSpellingAndKeepsTheRestOfTheDsnReadable(): void
    {
        foreach (['password', 'PASSWORD', 'passwd', 'pass', 'pwd'] as $key) {
            self::assertSame(
                'host=db;port=5432;dbname=nativa;user=nativa;' . $key . '=***',
                ConnectionSummary::redact('host=db;port=5432;dbname=nativa;user=nativa;' . $key . '=s3cr3t'),
            );
        }

        self::assertSame('', ConnectionSummary::redact(''));
    }

    #[Test]
    public function anUnsetDriverReadsAsTheDefaultSqliteAndSaysSo(): void
    {
        putenv('DB_DRIVER');
        putenv('DB_DSN');

        $html = SettingsAction::renderDatabaseCard(ConnectionSummary::fromEnvironment());

        self::assertStringContainsString('sqlite', $html);
        self::assertStringContainsString('nothing sets <code>DB_DRIVER</code>', $html);
        self::assertStringContainsString('storage/config/database.json', $html);
        self::assertStringContainsString('storage/data/', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    #[Test]
    public function theCardShowsTheConfiguredServerAndMasksItsPassword(): void
    {
        putenv('DB_DRIVER=pgsql');
        putenv('DB_DSN=host=db.internal;port=5432;dbname=nativa;user=nativa;password=hunter2');

        $html = SettingsAction::renderDatabaseCard(ConnectionSummary::fromEnvironment());

        self::assertStringContainsString('pgsql', $html);
        self::assertStringContainsString('host=db.internal;port=5432;dbname=nativa;user=nativa;password=***', $html);
        self::assertStringContainsString('One schema per module', $html);
        self::assertStringNotContainsString('hunter2', $html);
        self::assertStringNotContainsString('nativa_&lt;module&gt;', $html);
    }

    #[Test]
    public function mariaDbNamesTheModuleDatabasesItCreates(): void
    {
        putenv('DB_DRIVER=mariadb');
        putenv('DB_DSN=host=db.internal;user=root;password=hunter2');
        putenv('DB_DATABASE_PREFIX=app_');

        $summary = ConnectionSummary::fromEnvironment();
        $html = SettingsAction::renderDatabaseCard($summary);

        self::assertTrue($summary->usesPrefix());
        self::assertStringContainsString('app_&lt;module&gt;', $html);
        self::assertStringNotContainsString('hunter2', $html);
    }

    #[Test]
    public function theCardNamesTheLayerAndWarnsWhenTheEnvironmentShadowsTheFile(): void
    {
        putenv('DB_DRIVER=sqlite');
        putenv('DB_DSN');
        putenv('DB_DATABASE_PREFIX');

        $file = [
            'driver' => 'pgsql',
            'dsn'    => 'host=file.internal;password=file-secret',
            'prefix' => 'nativa_',
        ];

        $html = SettingsAction::renderDatabaseCard(
            ConnectionSummary::fromEnvironment($file),
            $file,
            'csrf-token',
            '/mark/settings/database',
        );

        // The layer that won, not just the value: a save the environment
        // overrides must not look like a save that did nothing.
        self::assertStringContainsString('environment', $html);
        self::assertStringContainsString(
            'DB_DRIVER is sqlite in the environment but pgsql in storage/config/database.json',
            $html,
        );
        self::assertStringContainsString('<form', $html);
        self::assertStringContainsString('action="/mark/settings/database"', $html);
        self::assertStringContainsString('csrf-token', $html);
        self::assertStringNotContainsString('file-secret', $html);
    }

    #[Test]
    public function sqliteDoesNotClaimAPrefixItNeverUses(): void
    {
        putenv('DB_DRIVER=sqlite');
        putenv('DB_DATABASE_PREFIX=app_');

        $summary = ConnectionSummary::fromEnvironment();

        self::assertFalse($summary->usesPrefix());
        self::assertStringNotContainsString('app_', SettingsAction::renderDatabaseCard($summary));
    }
}
