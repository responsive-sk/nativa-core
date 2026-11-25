<?php

declare(strict_types = 1);

/**
 * Deployment profile for the export, generated from bin/export/core/profile.json.
 *
 * The theme is the identity the artifact boots with. The other keys are
 * written only when the profile names them, so a starter tree does not
 * silently rebrand a site an operator has not configured.
 *
 * @return array<string, string>
 */
return [
    'theme' => 'nativa',
];
