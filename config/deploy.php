<?php

declare(strict_types = 1);

/**
 * Deployment profile for the export — the identity the artifact boots with.
 *
 * The source profile names `boson`, which is the site's theme and is not
 * shipped here. This one names the only theme the catalogue carries.
 *
 * This file is a value, not an effect: the command that writes it
 * (`settings:profile`) lives in `Settings`, so a checkout without that module
 * has nothing to apply it to and boots the kernel theme. `bin/build/build-dist.sh`
 * detects exactly that and says so in its step and in the build summary
 * (`identity: kernel defaults (no Settings module to apply config/deploy.php)`),
 * rather than reporting a profile it never read. Install `View`, `Layout` and
 * `Settings` and the same build applies this file instead.
 *
 * `theme` is the only key on purpose: `Settings` seeds `site_name` with the
 * kernel default, and a starter tree that silently rebrands itself is a
 * decision an operator should make in the panel, not inherit from an export.
 *
 * @return array<string, string>
 */
return [
    'theme' => 'nativa',
];
