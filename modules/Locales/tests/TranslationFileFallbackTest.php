<?php

declare(strict_types = 1);

namespace Modules\Locales\Tests;

use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Locales\Application\Services\TranslationService;
use Modules\Locales\Domain\Model\Locale;
use Modules\Locales\Domain\Model\Translation;
use Modules\Locales\Infrastructure\Persistence\Repositories\TranslationRepository;
use Modules\Locales\Infrastructure\Services\JsonTranslationLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TranslationFileFallbackTest (Phase E, translation-file half) — the JSON file
 * is a real source, not a branch nobody has ever taken.
 *
 * The read chain is cache → database → `storage/locales/<locale>.json` →
 * default → raw key, and until this test it had no coverage at all: the file
 * branch was written, never exercised, and `storage/locales/` was empty on this
 * tree. What that means in practice is a tree whose `locales.db` was never
 * seeded (fresh install, restored database, a key shipped before its
 * migration) renders raw keys, and an operator cannot see why.
 *
 * The tests drive the real repository (in-memory SQLite, same DDL as the
 * migrator) and the real loader (a temp directory), so the chain is measured
 * rather than mocked. `export()` is the write direction: the admin makes the
 * file from the database, which is the only way the fallback source stays
 * maintainable instead of hand-edited. `list()` is the panel's answer: a key
 * that renders from the file must be visible, marked, and not editable in
 * place — editing it would create a row that silently shadows the file.
 *
 * The fixtures spell the tree's two real locales, `sk_SK` and `en_US`.
 * `'sk_SK'` is also the value of `ContentLocaleMapper::FALLBACK_DEFAULT`, and
 * this file first paid for that: it took `NameOwnershipContractTest`'s literal
 * ceiling from 526 to 553, because the gate counted every second spelling of an
 * owned value. The ceiling was not raised — the gate now skips locale-shaped
 * literals on the grounds that a locale code is data about a language rather
 * than a second name for a constant that happens to hold one
 * (`isLocaleCode()`), and the measured 132 places that rule removed included 84
 * in `SeedData`, which the gate's own message had been calling correct. The keys
 * and values here are still unowned on purpose: a fixture that re-spells a
 * constant's value is the duplicate that rule exists for.
 *
 * Like every module test this drives the INSTALLED copy and skips when the
 * module is not installed.
 */
final class TranslationFileFallbackTest extends TestCase
{
    private string $directory = '';

    private \PDO $pdo;

    private TranslationRepository $repository;

    private JsonTranslationLoader $loader;

