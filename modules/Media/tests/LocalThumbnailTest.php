<?php

declare(strict_types = 1);

namespace Modules\Media\Tests;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Media\Application\Services\LocalThumbnail;
use Modules\Media\Domain\Model\MediaAsset;
use Modules\Media\Infrastructure\Persistence\Migrations\MediaDatabaseMigrator;
use Modules\Media\Interfaces\HTTP\MediaServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LocalThumbnailTest — a local upload gets one 300px square, and a tile loads
 * it instead of the original.
 *
 * The measured reason is in the change that added this: a local card used to
 * download `176a381bd55aa69adb4b7d9262c82ab8.jpg` at 299,130 B for a 150px
 * tile, while the same asset as a square is a few kilobytes. Cloudinary has
 * `c_fill,w_300` on delivery and a local file has nothing, so this is where
 * the local side gets its square.
 *
 * The generator needs ext-gd and the repository does not require it, so the
 * three generation tests skip when the extension is missing. The two fallback
 * tests do not: a tree without gd must still serve the original, and that is
 * the branch a production host without the extension runs.
 *
 * Files are written under `storage/uploads/tests/`, the same place
 * ServeUploadedFileTest uses, and every one of them is removed in tearDown.
 */
final class LocalThumbnailTest extends TestCase
{
    /** @var list<string> files this test wrote, removed in tearDown */
    private array $created = [];

