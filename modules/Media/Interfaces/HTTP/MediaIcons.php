<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP;

/**
 * MediaIcons — the inline SVGs the media admin screens share.
 *
 * Both the local library and the Cloudinary browser render folder tiles, and
 * an icon copied into two actions is an icon that drifts: the folder glyph
 * belongs to the folder tile, not to the action that happens to draw one.
 */
final class MediaIcons
{
    public static function folder(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
    }
}
