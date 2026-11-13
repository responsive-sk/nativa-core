<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/**
 * The kernel's refactoring gate — `composer rector` / `composer rector-dry`.
 *
 * Same shape as the showcase's (the slice was written under it): PHP 8.3 is a
 * floor for the 8.5 runtime, and the three level settings stay at 0 so this
 * job reports what a *language* upgrade would change, not what one developer's
 * taste would. Raise them deliberately, in a commit of their own.
 *
 * The scope is the code this repo owns and may rewrite: the kernel slice
 * (`src/Modules/Core`, `src/Modules/Database`), `src/Themes`, `bin`, `public`
 * and `tests` — the same scope `phpstan.neon` analyses.
 *
 * Excluded, deliberately: `src/Modules/*` elsewhere (installed module slices —
 * operator state, so the verdict would depend on what is installed) and
 * `modules/` (the verbatim catalogue, excluded for the CS fixer's reason:
 * rewriting it makes the next re-import a merge).
 *
 * Without this file `composer rector-dry` printed "No rector.php config found"
 * and exited 0 — a gate that passed by doing nothing.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src/Modules/Core',
        __DIR__ . '/src/Modules/Database',
        __DIR__ . '/src/Themes',
        __DIR__ . '/bin',
        __DIR__ . '/public',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0)
    /**
     * `ReturnNeverTypeRector` wants `'Some\Class'` turned into `Some\Class::class`
     * anywhere it can see a class-shaped string. In `tests/` that is a downgrade:
     * `::class` is a static reference for PHPStan, so a test that names an
     * OPTIONAL module's class by `::class` fails `composer phpstan` on every
     * kernel-only checkout — the analyser resolves the constant whether or not
     * the tree loaded that module.
     *
     * Measured 2026-10-17: `MetricRegistryResolutionTest` written with the
     * string form was green on both shapes; rewritten to `::class` because
     * Rector asked, it produced
     * `Class Modules\Cardboard\Infrastructure\Registry\MetricRegistry not found`
     * and a red `analysis` job. The same shape `AuthMiddlewareDenialTest`
     * carried until task 0.2.
     *
     * The rule stays on outside `tests/`, where a class-shaped string is a
     * mistake worth rewriting.
     */
    ->withSkip([
        __DIR__ . '/tests',
    ]);
