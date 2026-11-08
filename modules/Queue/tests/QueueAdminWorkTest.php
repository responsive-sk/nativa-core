<?php

declare(strict_types = 1);

namespace Modules\Queue\Tests;

use Modules\Audit\Infrastructure\Persistence\Migrations\AuditDatabaseMigrator;
use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Queue\Infrastructure\Persistence\Migrations\QueueDatabaseMigrator;
use Modules\Queue\Infrastructure\Persistence\Statement;
use Modules\Queue\Interfaces\HTTP\Actions\QueueAdminAction;
use Modules\Queue\Interfaces\HTTP\QueueServices;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * QueueAdminWorkTest — the panel's "Process now" button, asserted where it
 * matters: the jobs actually run.
 *
 * This is the trigger for deployments with no cron, so it has three properties
 * worth pinning, and none of them is "the page renders":
 *
 *  - the batch runs the SAME seam `bin/cms queue:work` drives
 *    (`QueueServices::work()`), so the button cannot drift from the worker;
 *  - the result travels back as numbers, because that is what the list page
 *    reports and what an operator acts on;
 *  - the run is audited, since "who drained the queue" is a write action like
 *    any other in `/mark`.
 *
 * Temp databases, never the checkout's: the action flushes the page cache and
 * writes an audit row, and a test that touches those files changes the tree it
 * runs in.
 */
final class QueueAdminWorkTest extends TestCase
{
    private string $tmpDir = '';

    private string $marker = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Queue') || !is_dir(APP_BASE_PATH . '/src/Modules/Audit')) {
            $this->markTestSkipped('Queue and Audit must both be installed — the trigger is the panel surface.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-queue-work-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_QUEUE=' . $this->tmpDir . '/queue.db');
        putenv('DB_AUDIT=' . $this->tmpDir . '/audit.db');
        putenv('DB_USERS=' . $this->tmpDir . '/users.db');
        DatabaseConnectionManager::resetInstance();
        QueueServices::reset();
        AuditServices::reset();

        (new QueueDatabaseMigrator(new PDO('sqlite:' . $this->tmpDir . '/queue.db')))->run(self::quietOutput());
        (new AuditDatabaseMigrator(new PDO('sqlite:' . $this->tmpDir . '/audit.db')))->run(self::quietOutput());

        $this->marker = $this->tmpDir . '/ran.txt';
        $marker = $this->marker;

        QueueServices::handlers()->register('test.handler', static function (array $args) use ($marker): void {
            file_put_contents($marker, (string) ($args['n'] ?? 'ran'), FILE_APPEND);
        });
    }

    protected function tearDown(): void
    {
        if ('' === $this->tmpDir) {
            parent::tearDown();

            return;
        }

        putenv('DB_QUEUE');
        putenv('DB_AUDIT');
        putenv('DB_USERS');
        DatabaseConnectionManager::resetInstance();
        QueueServices::reset();
        AuditServices::reset();

        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    #[Test]
    public function theButtonRunsTheQueuedJobsAndReportsHowMany(): void
    {
        QueueServices::queue()->push('test.handler', ['n' => 'first']);
        QueueServices::queue()->push('test.handler', ['n' => 'second']);

        $response = (new QueueAdminAction(self::router()))->process(
            Request::create('POST', '/mark/queue/work'),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            '/mark/queue?worked=2&failed=0',
            $response->getHeader('Location'),
            'the list page reads these two numbers to say what the click did',
        );
        self::assertSame('firstsecond', (string) file_get_contents($this->marker));
        self::assertSame([], QueueServices::queue()->listJobs(), 'a processed job is deleted, not left reserved');
    }

    #[Test]
    public function anEmptyQueueAnswersZeroRatherThanFailing(): void
    {
        $response = (new QueueAdminAction(self::router()))->process(
            Request::create('POST', '/mark/queue/work'),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/mark/queue?worked=0&failed=0', $response->getHeader('Location'));
    }

    #[Test]
    public function aFailingJobIsCountedAndParkedInsteadOfStoppingTheBatch(): void
    {
        QueueServices::handlers()->register('bad.handler', static function (): void {
            throw new \RuntimeException('nope');
        });

        QueueServices::queue()->push('bad.handler', [], maxAttempts: 1);
        QueueServices::queue()->push('test.handler', ['n' => 'after']);

        $response = (new QueueAdminAction(self::router()))->process(
            Request::create('POST', '/mark/queue/work'),
        );

        self::assertSame('/mark/queue?worked=1&failed=1', $response->getHeader('Location'));
        self::assertSame('after', (string) file_get_contents($this->marker), 'the batch continues past a parked job');
        self::assertCount(1, QueueServices::queue()->listFailed());
    }

    /**
     * The run is a write action in the panel, so it leaves the same trace every
     * other one does — otherwise "who drained the queue at 03:00" has no answer.
     */
    #[Test]
    public function theRunIsAudited(): void
    {
        QueueServices::queue()->push('test.handler', []);

        (new QueueAdminAction(self::router()))->process(Request::create('POST', '/mark/queue/work'));

        $row = Statement::query(
            DatabaseConnectionManager::instance()->connection('audit'),
            "SELECT action, detail FROM audit_log WHERE action = 'queue.work'",
        )->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row, 'the panel run must leave an audit row');
        self::assertStringContainsString('Processed 1 job(s)', (string) $row['detail']);
    }

    private static function router(): RouterInterface
    {
        return new class () implements RouterInterface {
            public function match(Request $request): ?array
            {
                return null;
            }

            public function url(string $name, array $params = []): string
            {
                return 'mark.queue' === $name ? '/mark/queue' : '/route/' . $name;
            }
        };
    }

    private static function quietOutput(): CliOutput
    {
        return new class () implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
