<?php

declare(strict_types = 1);

namespace Modules\Uploads\Tests;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Uploads\Infrastructure\Storage\LocalStorageProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LocalStorageProviderTest — the upload store's server-side boundary.
 *
 * The provider is not a route: it is the validation and path rule the upload
 * actions share. These tests keep the three decisions at that boundary visible:
 * a stored path becomes a public URL, a file is servable only when the detected
 * MIME matches its extension, and a relative path cannot leave the upload root.
 */
final class LocalStorageProviderTest extends TestCase
{
    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Uploads')) {
            $this->markTestSkipped('Uploads is not installed.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function aStoredPathBecomesThePublicUploadsUrl(): void
    {
        $relative = 'tests/upload-' . bin2hex(random_bytes(8)) . '.png';
        $full = AppPaths::instance()->storage('uploads/' . $relative);
        AppPaths::instance()->ensureDir(dirname($full));
        file_put_contents($full, self::png());
        $this->created[] = $full;

        $provider = new LocalStorageProvider();

        $base = $provider->url('');
        $this->assertStringStartsWith('/', $base);
        $this->assertSame($base . $relative, $provider->url($relative));
        $this->assertTrue($provider->isAllowed($relative));
    }

    #[Test]
    public function anExtensionThatDoesNotMatchTheDetectedMimeIsNotServable(): void
    {
        $relative = 'tests/upload-' . bin2hex(random_bytes(8)) . '.jpg';
        $full = AppPaths::instance()->storage('uploads/' . $relative);
        AppPaths::instance()->ensureDir(dirname($full));
        file_put_contents($full, self::png());
        $this->created[] = $full;

        $provider = new LocalStorageProvider();

        $this->assertFalse($provider->isAllowed($relative), 'A PNG renamed to .jpg must not be served.');
    }

    #[Test]
    public function aPathCannotLeaveTheUploadRoot(): void
    {
        $provider = new LocalStorageProvider();

        $this->assertFalse($provider->isAllowed('../config.php'));
        $this->assertFalse($provider->exists('../config.php'));
        $this->assertFalse($provider->delete('../config.php'));
    }

    private static function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=',
            true,
        );
    }
}
