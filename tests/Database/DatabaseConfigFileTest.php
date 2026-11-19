<?php

declare(strict_types = 1);

namespace Tests\Database;

use Modules\Core\Infrastructure\Env;
use Modules\Database\Infrastructure\Persistence\DatabaseConfigFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DatabaseConfigFileTest — the precedence and the failure modes of
 * `storage/config/database.json`.
 *
 * The file is a fallback, so the tests that matter are the ones that prove it
 * loses: a process value is not overwritten, and a batch is not applied at all
 * when one key is wrong. A half-applied file is the failure this class exists
 * to prevent — a driver from one layer with a DSN from another.
 *
 * Every applied key goes through `putenv()`, which outlives the test, so each
 * test records the ambient values and puts them back.
 */
final class DatabaseConfigFileTest extends TestCase
{
    private string $directory = '';

    /** @var array<string, string|false> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach (['DB_DRIVER', 'DB_DSN', 'DB_DATABASE_PREFIX'] as $variable) {
            $this->previous[$variable] = getenv($variable);
            putenv($variable);
            unset($_ENV[$variable], $_SERVER[$variable]);
        }

        Env::reset();

        $this->directory = sys_get_temp_dir() . '/nativa-dbconfig-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory . '/storage/config', 0o700, true));
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $variable => $value) {
            if (false === $value) {
                putenv($variable);
            } else {
                putenv($variable . '=' . $value);
            }
        }

        Env::reset();
        Env::loadImmutable('');

        $this->removeDirectory($this->directory);
    }

    #[Test]
    public function itDoesNothingWhenThereIsNoFile(): void
    {
        self::assertNull(DatabaseConfigFile::read($this->directory));

        DatabaseConfigFile::load($this->directory);

        self::assertFalse(getenv('DB_DRIVER'));
        self::assertNull(Env::origin('DB_DRIVER'));
    }

    #[Test]
    public function itAppliesTheFileAsAFallbackAndRemembersTheSource(): void
    {
        DatabaseConfigFile::write($this->directory, 'pgsql', 'host=db;port=5432;dbname=nativa', 'nativa_');
        DatabaseConfigFile::load($this->directory);

        self::assertSame('pgsql', getenv('DB_DRIVER'));
        self::assertSame('host=db;port=5432;dbname=nativa', getenv('DB_DSN'));
        self::assertSame('nativa_', getenv('DB_DATABASE_PREFIX'));

        // The panel has to name the layer that won, or a saved switch that the
        // environment overrides looks like a switch that did nothing.
        self::assertSame(DatabaseConfigFile::relativePath(), Env::origin('DB_DRIVER'));
    }

    #[Test]
    public function theProcessEnvironmentWinsOverTheFile(): void
    {
        DatabaseConfigFile::write($this->directory, 'pgsql', 'host=file;dbname=nativa', 'file_');
        putenv('DB_DRIVER=sqlite');
        $_ENV['DB_DRIVER'] = 'sqlite';

        DatabaseConfigFile::load($this->directory);

        self::assertSame('sqlite', getenv('DB_DRIVER'));
        self::assertSame('process', Env::origin('DB_DRIVER'));
        // The DSN was not set in the environment, so the file still supplies it.
        self::assertSame('host=file;dbname=nativa', getenv('DB_DSN'));
    }

    #[Test]
    public function theMysqlSpellingIsStoredAsMariaDb(): void
    {
        DatabaseConfigFile::write($this->directory, 'mysql', 'host=db;user=nativa', 'nativa_');

        self::assertSame('mariadb', DatabaseConfigFile::read($this->directory)['driver'] ?? null);
    }

    #[Test]
    public function itRefusesAnUnknownKey(): void
    {
        file_put_contents(
            DatabaseConfigFile::path($this->directory),
            '{"driver": "pgsql", "dns": "host=db"}',
        );

        try {
            DatabaseConfigFile::load($this->directory);
            self::fail('an unknown key must not be ignored');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('unknown key "dns"', $exception->getMessage());
        }
    }

    #[Test]
    public function itAppliesNothingWhenAnyKeyIsInvalid(): void
    {
        file_put_contents(
            DatabaseConfigFile::path($this->directory),
            '{"driver": "pgsql", "dsn": "host=db;dbname=nativa", "prefix": "Nativa-"}',
        );

        try {
            DatabaseConfigFile::load($this->directory);
            self::fail('an invalid prefix must fail the load');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('"prefix"', $exception->getMessage());
        }

        // All or nothing: the valid driver and DSN did not leak through.
        self::assertFalse(getenv('DB_DRIVER'));
        self::assertFalse(getenv('DB_DSN'));
    }

    #[Test]
    public function itRefusesADsnForSqlite(): void
    {
        file_put_contents(
            DatabaseConfigFile::path($this->directory),
            '{"driver": "sqlite", "dsn": "host=db"}',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"dsn" is not read for the sqlite driver');

        DatabaseConfigFile::load($this->directory);
    }

    #[Test]
    public function itRefusesTruncatedJsonInsteadOfFallingBack(): void
    {
        file_put_contents(DatabaseConfigFile::path($this->directory), '{"driver": "pgsql"');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid JSON');

        DatabaseConfigFile::load($this->directory);
    }

    #[Test]
    public function itWritesTheFilePrivatelyAndReplacesItWhole(): void
    {
        $file = DatabaseConfigFile::path($this->directory);

        DatabaseConfigFile::write($this->directory, 'pgsql', 'host=db;password=first', 'nativa_');
        self::assertFileExists($file);
        self::assertSame('600', decoct(fileperms($file) & 0o777));

        DatabaseConfigFile::write($this->directory, 'mariadb', 'host=db;password=second', 'other_');

        $stored = DatabaseConfigFile::read($this->directory);

        self::assertSame('mariadb', $stored['driver'] ?? null);
        self::assertSame('host=db;password=second', $stored['dsn'] ?? null);
        self::assertSame('other_', $stored['prefix'] ?? null);
        self::assertArrayHasKey('updated_at', $stored);

        // The atomic write leaves no temporary file behind.
        $leftovers = glob($this->directory . '/.database.json.*') ?: [];
        self::assertSame([], $leftovers);
    }

    #[Test]
    public function anEmptyDsnIsOmittedForSqlite(): void
    {
        DatabaseConfigFile::write($this->directory, 'sqlite', '', 'nativa_');

        $stored = DatabaseConfigFile::read($this->directory);

        self::assertSame('sqlite', $stored['driver'] ?? null);
        self::assertArrayNotHasKey('dsn', $stored);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $path) {
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
