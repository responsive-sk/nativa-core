<?php

declare(strict_types = 1);

namespace Modules\Core\Kernel\Contracts;

use Modules\Core\Infrastructure\Http\Response;

/**
 * ErrorRendererInterface — renders the HTTP error responses (404/405/500).
 */
interface ErrorRendererInterface
{
    public function renderError(int $status, string $title, string $detail, ?string $path = null): Response;
}
