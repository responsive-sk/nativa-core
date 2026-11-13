<?php

declare(strict_types = 1);

namespace Modules\Tooling;

/**
 * The network seam of the module registry.
 *
 * ModuleRegistry resolves and verifies a release; this interface only fetches
 * bytes. Tests pass a fake, so the install path never needs a live server.
 */
interface RegistryTransport
{
    /**
     * Fetch one URL, refusing a response larger than $limit bytes.
     *
     * @throws \RuntimeException on a transport error, a non-2xx response or an
     *                           oversized body
     */
    public function get(string $url, int $limit): string;
}
