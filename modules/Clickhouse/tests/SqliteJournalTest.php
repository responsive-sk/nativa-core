<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Tests;

use Modules\Clickhouse\Application\Services\SqliteJournal;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SqliteJournalTest — the checkpoint path, measured rather than assumed.
 *
 * `--checkpoint` is the only place this tree writes anything to an operator's
 * SQLite file, and the first shape it had could not work: a read-only handle
 * cannot truncate the WAL (`disk I/O error`), so the flag threw exactly when
 * there was something to fold in. This test holds a live WAL open the way the
 * kernel does and asserts the flag really truncates it, because a checkpoint
 * that silently stopped running would leave every copy of the file missing
 * rows and nothing else would notice.
 */
final class SqliteJournalTest extends TestCase
{
    private const string MODULE = 'Clickhouse';

    private string $path = '';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the journal lives in that module.');
        }

        $this->path = sys_get_temp_dir() . '/clickhouse-journal-' . bin2hex(random_bytes(4)) . '.db';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->path . $suffix)) {
                unlink($this->path . $suffix);
            }
        }
    }

    #[Test]
    public function aCheckpointTruncatesTheWalWhileTheWriterStillHoldsIt(): void
    {
        $writer = $this->walWriter();

        $wal = $this->path . '-wal';
        clearstatcache();
        $this->assertFileExists($wal);
        $this->assertGreaterThan(0, (int) filesize($wal));

        $this->assertTrue(SqliteJournal::checkpoint($this->path));

        clearstatcache();
        $this->assertSame(0, (int) filesize($wal));
        $this->assertSame(
            2,
            (int) $writer->query('SELECT count(*) FROM journal_rows')->fetchColumn(),
            'The rows the WAL held must be in the database file after the checkpoint.',
        );
    }

    #[Test]
    public function aMissingFileIsReportedAsNotCheckpointed(): void
    {
        $this->assertFalse(SqliteJournal::checkpoint($this->path));
    }

    /**
     * The kernel's shape: WAL on, autocheckpoint off, two commits that only
     * exist in `<file>-wal` while this handle stays open.
     */
    private function walWriter(): PDO
    {
        $writer = new PDO('sqlite:' . $this->path);
        $writer->exec('CREATE TABLE journal_rows (n INTEGER)');
        $writer->exec('PRAGMA journal_mode=WAL');
        $writer->exec('PRAGMA wal_autocheckpoint=0');
        $writer->exec('INSERT INTO journal_rows VALUES (1)');
        $writer->exec('INSERT INTO journal_rows VALUES (2)');

        return $writer;
    }
}
