<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Tests;

use Modules\Clickhouse\Application\Services\TypedTablePlanner;
use Modules\Clickhouse\Domain\SqliteAttachment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * TypedTablePlannerTest — the explicit DDL, and when there is none.
 *
 * Two halves matter. The golden string pins the format, because the statement
 * is the whole feature: a name or a path spelled differently is a table
 * ClickHouse refuses. The other half is the silence — a table whose columns are
 * only `INTEGER` and `TEXT` must produce no statement at all, because
 * inference already answers those correctly and an explicit definition would
 * be a guess nobody asked for.
 */
final class TypedTablePlannerTest extends TestCase
{
    private const MODULE = 'Clickhouse';

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the planner lives in that module.');
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
    }

    #[Test]
    public function aDatetimeColumnEarnsAnExplicitTableWithEveryColumn(): void
    {
        $path = $this->fixture('CREATE TABLE events (id INTEGER, viewed_at DATETIME)');
        $attachment = new SqliteAttachment('nativa_blog', $path, basename($path));

        $plan = (new TypedTablePlanner())->plan($attachment);

        // The explicit table is in the server's default database, not in the
        // attached one: an explicit definition there collides with the
        // implicit table (measured 26.11.1.973).
        self::assertSame([
            "CREATE TABLE IF NOT EXISTS nativa_blog__events (id Int64, viewed_at DateTime) ENGINE = SQLite('" . $path . "', 'events')",
        ], $plan['statements']);
        self::assertSame([
            '[CLICKHOUSE] typed nativa_blog.events.viewed_at as DateTime from TEXT — a value that does not parse is rejected when read (CANNOT_PARSE_DATETIME), not silently fixed',
        ], $plan['warnings']);
    }

    #[Test]
    public function aTableOfOnlyInferredTypesProducesNothing(): void
    {
        $path = $this->fixture('CREATE TABLE notes (id INTEGER, body TEXT)');
        $attachment = new SqliteAttachment('nativa_blog', $path, basename($path));

        $plan = (new TypedTablePlanner())->plan($attachment);

        self::assertSame([], $plan['statements'], 'Inference is the documented default; no guess without a declared type.');
        self::assertSame([], $plan['warnings']);
    }

    #[Test]
    public function theWarningCountMatchesTheExplicitlyTypedColumns(): void
    {
        $path = $this->fixture(
            'CREATE TABLE events (uid UUID, price DECIMAL(10,2), id INTEGER, body TEXT)',
        );
        $attachment = new SqliteAttachment('nativa_blog', $path, basename($path));

        $plan = (new TypedTablePlanner())->plan($attachment);

        self::assertCount(2, $plan['warnings']);
        self::assertStringContainsString('uid as UUID', $plan['warnings'][0] ?? '');
        self::assertStringContainsString('price as Decimal(10,2)', $plan['warnings'][1] ?? '');
        self::assertStringContainsString('uid UUID, price Decimal(10,2), id Int64, body String', $plan['statements'][0] ?? '');
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS nativa_blog__events', $plan['statements'][0] ?? '');
    }

    #[Test]
    public function aFixedStringDeclarationIsQuotedAsAFixedString(): void
    {
        $path = $this->fixture('CREATE TABLE tokens (token FIXEDSTRING(36))');
        $attachment = new SqliteAttachment('nativa_users', $path, basename($path));

        $plan = (new TypedTablePlanner())->plan($attachment);

        self::assertStringContainsString('token FixedString(36)', $plan['statements'][0] ?? '');
        self::assertStringContainsString('nativa_users__tokens', $plan['statements'][0] ?? '');
    }

    private function fixture(string ...$statements): string
    {
        $path = sys_get_temp_dir() . '/clickhouse-typed-' . bin2hex(random_bytes(4)) . '.db';
        $this->created[] = $path;

        $pdo = new PDO('sqlite:' . $path);
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        return $path;
    }
}
