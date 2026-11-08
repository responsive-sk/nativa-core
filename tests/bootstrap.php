<?php

declare(strict_types = 1);

/*
 * tests/bootstrap.php — the harness boots the REAL kernel init, not a
 * fixture. A fixture init that drifts from src/init.php is exactly the
 * staleness this harness exists to catch: what the tests dispatch through
 * is what :8000 serves.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/init.php';
