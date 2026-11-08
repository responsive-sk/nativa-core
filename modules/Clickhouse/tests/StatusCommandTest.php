<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Tests;

use Modules\Clickhouse\Application\ClickHouseClient;
use Modules\Clickhouse\Contracts\ClickHouseTransport;
use Modules\Clickhouse\Domain\ClickHouseError;
use Modules\Clickhouse\Interfaces\CLI\Command\StatusCommand;
use Modules\Clickhouse\Interfaces\HTTP\ClickhouseServices;
use Modules\Core\Infrastructure\Env;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\CLI\CliOutput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * StatusCommandTest — clean, drifted and unreachable, each on its own exit code.
 *
 * The stub answers the way a server would: the database list comes from
 * `system.databases`, the path from `SHOW CREATE DATABASE`, the table count
 * from `SHOW TABLES`. So the two facts this command exists to check — the
 * server's path and its reachability — are the two the test varies.
 */
final class StatusCommandTest extends TestCase
{
    private const MODULE = 'Clickhouse';

    /** @var list<string> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the command lives in that module.');
        }

        $this->useEnv('CLICKHOUSE_URL', 'http://localhost:8123');
    }

    protected function tearDown(): void
    {
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
    public function aServerThatAgreesReportsNoDriftAndExitsZero(): void
    {
        $stub = $this->server(true, $this->attachedNames());
        $output = new StatusCollector();

        $exit = $this->command($stub)->run(['clickhouse:status'], $output);

        $this->assertSame(0, $exit, implode("\n", $output->lines));
        $this->assertStringContainsString('tables', implode("\n", $output->lines));
        $this->assertStringContainsString('No drift.', implode("\n", $output->lines));
    }

    #[Test]
    public function aServerThatReadsADifferentPathIsDriftAndExitsOne(): void
    {
        $names = $this->attachedNames();
        $stub = $this->server(true, $names, ['nativa_blog' => '/moved/blog.db']);
        $output = new StatusCollector();

        $exit = $this->command($stub)->run(['clickhouse:status'], $output);

        $text = implode("\n", $output->lines);
        $this->assertSame(1, $exit, $text);
        $this->assertStringContainsString('drift: nativa_blog', $text);
        $this->assertStringContainsString('/moved/blog.db', $text);
    }

    #[Test]
    public function anUnreachableServerExitsOneBeforeListingAnything(): void
    {
        $stub = $this->server(false, []);
        $output = new StatusCollector();

        $exit = $this->command($stub)->run(['clickhouse:status'], $output);

        $text = implode("\n", $output->lines);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('ClickHouse is not reachable at http://localhost:8123', $text);
    }

    /**
     * Every kernel database this checkout has, prefixed the way attach names it.
     *
     * @return list<string>
     */
    private function attachedNames(): array
    {
        $files = glob(AppPaths::instance()->data('*.db')) ?: [];
        $prefix = 'nativa_';

        return array_values(array_map(
            static fn (string $file): string => $prefix . basename($file, '.db'),
            $files,
        ));
    }

    private function command(ClickHouseTransport $transport): StatusCommand
    {
        return new StatusCommand(client: new ClickHouseClient($transport));
    }

    private function useEnv(string $name, string $value): void
    {
        $this->previousEnv[$name] = getenv($name) ?: '';
        putenv($name . '=' . $value);
        Env::reset();
    }
    /**
     * A server-shaped stub: three query families, and a path map for drift.
     *
     * Anonymous on purpose: PHPUnit loads this file in a kernel-only checkout
     * where the module is absent, and a file-scope `implements
     * ClickHouseTransport` would fail at class declaration before setUp() could
     * skip it.
     *
     * @param list<string>          $databases what system.databases answers
     * @param array<string, string> $paths     database → path the server claims
     */
    private function server(bool $reachable, array $databases, array $paths = []): ClickHouseTransport
    {
        return new class($reachable, $databases, $paths) implements ClickHouseTransport {
            /**
             * @param list<string>          $databases
             * @param array<string, string> $paths
             */
            public function __construct(
                private readonly bool $reachable,
                private readonly array $databases,
                private readonly array $paths = [],
            ) {
            }

            public function execute(string $sql, string $database = ''): string
            {
                if (!$this->reachable) {
                    throw ClickHouseError::fromTransport('Connection refused');
                }

                if (str_contains($sql, 'system.databases')) {
                    return implode('', array_map(
                        static fn (string $name): string => json_encode(['name' => $name], JSON_THROW_ON_ERROR) . "\n",
                        $this->databases,
                    ));
                }

                if (1 === preg_match('/SHOW CREATE DATABASE (\S+)/i', $sql, $matches)) {
                    $name = $matches[1];
                    $path = $this->paths[$name] ?? $this->localPath($name);

                    return json_encode(
                        ['statement' => 'CREATE DATABASE ' . $name . "\nENGINE = SQLite('" . $path . "')"],
                        JSON_THROW_ON_ERROR,
                    ) . "\n";
                }

                if (str_starts_with($sql, 'SHOW TABLES')) {
                    return "{\"name\":\"events\"}\n";
                }

                return '{"1":1}' . "\n";
            }

            private function localPath(string $database): string
            {
                return AppPaths::instance()->database(substr($database, strlen('nativa_')));
            }
        };
    }

}

/**
 * A CliOutput that keeps every line.
 */
final class StatusCollector implements CliOutput
{
    /** @var list<string> */
    public array $lines = [];

    public function writeln(string $message): void
    {
        $this->lines[] = $message;
    }
}
