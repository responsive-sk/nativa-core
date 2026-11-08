<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Interfaces\HTTP\Actions\UsersAction;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Paths\AppPaths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * UserAvatarSelectionTest — the avatar picked from the Media library.
 *
 * The user form no longer accepts a raw upload: the shared picker writes a
 * public URL into `avatar`, and this action validates it before it reaches
 * `users.avatar`. A local `/storage/uploads/...` URL must point at an allowed
 * file; a CDN URL is the Cloudinary provider's answer; anything else is
 * refused with the form-level message.
 */
final class UserAvatarSelectionTest extends TestCase
{
    /** @var list<string> files this test created, removed in tearDown */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Cardboard')) {
            $this->markTestSkipped('Cardboard is not installed — the user form ships with this module.');
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
    public function aMediaLibraryUrlIsAcceptedWhenTheFileIsAllowed(): void
    {
        $relative = 'tests/avatar-' . bin2hex(random_bytes(8)) . '.png';
        $full = AppPaths::instance()->storage('uploads/' . $relative);
        AppPaths::instance()->ensureDir(dirname($full));
        file_put_contents($full, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2ZQAAAABJRU5ErkJggg==',
            true,
        ));
        $this->created[] = $full;

        $url = '/storage/uploads/' . $relative;

        $this->assertSame(['path' => $url, 'error' => ''], $this->select($url));
    }

    #[Test]
    public function aCdnUrlIsAccepted(): void
    {
        $url = 'https://res.cloudinary.com/epithemic/image/upload/avatar.jpg';

        $this->assertSame(['path' => $url, 'error' => ''], $this->select($url));
    }

    #[Test]
    public function anUnknownLocalPathIsRefused(): void
    {
        $result = $this->select('/storage/uploads/../.env');

        $this->assertNull($result['path']);
        $this->assertSame('Choose an image from the media library.', $result['error']);
    }

    #[Test]
    public function anEmptySelectionKeepsTheCurrentAvatar(): void
    {
        $this->assertSame(['path' => null, 'error' => ''], $this->select(''));
    }

    #[Test]
    public function anUnchangedMissingAvatarDoesNotBlockTheForm(): void
    {
        $stored = '2026/10/09/missing-' . bin2hex(random_bytes(8)) . '.jpg';
        $request = Request::create('POST', '/mark/users/1/edit', body: ['avatar' => $stored]);
        $method = new ReflectionMethod(UsersAction::class, 'editAvatarState');

        /** @var array{errors: list<string>, newAvatar: ?string, removeAvatar: bool} $result */
        $result = $method->invoke(new UsersAction(), [], $request, $stored);

        $this->assertSame([], $result['errors'], 'Saving unrelated fields must not fail on a missing old avatar.');
        $this->assertNull($result['newAvatar']);
        $this->assertFalse($result['removeAvatar']);
    }

    /**
     * @return array{path: ?string, error: string}
     */
    private function select(string $avatar): array
    {
        $request = Request::create('POST', '/mark/users/create', body: ['avatar' => $avatar]);
        $method = new ReflectionMethod(UsersAction::class, 'avatarSelection');

        /** @var array{path: ?string, error: string} $result */
        $result = $method->invoke(new UsersAction(), $request);

        return $result;
    }
}
