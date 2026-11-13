<?php

declare(strict_types = 1);

namespace Modules\Blog\Tests;

use Modules\Blog\Application\Services\AuthorAvatar;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Uploads\Infrastructure\Storage\LocalStorageProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AuthorAvatarUrlTest — the stored path is not the URL.
 *
 * Users store an avatar as `2026/10/09/<hex>.jpg` under storage/uploads. A
 * theme that receives that raw path renders `src="2026/10/09/…"`, which the
 * browser resolves against the current page: on `/authors/<slug>` it becomes
 * `/authors/2026/10/09/…` and 404s. Every surface therefore goes through
 * `AuthorAvatar::url()`, and a missing file has no URL at all (the caller
 * renders the initial-letter placeholder instead of a broken image).
 */
final class AuthorAvatarUrlTest extends TestCase
{
    /** @var list<string> files this test created, removed in tearDown */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Blog')) {
            $this->markTestSkipped('Blog is not installed.');
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
    public function aStoredAvatarBecomesAPublicStorageUrl(): void
    {
        $relative = 'tests/avatar-' . bin2hex(random_bytes(8)) . '.jpg';
        $full = AppPaths::instance()->storage('uploads/' . $relative);
        AppPaths::instance()->ensureDir(dirname($full));
        file_put_contents($full, 'avatar');
        $this->created[] = $full;

        $avatar = new AuthorAvatar(new LocalStorageProvider());

        $this->assertSame('/storage/uploads/' . $relative, $avatar->url($relative));
    }

    #[Test]
    public function aMediaLibraryUrlIsAcceptedWhenItsFileExists(): void
    {
        $relative = 'tests/avatar-' . bin2hex(random_bytes(8)) . '.jpg';
        $full = AppPaths::instance()->storage('uploads/' . $relative);
        AppPaths::instance()->ensureDir(dirname($full));
        file_put_contents($full, 'avatar');
        $this->created[] = $full;

        $avatar = new AuthorAvatar(new LocalStorageProvider());

        $this->assertSame('/storage/uploads/' . $relative, $avatar->url('/storage/uploads/' . $relative));
    }

    #[Test]
    public function aCdnUrlIsAccepted(): void
    {
        $avatar = new AuthorAvatar(new LocalStorageProvider());

        $this->assertSame(
            'https://res.cloudinary.com/epithemic/image/upload/avatar.jpg',
            $avatar->url('https://res.cloudinary.com/epithemic/image/upload/avatar.jpg'),
        );
    }

    #[Test]
    public function aMissingAvatarHasNoUrl(): void
    {
        $avatar = new AuthorAvatar(new LocalStorageProvider());

        $this->assertNull($avatar->url('tests/missing-' . bin2hex(random_bytes(8)) . '.jpg'));
        $this->assertNull($avatar->url('/storage/uploads/tests/missing-' . bin2hex(random_bytes(8)) . '.jpg'));
        $this->assertNull($avatar->url(null));
        $this->assertNull($avatar->url(''));
    }
}
