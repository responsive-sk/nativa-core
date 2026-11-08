<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Tests;

use Modules\Clickhouse\Application\ClickHouseClient;
use Modules\Clickhouse\Application\Services\SqliteEngineBridge;
use Modules\Clickhouse\Contracts\ClickHouseTransport;
use Modules\Clickhouse\Domain\SqliteAttachment;
use Modules\Core\Infrastructure\Paths\AppPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * SqliteEngineBridgeTest — the plan, the refusals and the drift comparison.
 *
 * The DDL is asserted as a golden string: `ENGINE = SQLite('…')` is the whole
 * bridge, and a format string that drifted in one place while the tests read
 * it from another would still pass.
 *
 * Files are injected through `DB_<NAME>`, which is how a deployment relocates
 * a database — `AppPaths` resolves the override, so the test uses the same
 * path resolution production does. Every temporary file and environment
 * variable is removed in tearDown().
 */
final class SqliteEngineBridgeTest extends TestCase
{
    private const MODULE = 'Clickhouse';

    /** @var list<string> paths created by this test */
    private array $created = [];

    /** @var list<string> symlinks created by this test */
    private array $links = [];

    /** @var array<string, string> env name → previous value */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the bridge lives in that module.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach ($this->links as $link) {
            if (is_link($link)) {
                unlink($link);
            }
        }

        $this->created = [];
        $this->links = [];

        foreach ($this->previousEnv as $name => $value) {
            putenv($name . '=' . $value);
        }

        $this->previousEnv = [];

