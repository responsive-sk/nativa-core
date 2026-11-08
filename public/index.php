<?php

declare(strict_types = 1);

/*
 * Public entry point — the front controller, and nothing more.
 *
 * The HTTP application is composed in `src/http.php`: early error logging,
 * `src/init.php`, the module pairings, the middleware pipeline, dispatch and
 * disposal. This file exists so the SAPI has a document to serve, not as a
 * second place for wiring.
 */

require_once __DIR__ . '/../src/http.php';

run_http_application();
