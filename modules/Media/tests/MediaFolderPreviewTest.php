<?php

declare(strict_types = 1);

namespace Modules\Media\Tests;

use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Media\Application\Services\MediaService;
use Modules\Media\Domain\Model\MediaAsset;
use Modules\Media\Infrastructure\Persistence\Migrations\MediaDatabaseMigrator;
use Modules\Media\Infrastructure\Persistence\MediaFolderRepository;
use Modules\Media\Infrastructure\Persistence\MediaRepository;
use Modules\Media\Infrastructure\Storage\Providers\CloudinaryApi;
use Modules\Media\Infrastructure\Storage\Providers\LocalStorageProviderAdapter;
use Modules\Media\Interfaces\HTTP\MediaServices;
use Modules\Core\Infrastructure\Storage\LocalStorageProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MediaFolderPreviewTest — a folder tile shows one image from the folder.
 *
 * Both libraries render the same tile (`media-folder-tile.phtml`), so both
 * need the same two answers: which image stands for a folder, and what the
 * tile looks like when there is none. The local answer is a query; the
 * Cloudinary answer is one Search request per tile, whose expression is
 * asserted here without the network.
 *
 * ## The database
 *
 * A temp file through `DB_MEDIA`, schema built by the Media migrator itself.
 * The working tree's `storage/data/media.db` is never opened.
 */
