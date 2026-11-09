<?php

declare(strict_types = 1);

/**
 * Deployment profile for the export — the identity the artifact boots with.
 *
 * The source profile names `boson`, which is the site's theme and is not
 * shipped here. This one names the only theme the catalogue carries, so a fresh
 * clone (and `make dist-check`) renders Nativa instead of falling back to the
 * kernel theme.
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
