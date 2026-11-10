<?php

declare(strict_types = 1);

namespace Tests\Http;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ReadReplicaRoutingTest — the routing rules inside a real request.
 *
 * `DatabaseRoutingTest` proves the configuration seam and
 * `ReadWriteConnectionTest` proves the decorator in isolation. Neither says the
 * thing a deployment depends on: that an ordinary request, dispatched through
 * the Kernel the way every page is, reads the replica, writes the primary, and
 * stops reading the replica once it has written.
 *
 * The action below is the whole measurement. It reads `probe` twice with a
 * write in between, and the two files hold different rows, so each answer names
 * the database it came from:
 *
 *   before=replica          — the first read was routed away from the primary
 *   after=primary,written   — the write went to the primary, and the read after
 *                             it did too (sticky write, read-your-writes)
 *
 * Nothing here reaches into `ReadWriteConnection`: the action only knows
 * `ConnectionInterface`, which is what a repository knows. A regression in the
 * manager, the adapter or the request path fails here; a regression in the
 * selector's weighting is the isolated test's business.
 *
 * @internal
 *
 * @coversNothing
 */
final class ReadReplicaRoutingTest extends TestCase
{
    private string $tmpDir = '';

    /** @var array<string, string|false> */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/nativa-replica-request-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        foreach (['DB_USERS', 'DB_USERS_READ'] as $key) {
            $this->previous[$key] = getenv($key);
            putenv($key);
        }

        DatabaseConnectionManager::resetInstance();
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            if (false === $value) {
                putenv($key);

                continue;
            }

            putenv($key . '=' . $value);
        }

        DatabaseConnectionManager::resetInstance();

        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    #[Test]
    public function aRequestReadsTheReplicaThenStaysOnThePrimaryAfterItsWrite(): void
    {
        $this->seed($this->tmpDir . '/users.db', ['primary']);
        $this->seed($this->tmpDir . '/users-read.db', ['replica']);
        putenv('DB_USERS=' . $this->tmpDir . '/users.db');
        putenv('DB_USERS_READ=' . $this->tmpDir . '/users-read.db');

        $kernel = new Kernel($this->probeRouter(), false);
        $response = $kernel->handle(Request::create('GET', '/probe'));

        self::assertSame(200, $response->getStatusCode(), 'the request must reach the action, not a gate or a 500');
        self::assertSame(
            ['before' => ['replica'], 'after' => ['primary', 'written']],
            json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR),
            'a read before the write must come from the replica, and every read after it from the primary',
        );
    }

    #[Test]
    public function withoutReplicaConfigurationTheSameRequestStaysOnOneFile(): void
    {
        $this->seed($this->tmpDir . '/users.db', ['primary']);
        putenv('DB_USERS=' . $this->tmpDir . '/users.db');

        $kernel = new Kernel($this->probeRouter(), false);
        $response = $kernel->handle(Request::create('GET', '/probe'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['before' => ['primary'], 'after' => ['primary', 'written']],
            json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR),
            'an unset DB_USERS_READ must mean "no routing", not a replica that happens to be the primary',
        );
    }

    /**
     * A real Router, pointed at one entry whose action is this file's own.
     *
     * The action directory list is empty on purpose: discovery is not what is
     * being measured, and a discovery cache would make the test depend on the
     * operator's installed modules.
     */
    private function probeRouter(): Router
    {
        return Router::fromEntries([], [[
            'path'          => '/probe',
            'method'        => 'GET',
            'name'          => 'replica-probe',
            'handlerMethod' => 'handle',
            'action'        => ReplicaProbeAction::class,
        ]]);
    }

    /**
     * @param list<string> $values
     */
    private function seed(string $path, array $values): void
    {
        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('CREATE TABLE probe (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT NOT NULL)');

        foreach ($values as $value) {
            $pdo->exec('INSERT INTO probe (v) VALUES (' . $pdo->quote($value) . ')');
        }
    }
}

/**
 * Reads, writes, reads — and reports which file each answer came from.
 *
 * Deliberately typed against the contract only: the action is written the way
 * a repository is, so a routing failure cannot be hidden by the test reaching
 * for the decorator's internals.
 *
 * @internal
 */
final class ReplicaProbeAction
{
    /**
     * @return array{before: list<string>, after: list<string>}
     */
    public function handle(Request $request): array
    {
        $db = DatabaseConnectionManager::instance()->connect('users');

        $before = $this->values($db);

        $db->execute("INSERT INTO probe (v) VALUES ('written')");

        return ['before' => $before, 'after' => $this->values($db)];
    }

    /**
     * @return list<string>
     */
    private function values(ConnectionInterface $db): array
    {
        $values = [];

        foreach ($db->query('SELECT v FROM probe ORDER BY id')->fetchAll() as $row) {
            $value = is_array($row) ? ($row['v'] ?? null) : null;
            $values[] = is_scalar($value) ? (string) $value : '';
        }

        return $values;
    }
}