    private TranslationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Locales')) {
            $this->markTestSkipped('Locales is not installed.');
        }

        $this->directory = sys_get_temp_dir() . '/nativa-locales-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o755, true);

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE translations (
                id VARCHAR(26) PRIMARY KEY,
                translation_key VARCHAR(200) NOT NULL,
                locale VARCHAR(10) NOT NULL,
                value TEXT NOT NULL DEFAULT '',
                group_name VARCHAR(50) DEFAULT 'general',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(translation_key, locale)
            )
            SQL);

        $this->repository = new TranslationRepository($this->pdo);
        $this->loader = new JsonTranslationLoader($this->directory);
        $this->service = new TranslationService($this->repository, $this->loader);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    #[Test]
    public function theDatabaseWinsOverTheFile(): void
    {
        $this->writeFile('sk_SK', ['nav.home' => 'Kotva z filu']);
        $this->seedRow('nav.home', 'sk_SK', 'Kotva z databázy');

        $this->assertSame(
            'Kotva z databázy',
            $this->service->get('nav.home', 'sk_SK'),
            'A file value shadowed the row an admin can edit in the panel.',
        );
    }

    #[Test]
    public function aRowTheDatabaseDoesNotHaveComesFromTheFile(): void
    {
        $this->writeFile('sk_SK', ['nav.home' => 'Kotva z filu']);

        $this->assertSame(
            'Kotva z filu',
            $this->service->get('nav.home', 'sk_SK'),
            'The file branch of the read chain returned nothing — a key with no row renders raw.',
        );
    }

    #[Test]
    public function anUnknownKeyFallsThroughToTheDefaultAndThenToItsKey(): void
    {
        $this->writeFile('sk_SK', ['nav.home' => 'Kotva z filu']);

        $this->assertSame('Fallback', $this->service->get('nav.missing', 'sk_SK', 'Fallback'));
        $this->assertSame('nav.missing', $this->service->get('nav.missing', 'sk_SK'));
    }

    #[Test]
    public function fileOnlyKeysAreListedWithTheFileSourceAndNoId(): void
    {
        $this->seedRow('nav.blog', 'sk_SK', 'Články');
        $this->writeFile('sk_SK', ['nav.blog' => 'Články z filu', 'forms.tagline' => 'Podnadpis z filu']);

        $listed = $this->service->list('sk_SK');

        $this->assertSame(['forms.tagline', 'nav.blog'], array_map(static fn ($row): string => $row->key, $listed));

        [$tagline, $blog] = $listed;

        $this->assertSame('file', $tagline->source);
        $this->assertSame('', $tagline->id, 'A file row with an id would make the panel offer an edit that writes a shadowing row.');
        $this->assertSame('forms', $tagline->group, 'A file key takes its group from the key prefix, like an import does.');
        $this->assertSame('Podnadpis z filu', $tagline->value);

        $this->assertSame('database', $blog->source, 'A key present in both sources must be listed once, as the database row.');
        $this->assertNotSame('', $blog->id);
    }

    #[Test]
    public function aKeyTheFileProvidesIsNotReportedAsMissing(): void
    {
        $this->seedRow('nav.blog', 'en_US', 'Články');
        $this->writeFile('sk_SK', ['nav.blog' => 'Články']);

        $this->assertSame(
            [],
            $this->service->getMissingKeys('sk_SK'),
            'The file renders this key, so reporting it as missing asks the operator to fill it twice.',
        );
    }

    #[Test]
    public function exportWritesTheLocalesRowsToItsFile(): void
    {
        $this->seedRow('nav.home', 'sk_SK', 'Kotva', 'nav');
        $this->seedRow('nav.cta', 'sk_SK', 'Začať', 'nav');
        $this->seedRow('nav.home', 'en_US', 'Heim', 'nav');

        $this->assertSame(2, $this->service->export('sk_SK'));

        $this->assertSame(
            ['nav.cta' => 'Začať', 'nav.home' => 'Kotva'],
            $this->loader->load('sk_SK'),
            'The exported file is the read chain\'s fallback source; it must hold exactly this locale\'s rows.',
        );
        $this->assertFalse($this->loader->exists('en_US'), 'Exporting one locale must not write another locale\'s file.');
    }

    #[Test]
    public function exportWritesNothingForALocaleWithoutRows(): void
    {
        $this->seedRow('nav.home', 'sk_SK', 'Kotva');

        $this->assertSame(0, $this->service->export('en_US'));
        $this->assertFalse(
            $this->loader->exists('en_US'),
            'An empty file would become the fallback that replaces a language with nothing.',
        );
    }

    #[Test]
    public function theAdminTemplateMarksFileRowsAndDropsTheirEditLink(): void
    {
        $this->writeFile('sk_SK', ['forms.tagline' => 'Podnadpis z filu']);
        $this->seedRow('nav.home', 'sk_SK', 'Kotva');

        $rows = $this->service->list('sk_SK');
        $html = '';

        foreach ($rows as $row) {
            $html .= ViewRenderer::render('Modules/Locales/Interfaces/HTTP/templates/locales-edit.phtml', [
                'url'        => static fn (string $name, array $params = []): string => '/mark/' . $name . '?' . http_build_query($params),
                'csrf'       => '',
                'iconEdit'   => 'pencil',
                'iconDelete' => 'bin',
                'id'         => $row->id,
                'key'        => htmlspecialchars($row->key, ENT_QUOTES),
                'rowGroup'   => htmlspecialchars($row->group, ENT_QUOTES),
                'value'      => htmlspecialchars($row->value, ENT_QUOTES),
                'source'     => $row->source,
            ]);
        }

        $this->assertStringContainsString('>file</span>', $html, 'A file-backed key is not marked in the panel.');
        $this->assertSame(
            1,
            substr_count($html, 'mark.locales.edit'),
            'Only the database row may be editable — a file row has no id to edit.',
        );
        $this->assertStringContainsString('Podnadpis z filu', $html, 'The value the site renders is not shown in the panel.');
    }

    /**
     * @param array<string, string> $translations
     */
    private function writeFile(string $locale, array $translations): void
    {
        $this->loader->save($locale, $translations);
    }

    private function seedRow(string $key, string $locale, string $value, string $group = 'nav'): void
    {
        $this->repository->save(Translation::create($key, Locale::fromString($locale), $value, $group));
    }
}