    private string $tmpDir = '';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Media')) {
            $this->markTestSkipped('Media is not installed — the local squares ship with this module.');
        }

        MediaServices::reset();
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even after a skipped setUp(), so nothing
        // here assumes the module or the files exist.
        if (is_dir(APP_BASE_PATH . '/src/Modules/Media')) {
            foreach ($this->created as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            MediaServices::reset();

            if ('' !== $this->tmpDir) {
                putenv('DB_MEDIA');
                DatabaseConnectionManager::resetInstance();

                foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                    @unlink($file);
                }

                @rmdir($this->tmpDir);
            }
        }

        parent::tearDown();
    }

    /**
     * A 600x400 photo becomes one 300x300 square, filed under `thumbs/` with
     * the directory of its original.
     */
    #[Test]
    public function aWideImageGetsOneSquareBeyondTheEdge(): void
    {
        $this->requireGd();

        $relative = $this->writeSource(600, 400);

        $square = LocalThumbnail::write($relative);

        self::assertSame(LocalThumbnail::relativePath($relative), $square);
        self::assertStringStartsWith('thumbs/tests/', (string) $square);

        $size = $this->imageSize((string) $square);
        self::assertSame([300, 300], $size);
    }

    /**
     * A photo stays JPEG. Measured on the tree's own uploads, the same 400x400
     * JPEG cropped to 300x300 is about 152 kB as PNG against 106 kB for the
     * original, so writing every square as PNG would have made an existing
     * file bigger than the one it replaced.
     */
    #[Test]
    public function aPhotoStaysJpegAndGetsSmaller(): void
    {
        $this->requireGd();

        $relative = $this->writeSource(600, 400, 'jpg');
        $sourceBytes = (int) filesize($this->absolute($relative));

        $square = LocalThumbnail::write($relative);

        self::assertNotNull($square);
        self::assertStringEndsWith('.jpg', $square);
        self::assertLessThan($sourceBytes, (int) filesize($this->absolute($square)));
    }

    /**
     * A source smaller than the edge keeps its own short side: blowing a
     * 100px icon up to 300px would write a file carrying more bytes than the
     * image it came from.
     */
    #[Test]
    public function aSmallSourceKeepsItsOwnShortSide(): void
    {
        $this->requireGd();

        $relative = $this->writeSource(100, 80);

        $square = LocalThumbnail::write($relative);

        self::assertNotNull($square);
        self::assertSame([80, 80], $this->imageSize($square));
    }

    /**
     * The square belongs to the original: remove the row and its file goes
     * with it, so no file is left that nothing points at.
     */
    #[Test]
    public function theSquareIsForgottenWithItsOriginal(): void
    {
        $this->requireGd();

        $relative = $this->writeSource(200, 200);
        $square = LocalThumbnail::write($relative);

        self::assertNotNull($square);
        self::assertTrue(is_file($this->absolute($square)));

        LocalThumbnail::forget($relative);

        self::assertFalse(is_file($this->absolute($square)));
    }

    /**
     * A tree without gd, or an upload made before this existed, has no
     * square: the tile keeps the original it always served.
     */
    #[Test]
    public function aTileFallsBackToTheOriginalWithoutASquare(): void
    {
        $relative = 'tests/fallback-' . bin2hex(random_bytes(6)) . '.png';

        self::assertNull(LocalThumbnail::url($relative));
        self::assertSame(
            '/storage/uploads/' . $relative,
            MediaServices::previewUrl($this->asset($relative)),
        );
    }

    /**
     * With a square on disk the same call answers with it, which is the whole
     * point: the card downloads the 300px file and not the 299 kB original.
     */
    #[Test]
    public function aTileLoadsTheSquareWhenOneExists(): void
    {
        $relative = 'tests/with-square-' . bin2hex(random_bytes(6)) . '.png';
        $square = LocalThumbnail::relativePath($relative);

        AppPaths::instance()->ensureDir(dirname($this->absolute($square)));
        file_put_contents($this->absolute($square), 'square');
        $this->created[] = $this->absolute($square);

        self::assertSame(
            '/storage/uploads/' . $square,
            MediaServices::previewUrl($this->asset($relative)),
        );
    }

    /**
     * The square is written by the upload, not by a tile noticing the file is
     * large: a local upload is the only moment the bytes are already in hand.
     * This is the wiring the other tests take for granted.
     */
    #[Test]
    public function anUploadWritesItsSquare(): void
    {
        $this->requireGd();
        $this->useTempDatabase();

        $relative = $this->writeSource(600, 400);
        $absolute = $this->absolute($relative);
        $size = (int) filesize($absolute);

        $asset = MediaServices::media()->upload([
            'name' => 'photo.png',
            'tmp_name' => $absolute,
            'error' => UPLOAD_ERR_OK,
            'size' => $size,
            'type' => 'image/png',
        ], 'test', 'local', 'tests');

        $this->created[] = $this->absolute($asset->path());
        $this->created[] = $this->absolute(LocalThumbnail::relativePath($asset->path()));

        $square = LocalThumbnail::relativePath($asset->path());

        self::assertTrue(is_file($this->absolute($square)), 'The upload must write its square, not the tile.');
        self::assertSame(
            '/storage/uploads/' . $square,
            MediaServices::previewUrl($asset, 300),
            'A card must load the square the upload just wrote.',
        );
    }

    /**
     * The upload writes a row, so this test needs a media.db — a temp file
     * through DB_MEDIA, the pattern MediaFolderPreviewTest uses. The working
     * tree's storage/data/media.db is never opened.
     */
    private function useTempDatabase(): void
    {
        if ('' !== $this->tmpDir) {
            return;
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-media-thumb-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_MEDIA=' . $this->tmpDir . '/media.db');
        DatabaseConnectionManager::resetInstance();
        MediaServices::reset();

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/media.db');
        (new MediaDatabaseMigrator($pdo))->run(new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        });
    }

    private function requireGd(): void
    {
        if (!LocalThumbnail::available()) {
            $this->markTestSkipped('ext-gd is not loaded — the squares are an optimisation, not a requirement.');
        }
    }

    /**
     * Write a solid image into storage/uploads/tests and register both the
     * file and the square it will get.
     */
    private function writeSource(int $width, int $height, string $extension = 'png'): string
    {
        $relative = 'tests/source-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $absolute = $this->absolute($relative);

        AppPaths::instance()->ensureDir(dirname($absolute));

        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 40, 90, 160));

        if ('jpg' === $extension) {
            imagejpeg($image, $absolute, 85);
        } else {
            imagepng($image, $absolute);
        }

        imagedestroy($image);

        $this->created[] = $absolute;
        $this->created[] = $this->absolute(LocalThumbnail::relativePath($relative));

        return $relative;
    }

    /**
     * @return array{int, int} width, height
     */
    private function imageSize(string $relative): array
    {
        $size = getimagesize($this->absolute($relative));
        self::assertIsArray($size);

        return [$size[0], $size[1]];
    }

    private function absolute(string $relative): string
    {
        return AppPaths::instance()->storage('uploads/' . $relative);
    }

    private function asset(string $path): MediaAsset
    {
        return MediaAsset::create(
            filename: $path,
            originalName: 'photo.png',
            path: $path,
            size: 1000,
            mimeType: 'image/png',
            uploadedBy: 'test',
            provider: 'local',
            folder: '',
        );
    }
}
