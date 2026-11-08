<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Tests;

use Modules\Clickhouse\Application\ClickHouseClient;
use Modules\Clickhouse\Contracts\ClickHouseTransport;
use Modules\Clickhouse\Domain\ClickHouseError;
use Modules\Clickhouse\Interfaces\CLI\Command\QueryCommand;
use Modules\Clickhouse\Interfaces\HTTP\ClickhouseServices;
use Modules\Core\Interfaces\CLI\CliOutput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * QueryCommandTest — the read gate, the write warning, the formats, the errors.
 *
 * Decision 5 is a refusal, not a convention: an `INSERT` without `--write` must
 * not reach the server, because reaching it would already have been the second
 * journal owner on a file the kernel is writing. The test asserts the call list
 * as well as the exit code, so "refused" cannot be confused with "sent and
 * failed".
 */
final class QueryCommandTest extends TestCase
{
    private const MODULE = 'Clickhouse';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the command lives in that module.');
        }
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even when setUp() skipped, so in a
        // kernel-only checkout this class does not exist yet.
        if (class_exists(ClickhouseServices::class)) {
            ClickhouseServices::reset();
        }
    }

    #[Test]
    public function aSelectRunsAndItsRowsArePrinted(): void
    {
        $transport = $this->transport(["{\"n\":1}\n{\"n\":2}\n"]);
        $output = new QueryCollector();

        $exit = $this->command($transport)->run(['clickhouse:query', 'SELECT number AS n FROM numbers(2)'], $output);

        $this->assertSame(0, $exit, implode("\n", $output->lines));
        $this->assertSame(['{"n":1}', '{"n":2}'], $output->lines);
        $this->assertStringContainsString('FORMAT JSONEachRow', $transport->calls[0]['sql']);
    }

    #[Test]
    public function aWriteWithoutTheFlagNeverReachesTheServer(): void
    {
        $transport = $this->transport();
        $output = new QueryCollector();

        $exit = $this->command($transport)->run(['clickhouse:query', 'INSERT INTO t VALUES (1)'], $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('needs --write', implode("\n", $output->lines));
        $this->assertSame([], $transport->calls, 'A refused write must not be sent.');
    }

    #[Test]
    public function aWriteWithTheFlagWarnsAndRuns(): void
    {
        $transport = $this->transport(['']);
        $output = new QueryCollector();

        $exit = $this->command($transport)->run(
            ['clickhouse:query', 'INSERT INTO nativa_blog.article_views (article_slug) VALUES (\'x\')', '--write'],
            $output,
        );

        $text = implode("\n", $output->lines);
        $this->assertSame(0, $exit, $text);
        $this->assertStringContainsString('WARNING: a write locks the whole SQLite file', $text);
        $this->assertStringContainsString('INSERT INTO nativa_blog.article_views', $transport->calls[0]['sql']);
    }

    #[Test]
    public function tsvPrintsTheBodiesLinesVerbatim(): void
    {
        $transport = $this->transport(["1\tone\n2\ttwo\n"]);
        $output = new QueryCollector();

        $exit = $this->command($transport)->run(
            ['clickhouse:query', 'SELECT n, s FROM t', '--format=TSV'],
            $output,
        );

        $this->assertSame(0, $exit, implode("\n", $output->lines));
        $this->assertSame(["1\tone", "2\ttwo"], $output->lines);
        $this->assertStringContainsString('FORMAT TSV', $transport->calls[0]['sql']);
    }

    #[Test]
    public function theDatabaseFlagBecomesTheRequestParameterNotSql(): void
    {
        $transport = $this->transport(["{\"n\":1}\n"]);
        $output = new QueryCollector();

        $exit = $this->command($transport)->run(
            ['clickhouse:query', 'SELECT 1 AS n', '--db', 'nativa_blog'],
            $output,
        );

        $this->assertSame(0, $exit, implode("\n", $output->lines));
        $this->assertSame('nativa_blog', $transport->calls[0]['database']);
        $this->assertStringNotContainsString('nativa_blog', $transport->calls[0]['sql']);
    }

    #[Test]
    public function aServerErrorPrintsTheSingleLineAndExitsOne(): void
    {
        $transport = $this->transport([
            ClickHouseError::fromResponse("Code: 60. DB::Exception: Table default.x doesn't exist.\n"),
        ]);
        $output = new QueryCollector();

        $exit = $this->command($transport)->run(['clickhouse:query', 'SELECT * FROM x'], $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("ClickHouse error 60 (DB::Exception): Table default.x doesn't exist.", implode("\n", $output->lines));
    }

    #[Test]
    public function anUnknownFormatIsRefused(): void
    {
        $output = new QueryCollector();

        $exit = $this->command($this->transport())->run(
            ['clickhouse:query', 'SELECT 1', '--format=csv'],
            $output,
        );

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('unknown format "csv"', implode("\n", $output->lines));
    }

    #[Test]
    public function twoStatementsInOneRunAreRefused(): void
    {
        $output = new QueryCollector();

        $exit = $this->command($this->transport())->run(
            ['clickhouse:query', 'SELECT 1', 'SELECT 2'],
            $output,
        );

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('exactly one statement', implode("\n", $output->lines));
    }

    private function command(ClickHouseTransport $transport): QueryCommand
    {
        return new QueryCommand(new ClickHouseClient($transport));
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
 * A CliOutput that keeps every printed line, so a test can assert on the output.
 */
final class QueryCollector implements CliOutput
{
    /** @var list<string> */
    public array $lines = [];

    public function writeln(string $message): void
    {
        $this->lines[] = $message;
    }
}
