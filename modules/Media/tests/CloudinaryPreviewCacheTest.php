<?php

declare(strict_types = 1);

namespace Modules\Media\Tests;

use Modules\Media\Application\Services\CloudinaryFolderPreviews;
use Modules\Media\Infrastructure\Cache\CloudinaryPreviewCache;
use Modules\Media\Infrastructure\Storage\Providers\CloudinaryApi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CloudinaryPreviewCacheTest — the second library render costs no request.
 *
 * The tiles ask the Search API one request per folder, and those answers only
 * change on a media write. The cache is what makes "open the library again"
 * a file read, including for the folders that have no image at all: an empty
 * folder remembered as '' is still an answer, and forgetting it would ask
 * again about every empty folder on every render.
 *
 * The clock is injected rather than slept through, so expiry is asserted
 * against the same TTL the code ships with.
 */
final class CloudinaryPreviewCacheTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Media')) {
            $this->markTestSkipped('Media is not installed — the folder tiles ship with this module.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-media-preview-cache-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even when setUp() skipped, so nothing here
        // assumes the module is installed or the tmp dir exists.
        if (is_dir(APP_BASE_PATH . '/src/Modules/Media')) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function theSecondRenderCostsNoRequest(): void
    {
        $calls = new \ArrayObject();
        $api = $this->api($calls, [
            'blog' => 'https://res.cloudinary.com/demo/image/upload/c_fill,w_68,h_68/blog/x',
        ]);
        $previews = $this->service();

        $first = $previews->previewsFor($api, ['blog', 'empty', 'missing']);

        self::assertSame([
            'blog'    => 'https://res.cloudinary.com/demo/image/upload/c_fill,w_68,h_68/blog/x',
            'empty'   => '',
            'missing' => '',
        ], $first);
        self::assertCount(1, $calls);
        self::assertSame(['blog', 'empty', 'missing'], $calls[0]);

        $second = $previews->previewsFor($api, ['blog', 'empty', 'missing']);

        self::assertSame($first, $second);
        self::assertCount(1, $calls, 'A second render of the same folders must not call the API.');

        // The tiles' order is the answer's order: a cold render must not lay
        // them out differently than a warm one just because a folder had no
        // image to return.
        self::assertSame(
            ['missing', 'blog', 'empty'],
            array_keys($previews->previewsFor($api, ['missing', 'blog', 'empty'])),
        );
    }

    #[Test]
    public function onlyTheFoldersWithoutAFreshEntryAreFetched(): void
    {
        $calls = new \ArrayObject();
        $api = $this->api($calls, ['a' => 'u-a', 'b' => 'u-b']);
        $previews = $this->service();

        $previews->previewsFor($api, ['a']);
        $result = $previews->previewsFor($api, ['a', 'b']);

        self::assertSame(['a' => 'u-a', 'b' => 'u-b'], $result);
        self::assertCount(2, $calls);
        self::assertSame(['b'], $calls[1], 'A cached folder must not travel again.');
    }

    #[Test]
    public function anEntryExpiresWithTheTtl(): void
    {
        $cache = new CloudinaryPreviewCache($this->tmpDir);
        $cache->remember(['blog' => 'u-blog', 'empty' => '']);

        $now = time();
        self::assertSame(
            ['blog' => 'u-blog', 'empty' => ''],
            $cache->fresh(['blog', 'empty'], $now + CloudinaryPreviewCache::TTL - 1),
        );
        self::assertSame([], $cache->fresh(['blog', 'empty'], $now + CloudinaryPreviewCache::TTL + 1));
    }

    #[Test]
    public function forgetDropsEveryEntry(): void
    {
        $cache = new CloudinaryPreviewCache($this->tmpDir);
        $cache->remember(['blog' => 'u-blog']);

        $cache->forget();

        self::assertSame([], $cache->fresh(['blog']));
        self::assertFileDoesNotExist($cache->file());
    }

    private function service(): CloudinaryFolderPreviews
    {
        return new CloudinaryFolderPreviews(new CloudinaryPreviewCache($this->tmpDir));
    }

    /**
     * A client whose preview call answers from a fixture and counts the
     * folders it was asked about.
     *
     * @param \ArrayObject<int, list<string>> $calls
     * @param array<string, string>           $previews
     */
    private function api(\ArrayObject $calls, array $previews): CloudinaryApi
    {
        return new readonly class($calls, $previews) extends CloudinaryApi {
            /**
             * @param \ArrayObject<int, list<string>> $calls
             * @param array<string, string>           $previews
             */
            public function __construct(
                private \ArrayObject $calls,
                private array $previews,
            ) {
                parent::__construct('demo', 'key', 'secret');
            }

            public function folderPreviews(array $childPaths): array
            {
                $this->calls->append($childPaths);

                return array_intersect_key($this->previews, array_fill_keys($childPaths, true));
            }
        };
    }
}
