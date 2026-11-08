<?php

declare(strict_types = 1);

namespace Modules\Media\Tests;

use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Media\Application\Services\MediaService;
use Modules\Media\Domain\Model\MediaAsset;
use Modules\Media\Infrastructure\Persistence\Migrations\MediaDatabaseMigrator;
use Modules\Media\Infrastructure\Persistence\MediaFolderRepository;
use Modules\Media\Infrastructure\Persistence\MediaRepository;
use Modules\Media\Infrastructure\Storage\Providers\MediaProviderInterface;
use Modules\Media\Interfaces\HTTP\MediaCloudinarySelection;
use Modules\Media\Interfaces\HTTP\MediaServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MediaCloudinaryDeleteTest — the library's Delete selected path.
 *
 * The browse page shows the CDN's own answers, so a deleted id may or may not
 * have a tracked row. The rule this pins is that the row is forgotten only
 * after the remote destroy succeeded: a failed destroy must leave the row (and
 * its still-reachable file) alone.
 *
 * The action itself is not driven here: it logs through AuditServices, which
 * opens the real audit and users databases. What is asserted is the part with
 * the behaviour, the service, plus the markup that offers the button.
 *
 * ## The database
 *
 * A temp file through `DB_MEDIA`, schema built by the Media migrator itself.
 * The working tree's `storage/data/media.db` is never opened.
 */
final class MediaCloudinaryDeleteTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Media')) {
            $this->markTestSkipped('Media is not installed — the Cloudinary library ships with this module.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-media-cloudinary-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_MEDIA=' . $this->tmpDir . '/media.db');
        DatabaseConnectionManager::resetInstance();
        MediaServices::reset();

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/media.db');
        (new MediaDatabaseMigrator($pdo))->run($this->quietOutput());
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even when setUp() skipped, so nothing here
        // assumes the module is installed or the tmp dir exists.
        if (is_dir(APP_BASE_PATH . '/src/Modules/Media')) {
            putenv('DB_MEDIA');
            DatabaseConnectionManager::resetInstance();
            MediaServices::reset();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function aDeletedRemoteFileTakesItsTrackedRowWithIt(): void
    {
        $repository = $this->repository();
        $repository->insert($this->asset('nativa-media/abc'));

        $provider = $this->provider();

        $counts = $this->service($provider)->deleteRemoteResources($provider, ['nativa-media/abc']);

        self::assertSame(['deleted' => 1, 'failed' => 0, 'rows' => 1], $counts);
        self::assertSame(['nativa-media/abc'], $provider->deleted);
        self::assertNull($repository->findByPath('nativa-media/abc'));
    }

    #[Test]
    public function aFailedRemoteDeleteKeepsTheTrackedRow(): void
    {
        $repository = $this->repository();
        $repository->insert($this->asset('nativa-media/kept'));

        $provider = $this->provider(['nativa-media/kept' => false]);

        $counts = $this->service($provider)->deleteRemoteResources($provider, ['nativa-media/kept']);

        self::assertSame(['deleted' => 0, 'failed' => 1, 'rows' => 0], $counts);
        self::assertNotNull($repository->findByPath('nativa-media/kept'), 'A destroy that answered false must not erase the row.');
    }

    /**
     * The page is the only place the button exists, so the markup is pinned:
     * a second submit that posts the same checked ids to the delete route.
     */
    #[Test]
    public function theLibraryOffersADeleteSelectedButtonNextToImport(): void
    {
        $html = ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-cloudinary.phtml', [
            'noticeHtml'    => '',
            'crumbsHtml'    => '',
            'foldersHtml'   => '',
            'currentFolder' => 'nativa-media',
            'summaryHtml'   => '',
            'cardsHtml'     => '<div class="m-media-grid"></div>',
            'csrf'          => '',
            'nextHref'      => '',
            'url'           => static fn (string $name, array $params = []): string => '/mark/media/cloudinary'
                . ('media.cloudinary.delete' === $name ? '/delete' : '')
                . ('media.cloudinary.import' === $name ? '/import' : ''),
        ]);

        self::assertStringContainsString('action="/mark/media/cloudinary/import"', $html);
        self::assertStringContainsString('formaction="/mark/media/cloudinary/delete"', $html);
        self::assertStringContainsString('data-confirm="Delete the selected files from Cloudinary? This cannot be undone."', $html);
    }

    /**
     * The ids the browser posts are shape-checked before they reach the CDN:
     * empty, traversal, control characters and duplicates never travel.
     */
    #[Test]
    public function theSelectionDropsJunkAndDuplicates(): void
    {
        $request = Request::create('POST', '/mark/media/cloudinary/delete', body: [
            'public_ids' => [
                'nativa-media/a',
                'nativa-media/a',
                '',
                '   ',
                '../etc/passwd',
                "nativa-media/\x01",
                42,
                'nativa-media/b',
            ],
        ]);

        self::assertSame(['nativa-media/a', 'nativa-media/b'], MediaCloudinarySelection::publicIds($request));
    }

    private function repository(): MediaRepository
    {
        return new MediaRepository(DatabaseConnectionManager::instance()->connection('media'));
    }

    private function service(MediaProviderInterface $provider): MediaService
    {
        $pdo = DatabaseConnectionManager::instance()->connection('media');

        return new MediaService(new MediaRepository($pdo), $provider, new MediaFolderRepository($pdo));
    }

    private function asset(string $path): MediaAsset
    {
        return MediaAsset::create(
            filename: basename($path),
            originalName: basename($path),
            path: $path,
            size: 12,
            mimeType: 'image/png',
            uploadedBy: 'test',
            provider: 'cloudinary',
        );
    }

    /**
     * A provider whose destroy answers what the test says, and remembers what
     * it was asked to delete.
     *
     * @param array<string, bool> $results
     */
    private function provider(array $results = []): MediaProviderInterface
    {
        return new class($results) implements MediaProviderInterface {
            /** @var list<string> */
            public array $deleted = [];

            /**
             * @param array<string, bool> $results
             */
            public function __construct(private readonly array $results)
            {
            }

            public function store(array $file): array
            {
                return ['path' => '', 'url' => '', 'size' => 0, 'mime_type' => ''];
            }

            public function delete(string $path): bool
            {
                $this->deleted[] = $path;

                return $this->results[$path] ?? true;
            }

            public function url(string $path): string
            {
                return $path;
            }

            public function exists(string $path): bool
            {
                return true;
            }

            public function getName(): string
            {
                return 'cloudinary';
            }
        };
    }

    private function quietOutput(): CliOutput
    {
        return new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