final class MediaFolderPreviewTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Media')) {
            $this->markTestSkipped('Media is not installed — the folder tiles ship with this module.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-media-preview-' . bin2hex(random_bytes(6));
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
    public function theNewestImageInAFolderStandsForIt(): void
    {
        $pdo = DatabaseConnectionManager::instance()->connection('media');
        $repository = new MediaRepository($pdo);
        $repository->insert($this->asset('photos/old.png', 'photos', 'image/png'));
        $repository->insert($this->asset('photos/new.png', 'photos', 'image/png'));

        $pdo->exec("UPDATE assets SET created_at = '2020-01-01 00:00:00' WHERE path = 'photos/old.png'");
        $pdo->exec("UPDATE assets SET created_at = '2024-01-01 00:00:00' WHERE path = 'photos/new.png'");

        $previews = $this->service()->folderPreviews(['photos']);

        self::assertArrayHasKey('photos', $previews);
        self::assertSame('photos/new.png', $previews['photos']->path());
    }

    /**
     * A folder with no image has no preview, and neither does a folder that
     * was never asked about. The tile then keeps the folder glyph.
     */
    #[Test]
    public function aFolderWithoutAnImageHasNoPreview(): void
    {
        $repository = new MediaRepository(DatabaseConnectionManager::instance()->connection('media'));
        $repository->insert($this->asset('docs/manual.pdf', 'docs', 'application/pdf'));

        self::assertSame([], $this->service()->folderPreviews(['docs', 'missing']));
    }

    /**
     * A remote upload is filed where the provider put it, so the local
     * library shows the same tree the CDN does. A path at the root has no
     * folder, and a traversal never becomes one.
     */
    #[Test]
    public function aStoredPathIsFiledUnderItsFolder(): void
    {
        self::assertSame('nativa-media', MediaService::folderFromPath('nativa-media/abc.png'));
        self::assertSame('a/b', MediaService::folderFromPath('a/b/c.png'));
        self::assertSame('', MediaService::folderFromPath('x.png'));
        self::assertSame('', MediaService::folderFromPath('../etc/passwd'));
    }

    /**
     * The transport is bounded: a page asks for at most a batch of previews
     * at once, so a hundred folders cannot open a hundred sockets from one
     * request. Keys survive the split, because the preview map is keyed by
     * folder path.
     */
    #[Test]
    public function theFetchIsSplitIntoBoundedBatches(): void
    {
        $urls = [];
        for ($i = 1; $i <= 8; ++$i) {
            $urls['folder-' . $i] = 'https://api.test/' . $i;
        }

        $batches = CloudinaryApi::chunkRequests($urls, 6);

        self::assertCount(2, $batches);
        self::assertSame(['folder-1', 'folder-2', 'folder-3', 'folder-4', 'folder-5', 'folder-6'], array_keys($batches[0]));
        self::assertSame(['folder-7', 'folder-8'], array_keys($batches[1]));
        self::assertSame('https://api.test/7', $batches[1]['folder-7']);
    }

    /**
     * A bound of zero would mean "ask for nothing", which is never what a
     * caller wants: it degrades to one URL at a time instead.
     */
    #[Test]
    public function aBoundOfZeroAsksForOneUrlAtATime(): void
    {
        $batches = CloudinaryApi::chunkRequests(['a' => 'u1', 'b' => 'u2'], 0);

        self::assertSame([['a' => 'u1'], ['b' => 'u2']], $batches);
    }

    /**
     * One request per tile, because a page-wide search cannot answer per
     * folder: the expression scopes the search and `resource_type:image`
     * keeps a video out of a picture tile.
     */
    #[Test]
    public function oneRequestPerTileAsksForImagesOnly(): void
    {
        $url = (new CloudinaryApi('demo', 'key', 'secret'))->firstImageUrl('photos');

        self::assertStringContainsString('/v1_1/demo/resources/search', $url);
        self::assertStringContainsString('folder%3Aphotos', $url);
        self::assertStringContainsString('AND+resource_type%3Aimage', $url);
        self::assertStringContainsString('max_results=1', $url, 'One tile needs one image, not a page of them.');
    }

    /**
     * A tile is 34px, so the preview asks for a 68px square crop instead of
     * the original: no folder glance downloads a full-size asset. The format
     * and quality are the CDN's to choose, because `f_auto` is what turns a
     * png into the webp or avif the browser accepts.
     */
    #[Test]
    public function thePreviewIsACroppedSquare(): void
    {
        $api = new CloudinaryApi('demo', 'key', 'secret');

        self::assertSame(
            'https://res.cloudinary.com/demo/image/upload/c_fill,w_68,h_68,f_auto,q_auto/blog/photo.jpg',
            $api->thumbUrl('blog/photo.jpg'),
        );
        self::assertSame(
            'https://res.cloudinary.com/demo/image/upload/c_fill,w_200,h_200,f_auto,q_auto/blog/photo.jpg',
            $api->thumbUrl('blog/photo.jpg', 200),
        );
    }

    /**
     * The grid shows the same asset at 300px, so the size a caller asks for
     * reaches the URL instead of being silently the tile default.
     */
    #[Test]
    public function aCardAsksForItsOwnEdge(): void
    {
        $api = new CloudinaryApi('demo', 'key', 'secret');

        self::assertStringContainsString(
            '/c_fill,w_300,h_300,f_auto,q_auto/',
            $api->thumbUrl('blog/photo.jpg', 300),
        );
    }

    #[Test]
    public function theTileRendersThePreviewAndFallsBackToTheGlyph(): void
    {
        $vars = [
            'class'      => 'm-folder-card',
            'href'       => '/mark/media?folder=photos',
            'linkAttrs'  => 'hx-get="/mark/media?folder=photos"',
            'previewUrl' => '/storage/uploads/photos/cover.png',
            'icon'       => '<svg class="folder-glyph"></svg>',
            'name'       => 'photos',
            'count'      => '3',
        ];

        $html = ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-folder-tile.phtml', $vars);

        self::assertStringContainsString('<img class="m-folder-card__thumb" src="/storage/uploads/photos/cover.png"', $html);
        self::assertStringNotContainsString('folder-glyph', $html);
        self::assertStringContainsString('hx-get="/mark/media?folder=photos"', $html);
        self::assertStringContainsString('>3<', $html);

        $fallback = ViewRenderer::render(
            'Modules/Media/Interfaces/HTTP/templates/media-folder-tile.phtml',
            ['previewUrl' => ''] + $vars,
        );

        self::assertStringContainsString('folder-glyph', $fallback);
        self::assertStringNotContainsString('m-folder-card__thumb', $fallback);
        self::assertStringNotContainsString('<span class="m-badge m-badge-gray"></span>', $fallback);
    }

    private function service(): MediaService
    {
        $pdo = DatabaseConnectionManager::instance()->connection('media');

        return new MediaService(
            new MediaRepository($pdo),
            new LocalStorageProviderAdapter(new LocalStorageProvider()),
            new MediaFolderRepository($pdo),
        );
    }

    private function asset(string $path, string $folder, string $mimeType): MediaAsset
    {
        return MediaAsset::create(
            filename: basename($path),
            originalName: basename($path),
            path: $path,
            size: 128,
            mimeType: $mimeType,
            uploadedBy: 'test',
            provider: 'local',
            folder: $folder,
        );
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
