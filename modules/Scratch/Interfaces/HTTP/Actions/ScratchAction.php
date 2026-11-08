<?php

declare(strict_types = 1);

namespace Modules\Scratch\Interfaces\HTTP\Actions;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * ScratchAction — the module-lifecycle fixture.
 *
 * A deliberately minimal leaf module: one route, zero non-kernel imports, no
 * module.json. It exists so `bin/cms module:add/remove` has something real to
 * move — the round trip (add → 200 → remove → 404 → byte-identical tree) is
 * exercised against this slice. It ships WITHOUT module.json on purpose: a
 * module written before the catalogue existed must install on the strength of
 * the scan alone.
 */
#[Route(path: '/scratch', method: 'GET', name: 'scratch')]
final class ScratchAction
{
    public function handle(Request $request): Response
    {
        return Response::html(FrontendLayout::render(
            'Scratch',
            '<section class="k-section"><h1 class="k-section__title">Scratch</h1>'
            . '<p>A leaf module, installed and removed without a trace.</p></section>',
            currentPath: '/scratch',
            noindex: true,
        ));
    }
}
