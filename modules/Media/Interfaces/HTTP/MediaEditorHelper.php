<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP;

use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Kernel\Contracts\RouterInterface;

/**
 * MediaEditorHelper — shared media UI components for all admin editors.
 *
 * Provides:
 * - Media picker modal (browse existing uploads)
 * - Inline upload via drag-drop, clipboard paste, or toolbar button
 * - Image preview for URL inputs
 *
 * Used by Articles (create/edit), Pages (create/edit), and any future
 * content editors that need media integration.
 */
final class MediaEditorHelper
{
    /**
     * Media picker modal + inline upload script.
     *
     * Renders a reusable set of JS functions and a modal that can be
     * embedded in any admin form with a markdown textarea.
     *
     * @param string|null $textareaId The markdown textarea to insert into, or
     *                                null when the picker only fills a field
     *                                (a cover image, an avatar)
     */
    public static function editorScript(RouterInterface $router, ?string $textareaId = 'body'): string
    {
        $escapedId = null !== $textareaId ? htmlspecialchars($textareaId, ENT_QUOTES) : '';

        return ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-picker.phtml', [
            'escapedId' => $escapedId,
            'url'       => static fn (string $name, array $params = []): string => $router->url($name, $params),
        ]);
    }
}
