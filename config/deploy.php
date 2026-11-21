<?php

declare(strict_types = 1);

/**
 * Deployment profile for the export — the identity the artifact boots with.
 *
 * The site's theme is `boson`: restored from commit 74c3a4a and carried in the
 * catalogue, so a fresh clone (and `make dist-check`) renders the site's theme
 * instead of falling back to the kernel theme.
 *
 * `theme` is the only key on purpose: `Settings` seeds `site_name` with the
 * kernel default, and a starter tree that silently rebrands itself is a
 * decision an operator should make in the panel, not inherit from an export.
 *
 * @return array<string, string>
 */
return [
    'theme' => 'boson',
];
