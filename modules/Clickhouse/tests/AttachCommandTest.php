<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Tests;

use Modules\Clickhouse\Application\ClickHouseClient;
use Modules\Clickhouse\Contracts\ClickHouseTransport;
use Modules\Clickhouse\Interfaces\CLI\Command\AttachCommand;
use Modules\Clickhouse\Interfaces\HTTP\ClickhouseServices;
use Modules\Core\Infrastructure\Env;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\CLI\CliOutput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * AttachCommandTest — dry-run, ordering, the unconfigured refusal and the WAL.
 *
 * The exit code is the contract here too: `--dry-run` that wrote, or a run
 * that attached nothing while printing a healthy summary, would be invisible
 * to an operator. The files come in through `DB_<NAME>`, so the plan sees the
 * same path resolution a deployment does.
 */
final class AttachCommandTest extends TestCase
{
    private const MODULE = 'Clickhouse';

    /** @var list<string> */
    private array $created = [];

    /** @var array<string, string> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the command lives in that module.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->created = [];

        foreach ($this->previousEnv as $name => $value) {
            putenv($name . '=' . $value);
        }

        $this->previousEnv = [];

        // PHPUnit runs tearDown() even when setUp() skipped, so in a
        // kernel-only checkout this class does not exist yet.
        if (class_exists(ClickhouseServices::class)) {
            ClickhouseServices::reset();
        }

        Env::reset();
        Env::loadImmutable('');
        AppPaths::resetInstance();
    }

    #[Test]
    public function aDryRunPrintsTheStatementAndWritesNothing(): void
    {
        $path = $this->database('zattach');
        $transport = $this->transport();
        $output = $this->collector();

        $exit = $this->command($transport)->run(
            ['clickhouse:attach', '--db', 'zattach', '--dry-run'],
            $output,
        );

        $this->assertSame(0, $exit, $output->text());
        $this->assertSame(
            "CREATE DATABASE IF NOT EXISTS nativa_zattach ENGINE = SQLite('" . realpath($path) . "')",
            $output->lines[0] ?? null,
        );
        $this->assertStringContainsString('Dry run: 1 database(s), nothing changed.', $output->text());
        $this->assertSame([], $transport->calls, 'A dry run must not send a single request.');
    }

    #[Test]
    public function forcePrintsTheDropBeforeTheCreate(): void
    {
        $this->database('zforce');
        $output = $this->collector();

        $exit = (new AttachCommand())->run(
            ['clickhouse:attach', '--db', 'zforce', '--dry-run', '--force'],
            $output,
        );

        $this->assertSame(0, $exit, $output->text());
        $text = $output->text();
        $drop = strpos($text, 'DROP DATABASE IF EXISTS nativa_zforce');
        $create = strpos($text, 'CREATE DATABASE IF NOT EXISTS nativa_zforce');
        $this->assertNotFalse($drop);
        $this->assertNotFalse($create);
        $this->assertLessThan($create, $drop, 'The DROP has to come first.');
    }

    #[Test]
    public function aNonEmptyWalIsReportedUnlessCheckpointed(): void
    {
        $path = $this->database('zwal');
        file_put_contents($path . '-wal', 'uncheckpointed');
        $this->created[] = $path . '-wal';

        $output = $this->collector();
        $exit = (new AttachCommand())->run(
            ['clickhouse:attach', '--db', 'zwal', '--dry-run'],
            $output,
        );

        $this->assertSame(0, $exit, $output->text());
        $this->assertStringContainsString('-wal', $output->text());
        $this->assertStringContainsString('in the WAL', $output->text());
    }

    #[Test]
    public function anUnconfiguredServerIsRefusedBeforeAnythingIsSent(): void
    {
        $this->database('zunconfigured');
        $this->useEnv('CLICKHOUSE_URL', '');

        $output = $this->collector();
        $exit = (new AttachCommand())->run(['clickhouse:attach', '--db', 'zunconfigured'], $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'ClickHouse is not configured (CLICKHOUSE_URL, CLICKHOUSE_USER, CLICKHOUSE_PASSWORD)',
            $output->text(),
        );
    }

    #[Test]
    public function typedAddsTheExplicitTableAndItsWarning(): void
    {
        $path = $this->database('ztyped');
        (new \PDO('sqlite:' . $path))->exec(
            'CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, viewed_at DATETIME)',
        );

        $output = $this->collector();
        $exit = (new AttachCommand())->run(
            ['clickhouse:attach', '--db', 'ztyped', '--typed', '--dry-run'],
            $output,
        );

        $text = $output->text();
        $this->assertSame(0, $exit, $text);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS nativa_ztyped__events', $text);
        $this->assertStringContainsString('id Int64', $text);
        $this->assertStringContainsString('viewed_at DateTime', $text);
        $this->assertStringContainsString(
            'typed nativa_ztyped.events.viewed_at as DateTime from TEXT',
            $text,
        );
    }

    #[Test]
    public function aTypoInAnOptionIsRefusedInsteadOfIgnored(): void
    {
        $output = $this->collector();

        $exit = (new AttachCommand())->run(['clickhouse:attach', '--dry-runn'], $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('unknown option "--dry-runn"', $output->text());
    }

    private function command(ClickHouseTransport $transport): AttachCommand
    {
        return new AttachCommand(client: new ClickHouseClient($transport));
    }

    private function database(string $name): string
    {
        $path = sys_get_temp_dir() . '/clickhouse-' . $name . '-' . bin2hex(random_bytes(4)) . '.db';
        file_put_contents($path, '');
        $this->created[] = $path;
        $this->useEnv('DB_' . strtoupper($name), $path);

        return $path;
    }

    private function useEnv(string $name, string $value): void
    {
        $this->previousEnv[$name] = getenv($name) ?: '';
        putenv($name . '=' . $value);
        Env::reset();
    }

    private function collector(): CommandCollector
    {
        return new CommandCollector();
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

/**
 * A CliOutput that keeps every line, so a test can assert on the report.
 */
final class CommandCollector implements CliOutput
{
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
}
