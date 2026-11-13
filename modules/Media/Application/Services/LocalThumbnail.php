<?php

declare(strict_types = 1);

namespace Modules\Media\Application\Services;

use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * LocalThumbnail — the small square a local upload gets at store time.
 *
 * Cloudinary resizes on delivery, so a remote card asks for a 300px crop and
 * never sees the original. A local file had no such step: a 299 kB photo was
 * downloaded whole into a 150px card. When ext-gd is loaded this writes one
 * square beside the original under `storage/uploads/thumbs/`, and every tile
 * asks for that instead. Without gd nothing is written and the original is
 * served exactly as before, which is why the extension is an optimisation
 * rather than a requirement.
 *
 * The square keeps the source's family: a photo stays JPEG and everything
 * else becomes PNG. Measured on the tree's own uploads, a 400x400 JPEG
 * cropped to 300x300 is 152 kB as PNG and about 20 kB as JPEG, against 106 kB
 * for the original, so choosing PNG for every source would have made an
 * existing 52 kB file bigger than the one it replaced. The serving route
 * accepts a file only when its detected MIME matches its extension, which is
 * why the family is a rule and not a preference. A transparent source is
 * filled white first, because a black corner where a logo had transparency
 * is a bug to the eye even when the bytes are right.
 *
 * The directory name lives in relativePath() and nowhere else. A class
 * constant would be the usual owner, and `NameOwnershipContractTest` counts
 * every declaration in the tree, so the value stays in the one method that
 * builds the path instead of spending that ceiling.
 */
final class LocalThumbnail
{
    /**
     * Whether this PHP can make a square at all.
     */
    public static function available(): bool
    {
        return extension_loaded('gd')
            && function_exists('imagecreatefromstring')
            && function_exists('imagepng')
            && function_exists('imagejpeg');
    }

    /**
     * The square's path, relative to storage/uploads.
     */
    public static function relativePath(string $path): string
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME);

        return 'thumbs/'
            . ('.' === $directory || '' === $directory ? '' : $directory . '/')
            . $name . '.' . self::targetExtension($path);
    }

    /**
     * The extension the square is written with: a photo stays JPEG, because a
     * photo re-encoded as PNG is bigger than the file it came from.
     */
    private static function targetExtension(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg'], true) ? 'jpg' : 'png';
    }

    /**
     * Write the square for a stored upload, or null when it cannot be made.
     *
     * A source smaller than the edge is cropped to its own short side, so a
     * 100px icon is not blown up into a 300px file that would carry more
     * bytes than the image it came from.
     *
     * @return string|null the square's path relative to storage/uploads
     */
    public static function write(string $path, int $edge = 300): ?string
    {
        if (!self::available()) {
            return null;
        }

        $bytes = @file_get_contents(AppPaths::instance()->storage('uploads/' . $path));

        if (!is_string($bytes)) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if (!$image instanceof \GdImage) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($edge, $width, $height);

        if ($side < 1) {
            imagedestroy($image);

            return null;
        }

        $square = imagecreatetruecolor($side, $side);

        if (!$square instanceof \GdImage) {
            imagedestroy($image);

            return null;
        }

        $white = imagecolorallocate($square, 255, 255, 255);
        imagefilledrectangle($square, 0, 0, $side, $side, false === $white ? 0 : $white);
        imagecopyresampled(
            $square,
            $image,
            0,
            0,
            intdiv($width - $side, 2),
            intdiv($height - $side, 2),
            $side,
            $side,
            $side,
            $side,
        );

        $relative = self::relativePath($path);
        $target = AppPaths::instance()->storage('uploads/' . $relative);
        AppPaths::instance()->ensureDir(dirname($target));
        $written = 'jpg' === self::targetExtension($path)
            ? imagejpeg($square, $target, 82)
            : imagepng($square, $target, 6);

        imagedestroy($square);
        imagedestroy($image);

        return $written ? $relative : null;
    }

    /**
     * The URL a tile should load for a stored local file: its square when one
     * exists, null when it does not and the caller keeps the original.
     */
    public static function url(string $path): ?string
    {
        $relative = self::relativePath($path);

        return is_file(AppPaths::instance()->storage('uploads/' . $relative))
            ? '/storage/uploads/' . $relative
            : null;
    }

    /**
     * Remove the square of a stored file, if it has one. Called with the row
     * that points at the original, so a deletion never leaves a file nothing
     * refers to.
     */
    public static function forget(string $path): void
    {
        $target = AppPaths::instance()->storage('uploads/' . self::relativePath($path));

        if (is_file($target)) {
            @unlink($target);
        }
    }
}
