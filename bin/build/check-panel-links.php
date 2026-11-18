<?php

declare(strict_types = 1);

/**
 * check-panel-links.php — every top-level `/mark` page an installed module
 * serves has a sidebar entry.
 *
 * The rule the panel is built on: a nav entry is declared next to the module
 * that owns the page, and the sidebar renders what the installed modules
 * declare. A module that ships a route and forgets the attribute is therefore
 * invisible — the page answers 200 and nothing links to it, and an operator
 * reaches it by typing the URL. Measured 2026-11-18: seven top-level pages were
 * in that state (Pages, Comments, Questions, Forms, Queue, Schedule,
 * Permissions), and every gate was green because a page that renders is not a
 * page a gate opened.
 *
 * `bin/build/mark-smoke.sh` asks the same question over HTTP, which is the
 * stronger claim — but it runs AFTER the install, in the suite job, and the
 * defect belongs to the catalogue. This is the earlier, cheaper answer: the
 * route table and the declarations of the tree that was just installed, with no
 * database and no server.
 *
 * What counts as a page
 *
 *   GET, inside `/mark`, no `{placeholder}`, and exactly one segment under it.
 *   `/mark/settings/modules` is reached from the settings screen and demanding
 *   a menu entry for it would push a link into the sidebar no operator asked
 *   for. `/mark` itself is the dashboard: one slash, not two, and declared
 *   anyway.
 *
 * Usage: php bin/build/check-panel-links.php      (needs the catalogue installed)
 */

require __DIR__ . '/../../src/init.php';

use Modules\Cardboard\Infrastructure\Registry\CardboardNavRegistry;
use Modules\Core\Interfaces\HTTP\Router;

$router = new Router([APP_BASE_PATH . '/src/Modules']);
$declared = array_column(CardboardNavRegistry::all(), 'href');

$pages = 0;
$orphans = [];

foreach ($router->toEntries() as $entry) {
    $path = $entry['path'];

    if ('GET' !== $entry['method'] || !str_starts_with($path, '/mark')) {
        continue;
    }

    if (str_contains($path, '{') || 2 !== substr_count($path, '/')) {
        continue;
    }

    $pages++;

    if (!in_array($path, $declared, true)) {
        $orphans[] = $path;
    }
}

if ([] !== $orphans) {
    sort($orphans);

    fwrite(STDERR, "::error::the panel serves top-level pages with no #[AsCardboardNavItem]:\n");

    foreach ($orphans as $orphan) {
        fwrite(STDERR, '  ' . $orphan . "\n");
    }

    fwrite(
        STDERR,
        "A page nothing links to is a page only its author knows about. Declare it in\n"
        . "the owning module's Application/Nav class, with the permission\n"
        . "MarkRoutePolicy asks for the path — CardboardNavContractTest fails a\n"
        . "declaration that disagrees with the policy.\n",
    );

    exit(1);
}

printf("ok  every top-level /mark page has a nav declaration (%d pages, %d declarations)\n", $pages, count($declared));
