<?php

declare(strict_types = 1);

/**
 * Deployment profile — the identity an ARTIFACT is built with.
 *
 * `bin/build/build-dist.sh` migrates the dist and nothing more: real schemas,
 * kernel defaults (`theme=kernel`, `site_name=Nativa PHP Core`), and never the
 * developer's `storage/data/`, which is local state. Measured 2026-10-17: that
 * made a rebuilt dist lose the site it was built for — the responsive.sk build
 * came up on the kernel theme, so the copy looked deleted even though nothing
 * had touched it.
 *
 * This file is the missing half. It names the settings a build STARTS with, and
 * the build applies it right after `migrate` (through `bin/cms settings:profile`
 * → `Modules\Settings\Interfaces\CLI\Command\ApplyDeploymentProfileCommand`), so
 * a rebuild cannot silently rebrand the deployment.
 *
 * Three rules, all enforced by the command rather than trusted here:
 *
 *   1. Every key must ALREADY EXIST in the settings table (the migrator's seed
 *      is the vocabulary). A typo is refused at build time — a profile that
 *      silently does nothing ships the kernel defaults and looks green.
 *   2. Only the VALUE is written. `group_name`, `is_public` and `value_type`
 *      belong to the seed and to `/mark` → Settings: `theme` is a non-public
 *      `design` row, and flipping that from a profile would change what the
 *      frontend may read.
 *   3. Rows typed `boolean` take `true`/`false` and are stored as '1'/'0'.
 *
 * It describes what a build starts as, NOT what the site must stay: the
 * operator still changes everything in `/mark` → Settings, and a deployment
 * that wants the kernel defaults deletes this file or builds with
 * `--no-profile`.
 *
 * The file travels into the artifact (the build copies `config/`), which is
 * deliberate: the artifact carries the reason for the identity it boots with.
 *
 * @return array<string, string|bool>
 */
return [
    'theme'     => 'boson',
    'site_name' => 'responsive.sk',
];