        AppPaths::resetInstance();
    }

    #[Test]
    public function theGoldenDdlNamesTheDatabaseAndTheAbsolutePath(): void
    {
        $path = $this->temporaryDatabase('zgold');
        $attachment = $this->planOne('zgold', 'nativa_');

        self::assertSame('nativa_zgold', $attachment->databaseName);
        self::assertSame((string) realpath($path), $attachment->path);
        self::assertSame(basename($path), $attachment->file);

        self::assertSame(
            ["CREATE DATABASE IF NOT EXISTS nativa_zgold ENGINE = SQLite('" . realpath($path) . "')"],
            $attachment->statements(),
        );
        self::assertSame('DROP DATABASE IF EXISTS nativa_zgold', $attachment->detachStatement());
    }

    /**
     * The operator may name the database the way `status` prints it or the way
     * the file is named; both mean the same attachment.
     */
    #[Test]
    public function onlyAcceptsTheBareFileNameOrThePrefixedDatabaseName(): void
    {
        $path = $this->temporaryDatabase('zonly');

        self::assertCount(1, (new SqliteEngineBridge())->plan('nativa_', 'zonly'));
        self::assertCount(1, (new SqliteEngineBridge())->plan('nativa_', 'nativa_zonly'));
        self::assertSame(realpath($path), $this->planOne('zonly', 'nativa_')->path);
    }

    #[Test]
    public function anExplicitlyNamedMissingFileIsRefused(): void
    {
        $this->useDatabase('zmissing', sys_get_temp_dir() . '/clickhouse-zmissing-does-not-exist.db');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLite file for nativa_zmissing is missing');

        (new SqliteEngineBridge())->plan('nativa_', 'zmissing');
    }

    #[Test]
    public function anUnknownNameIsRefusedRatherThanPlanningNothing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No SQLite database matches --db=no_such_database.');

        (new SqliteEngineBridge())->plan('nativa_', 'no_such_database');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badPrefixes(): array
    {
        return [
            'empty'      => [''],
            'starts digit' => ['1nativa'],
            'dash'       => ['nativa-'],
            'space'      => ['nativa '],
        ];
    }

    #[Test]
    #[DataProvider('badPrefixes')]
    public function aPrefixThatFailsTheRuleIsRefused(string $prefix): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ClickHouse prefix');

        (new SqliteEngineBridge())->plan($prefix);
    }

    #[Test]
    public function aNameThatFailsTheRuleAfterThePrefixIsRefused(): void
    {
        // A dash is legal in a file name and illegal in a database name; the
        // prefix cannot rescue it, so the composed name is what is checked.
        $this->useDatabase('zz-test', sys_get_temp_dir() . '/clickhouse-zz-test.db');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ClickHouse database name');

        (new SqliteEngineBridge())->plan('nativa_', 'zz-test');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badPaths(): array
    {
        return [
            'relative'      => ['relative/blog.db', 'not absolute'],
            'single quote'  => ["/srv/blo'g.db", 'quote'],
            'backslash'     => ['/srv/blo\\g.db', 'backslash'],
            'NUL byte'      => ["/srv/blog\0.db", 'NUL'],
        ];
    }

    #[Test]
    #[DataProvider('badPaths')]
    public function aPathThatWouldChangeTheLiteralIsRefused(string $path, string $reason): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($reason);

        SqliteEngineBridge::assertAttachablePath('nativa_blog', $path);
    }

    #[Test]
    public function aSymlinkedStorageResolvesToTheRealFile(): void
    {
        $directory = sys_get_temp_dir() . '/clickhouse-real-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o775, true);
        $real = $directory . '/data.db';
        file_put_contents($real, '');
        $this->created[] = $real;

        $link = sys_get_temp_dir() . '/clickhouse-link-' . bin2hex(random_bytes(4));
        symlink($directory, $link);
        $this->links[] = $link;

        $this->useDatabase('zlink', $link . '/data.db');

        // The server-side half of open question 2 is not measured here (no
        // ClickHouse server); what is decided is that the module sends the
        // resolved local path, so a bind mount sees the same file.
        self::assertSame(realpath($real), $this->planOne('zlink', 'nativa_')->path);
    }

    #[Test]
    public function applySendsTheCreateAndReportsTheTableCount(): void
    {
        $path = (string) realpath($this->temporaryDatabase('zapply'));
        $attachment = new SqliteAttachment('nativa_zapply', $path, basename($path));
        $transport = $this->transport(['', "{\"name\":\"article_views\"}\n"]);

        (new SqliteEngineBridge())->apply(new ClickHouseClient($transport), [$attachment], false);

        self::assertCount(2, $transport->calls);
        self::assertSame("CREATE DATABASE IF NOT EXISTS nativa_zapply ENGINE = SQLite('" . $path . "')", $transport->calls[0]['sql']);
        self::assertStringContainsString('SHOW TABLES FROM nativa_zapply', $transport->calls[1]['sql']);
    }

    #[Test]
    public function forceDropsBeforeItCreates(): void
    {
        $attachment = new SqliteAttachment('nativa_zforce', '/srv/zforce.db', 'zforce.db');
        $transport = $this->transport(['', '', "{\"name\":\"t\"}\n"]);

        (new SqliteEngineBridge())->apply(new ClickHouseClient($transport), [$attachment], true);

        self::assertSame('DROP DATABASE IF EXISTS nativa_zforce', $transport->calls[0]['sql']);
        self::assertStringStartsWith('CREATE DATABASE IF NOT EXISTS nativa_zforce', $transport->calls[1]['sql']);
    }

    #[Test]
    public function driftNamesABackendThatReadsADifferentPath(): void
    {
        $attachment = new SqliteAttachment('nativa_blog', '/new/storage/blog.db', 'blog.db');
        $transport = $this->transport([
            "{\"name\":\"nativa_blog\"}\n",
            "{\"statement\":\"CREATE DATABASE nativa_blog\\nENGINE = SQLite('/old/storage/blog.db')\"}\n",
        ]);

        $lines = (new SqliteEngineBridge())->drift(new ClickHouseClient($transport), [$attachment]);

        self::assertCount(1, $lines);
        self::assertStringContainsString('/old/storage/blog.db', $lines[0]);
        self::assertStringContainsString('/new/storage/blog.db', $lines[0]);
    }

    #[Test]
    public function driftNamesALocalFileThatIsNotAttached(): void
    {
        $attachment = new SqliteAttachment('nativa_blog', '/srv/blog.db', 'blog.db');
        $transport = $this->transport(["{\"name\":\"default\"}\n"]);

        $lines = (new SqliteEngineBridge())->drift(new ClickHouseClient($transport), [$attachment]);

        self::assertSame(['nativa_blog: not attached (local file /srv/blog.db)'], $lines);
    }

    #[Test]
    public function anAgreeingServerHasNoDrift(): void
    {
        $attachment = new SqliteAttachment('nativa_blog', '/srv/blog.db', 'blog.db');
        $transport = $this->transport([
            "{\"name\":\"nativa_blog\"}\n",
            "{\"statement\":\"CREATE DATABASE nativa_blog\\nENGINE = SQLite('/srv/blog.db')\"}\n",
        ]);

        self::assertSame([], (new SqliteEngineBridge())->drift(new ClickHouseClient($transport), [$attachment]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function createStatements(): array
    {
        return [
            'plain'      => ["CREATE DATABASE nativa_blog ENGINE = SQLite('/srv/blog.db')", '/srv/blog.db'],
            'newlines'   => ["CREATE DATABASE nativa_blog\nENGINE = SQLite('/srv/blog.db')", '/srv/blog.db'],
            'spaces'     => ["CREATE DATABASE `nativa_blog` ENGINE = SQLite( '/srv/blog.db' )", '/srv/blog.db'],
        ];
    }

    #[Test]
    #[DataProvider('createStatements')]
    public function theSubjectPathIsReadBackFromShowCreate(string $statement, string $expected): void
    {
        self::assertSame($expected, SqliteEngineBridge::sqlitePathFrom($statement));
    }

    #[Test]
    public function aStatementWithoutASqliteEngineHasNoPath(): void
    {
        self::assertNull(SqliteEngineBridge::sqlitePathFrom('CREATE DATABASE x ENGINE = Atomic'));
    }

    private function planOne(string $name, string $prefix): SqliteAttachment
    {
        $attachments = (new SqliteEngineBridge())->plan($prefix, $name);
        self::assertCount(1, $attachments);

        return $attachments[0];
    }

    private function temporaryDatabase(string $name): string
    {
        $path = sys_get_temp_dir() . '/clickhouse-' . $name . '-' . bin2hex(random_bytes(4)) . '.db';
        file_put_contents($path, '');
        $this->created[] = $path;
        $this->useDatabase($name, $path);

        return $path;
    }

    private function useDatabase(string $name, string $path): void
    {
        $key = 'DB_' . strtoupper($name);
        $this->previousEnv[$key] = getenv($key) ?: '';
        putenv($key . '=' . $path);
    }

    /**
     * The seam: records every statement and answers from a queue.
     *
     * Anonymous on purpose: PHPUnit loads this file in a kernel-only checkout
     * too, where the module is absent — a file-scope `implements
     * ClickHouseTransport` would fail at class declaration before setUp() can
     * skip it. Only calling the factory resolves the interface.
     *
     * @param list<string|Throwable> $answers in order; an empty queue answers ''
     */
    private function transport(array $answers = []): ClickHouseTransport
    {
        return new class($answers) implements ClickHouseTransport {
            /** @var list<array{sql: string, database: string}> */
            public array $calls = [];

            /**
             * @param list<string|Throwable> $answers
             */
            public function __construct(private array $answers = [])
            {
            }

            public function execute(string $sql, string $database = ''): string
            {
                $this->calls[] = ['sql' => $sql, 'database' => $database];

                $answer = array_shift($this->answers);

                if ($answer instanceof Throwable) {
                    throw $answer;
                }

                return $answer ?? '';
            }
        };
    }
}
