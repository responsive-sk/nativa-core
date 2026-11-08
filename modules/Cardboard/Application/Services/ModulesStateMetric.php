<?php

declare(strict_types=1);

namespace Modules\Cardboard\Application\Services;

use Modules\Cardboard\Domain\Contracts\MetricContract;
use Modules\Cardboard\Domain\Model\Card;
use Modules\Cardboard\Domain\Model\CardLink;
use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Infrastructure\Attributes\AsMetricProvider;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;

/**
 * ModulesStateMetric — is the tree running the code it says it is?
 *
 * `src/Modules/<Name>` is gitignored operator state: a clone carries Core +
 * Database and nothing else, and `module:add` copies the catalogue in. So the
 * running tree and the committed catalogue are allowed to disagree — and they
 * do, quietly.
 *
 * Measured 2026-10-17, six modules after each other. `AdminRoutePolicy` and
 * `Permissions` moved to `Modules\Core\Domain`; Cardboard's two local copies of
 * the permission table were deleted. Every gate was green — phpstan, the suite,
 * the ratchet, the admin smoke over HTTP — and the installed slice still carried
 * the old code, because nothing compared the two. `git status` cannot see it,
 * since the slice is not tracked, and `module:show` reports `isInstalled()`,
 * which answers "is there a slice", not "is it the same slice".
 *
 * So the dashboard says it. Cardboard renders nine metric cards today and none
 * of them report on modules; this is a tenth, in the pattern the other nine
 * already use, found by discovery with no new route.
 *
 * It reads through `ModuleTreeState`, the same reader the
 * `/mark/settings/modules` page uses. Two surfaces answering one question about
 * one tree are the code that would drift from each other, which is the entire
 * subject here; there is exactly one reader.
 *
 * ## Why it lives in Cardboard and not in Tooling
 *
 * `ModuleCatalog` is in `Modules\Tooling`, which is kernel — and a file there
 * naming Cardboard is a NEW EDGE on `KernelCouplingTest`'s ledger, whose whole
 * claim is that no kernel file names another module. Tooling is also *below*
 * Cardboard in the load order, so a metric provider there would be the first
 * thing to reach for a contract Cardboard owns. Measured: the coupling test
 * failed with six edges and `Ledger: 0 rows` the moment the class was written
 * there, and `phpstan` was happy with the same file — the coupling guard is the
 * one that sees this shape, which is why it exists.
 *
 * Neutral on purpose. Drift between catalogue and slice is the expected state
 * after a commit and before a `module:add`, so a red card would cry wolf on every
 * normal edit of the catalogue. The subtitle names the counts; the judgment
 * about when to reinstall stays with the operator.
 *
 * Priority 30: the lowest number on the board, so it sorts below every content
 * metric. This is infrastructure state, not something anyone triages.
 *
 * @internal
 *
 * @coversNothing
 */
#[AsMetricProvider(priority: 30)]
final readonly class ModulesStateMetric implements MetricContract
{
    public function registerCards(Period $period): void
    {
        try {
            $report = (new ModuleTreeState())->counts();

            CardboardRegistry::register(
                Card::make('modules', 'Modules', $report['total'])
                    ->withSubtitle(sprintf(
                        '%d ok · %d drift · %d not installed',
                        $report['identical'],
                        $report['drifted'],
                        $report['missing'],
                    ))
                    ->withActions(CardLink::make('Module List', '/mark/settings/modules'))
                    ->withPriority(30),
            );
        } catch (\Throwable) {
            // No filesystem, no catalogue — skip silently, the way every other
            // provider does. A dashboard that 500s over a stat card helps nobody.
            // @mago-expect lint:no-empty-catch-clause
        }
    }
}