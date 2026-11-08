<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Tests;

use Modules\Clickhouse\Application\ClickHouseClient;
use Modules\Clickhouse\Contracts\ClickHouseTransport;
use Modules\Clickhouse\Domain\ClickHouseError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * ClickHouseClientTest — the statement layer, driven by a recording transport.
 *
 * The format is the assertion, not the plumbing: `JSONEachRow` is one object
 * per line, so the tests hand the client bodies that a whole-body
 * `json_decode()` would mishandle — two rows, a newline inside a string, an
 * empty body, and the JSON-array body that is a different format altogether.
 * No test needs a server, which is the point of the transport seam.
 */
final class ClickHouseClientTest extends TestCase
{
    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Clickhouse')) {
            $this->markTestSkipped('Clickhouse is not installed.');
        }
    }

    protected function tearDown(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Clickhouse')) {
            return;
        }
    }

    #[Test]
    public function selectReadsOneObjectPerLine(): void
    {
        $transport = $this->transport(["{\"n\":1}\n{\"n\":2}\n"]);
        $rows = (new ClickHouseClient($transport))->select('SELECT number AS n FROM numbers(2)');

        self::assertSame([['n' => 1], ['n' => 2]], $rows);
    }

    /**
     * JSONEachRow escapes a newline inside a value (`\n`), so splitting the
     * body on raw newlines keeps the row whole. A "smarter" parser that
     * re-encoded the body would lose it here.
     */
    #[Test]
    public function aNewlineInsideAStringStaysOneRow(): void
    {
        $transport = $this->transport(["{\"note\":\"line1\\nline2\"}\n"]);
        $rows = (new ClickHouseClient($transport))->select('SELECT note FROM t');

        self::assertCount(1, $rows);
        self::assertSame("line1\nline2", $rows[0]['note'] ?? null);
    }

    #[Test]
    public function anEmptyBodyIsZeroRows(): void
    {
        $transport = $this->transport(['']);

        self::assertSame([], (new ClickHouseClient($transport))->select('SELECT 1 WHERE 0'));
    }

    /**
     * A JSON array is `JSON`/`JSONCompact`, not JSONEachRow. Accepting it would
     * make the client's contract depend on the server's choice of format, and
     * a caller reading `$rows[0]` would then get the whole array.
     */
    #[Test]
    public function aJsonArrayBodyIsRefusedOutLoud(): void
    {
        $transport = $this->transport(['[{"n":1},{"n":2}]']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('select() needs JSONEachRow');

        (new ClickHouseClient($transport))->select('SELECT n FROM t');
    }

    #[Test]
    public function selectAppendsTheFormatOnlyWhenTheStatementHasNone(): void
    {
        $transport = $this->transport(["{\"n\":1}\n", "{\"n\":1}\n"]);
        $client = new ClickHouseClient($transport);

        $client->select('SELECT 1 AS n;');
        $client->select('SELECT 1 AS n FORMAT JSONEachRow');

        self::assertSame("SELECT 1 AS n\nFORMAT JSONEachRow", $transport->calls[0]['sql'] ?? null);
        self::assertSame('SELECT 1 AS n FORMAT JSONEachRow', $transport->calls[1]['sql'] ?? null);
    }

    #[Test]
    public function emitSendsTheStatementVerbatimAndReturnsTheBody(): void
    {
        $transport = $this->transport(['']);

        $body = (new ClickHouseClient($transport))->emit("CREATE DATABASE IF NOT EXISTS nativa_blog ENGINE = SQLite('/tmp/blog.db')");

        self::assertSame('', $body);
        self::assertSame(
            "CREATE DATABASE IF NOT EXISTS nativa_blog ENGINE = SQLite('/tmp/blog.db')",
            $transport->calls[0]['sql'] ?? null,
        );
    }

    #[Test]
    public function insertSendsOneJsonObjectPerRow(): void
    {
        $transport = $this->transport(['']);

        (new ClickHouseClient($transport))->insert('nativa_blog.article_views', [
            ['article_slug' => 'a"b', 'viewed_at' => '2026-10-28 10:00:00'],
            ['article_slug' => 'c', 'viewed_at' => '2026-10-28 10:00:01'],
        ]);

        $sql = (string) ($transport->calls[0]['sql'] ?? '');
        self::assertStringStartsWith("INSERT INTO nativa_blog.article_views FORMAT JSONEachRow\n", $sql);

        $lines = explode("\n", $sql);
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            /** @var array<string, string> $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $rows[] = $decoded;
        }

        self::assertCount(2, $rows);
        self::assertSame('a"b', $rows[0]['article_slug'] ?? null);
        self::assertSame('c', $rows[1]['article_slug'] ?? null);
    }

    #[Test]
    public function insertOfNoRowsMakesNoRequest(): void
    {
        $transport = $this->transport([]);

        self::assertSame('', (new ClickHouseClient($transport))->insert('t', []));
        self::assertSame([], $transport->calls);
    }

    #[Test]
    public function pingIsFalseOnATransportFailure(): void
    {
        $transport = $this->transport([ClickHouseError::fromTransport('Connection refused')]);

        self::assertFalse((new ClickHouseClient($transport))->ping());
    }

    #[Test]
    public function databasesAndTablesReadTheNameColumn(): void
    {
        $transport = $this->transport([
            "{\"name\":\"default\"}\n{\"name\":\"nativa_blog\"}\n",
            "{\"name\":\"article_views\"}\n",
            "{\"statement\":\"CREATE DATABASE nativa_blog\\nENGINE = SQLite('/srv/blog.db')\"}\n",
        ]);
        $client = new ClickHouseClient($transport);

        self::assertSame(['default', 'nativa_blog'], $client->databases());
        self::assertSame(['article_views'], $client->tables('nativa_blog'));
        self::assertStringContainsString(
            "ENGINE = SQLite('/srv/blog.db')",
            $client->createDatabase('nativa_blog'),
        );
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
