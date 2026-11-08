<?php

declare(strict_types = 1);

namespace Modules\Health\Tests;

use Modules\Health\Interfaces\HTTP\HealthServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * HealthServicesTest — the one part of the module that had to guess: which
 * databases exist, and which `DB_*` variables are module databases.
 *
 * Both guesses are cheap to get wrong in the direction that matters. Deriving a
 * filename from a module name probes a file that does not exist (`Settings`
 * owns `config.db`); treating every `DB_*` variable as a module probes a file
 * that is not a database (`DB_HOST=db.internal`). The second one is pinned
 * here, because it turns another tool's environment into a 503 on a site that
 * serves every page.
 */
final class HealthServicesTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Health')) {
            self::markTestSkipped('Health is not installed in this tree.');
        }

        $this->tempDir = sys_get_temp_dir() . '/health-services-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        putenv('DB_OTHER');
        putenv('DB_FOREIGN');

        // PHPUnit runs tearDown() even after a skipped setUp(), so on a
        // kernel-only tree (CI) this file used to error on an uninitialised
        // typed property instead of skipping. Same guard as the checks test.
        if (!isset($this->tempDir)) {
            parent::tearDown();

            return;
        }

        if (is_file($this->tempDir . '/other.db')) {
            unlink($this->tempDir . '/other.db');
        }

        if (is_file($this->tempDir . '/config.json')) {
            unlink($this->tempDir . '/config.json');
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function anOverrideCountsOnlyWhenItNamesASqliteFile(): void
    {
        new \PDO('sqlite:' . $this->tempDir . '/other.db');
        file_put_contents($this->tempDir . '/config.json', '{}');

        putenv('DB_OTHER=' . $this->tempDir . '/other.db');
        putenv('DB_FOREIGN=' . $this->tempDir . '/config.json');

        try {
            $names = HealthServices::databaseNames();
        } finally {
            putenv('DB_OTHER');
            putenv('DB_FOREIGN');
        }

        self::assertContains('other', $names, 'A relocated SQLite file is a module database.');
        self::assertNotContains('foreign', $names, 'A .json file another tool points at is not.');
    }

    #[Test]
    public function theRegisteredProbesAreNamedAndOrdered(): void
    {
        self::assertSame(
            ['database', 'filesystem', 'dist'],
            array_map(static fn ($check): string => $check->getName(), HealthServices::checks()),
        );
    }
}
