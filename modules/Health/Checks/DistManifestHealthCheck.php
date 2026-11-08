<?php

declare(strict_types = 1);

namespace Modules\Health\Checks;

use Modules\Health\Contracts\HealthCheckInterface;
use Modules\Health\Value\HealthResult;
use Modules\Health\Value\HealthStatus;

/**
 * DistManifestHealthCheck — every built theme bundle has a manifest that parses.
 *
 * This is the check marko has no equivalent of, and the one this tree needs:
 * `public/dist/<theme>/manifest.json` is how the admin and public chrome find
 * their hashed CSS/JS. A manifest that is missing or truncated serves every PHP
 * surface correctly and renders both of them unstyled — the failure mode
 * `docs/kernel-status.md` (Boot 15) recorded, where `make assets` had been
 * skipped and only the frozen bundles kept the site looking right.
 *
 * The check reads `manifest.json` as JSON and requires a non-empty object. It
 * does not verify that each referenced asset file exists: that is a build-time
 * property `dist-check.sh` already gates, and re-walking every bundle per
 * request would turn a health probe into a directory crawl.
 *
 * An empty `public/dist` is `Degraded`, not `Unhealthy`: a fresh clone before
 * `make assets` legitimately has none, and the kernel ships `griffe`/`mono` as
 * frozen bundles precisely so that state is rare rather than impossible.
 *
 * A BUNDLE is a directory carrying `assets/` — that is the shape `make assets`
 * produces (`dist/<theme>/assets/` + `manifest.json`). Anything else under
 * `public/dist` (a tool's `.cache`, an editor folder, a half-created target) is
 * skipped rather than demanded a manifest from: failing the site over a
 * directory no page can see would be a false outage, which is the failure mode
 * this check was already reshaped once to avoid.
 */
final class DistManifestHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly string $distDirectory,
    ) {
    }

    public function getName(): string
    {
        return 'dist';
    }

    public function check(): HealthResult
    {
        $start = microtime(true);

        try {
            $bundles = $this->bundles();

            foreach ($bundles as $bundle) {
                $manifest = $bundle . DIRECTORY_SEPARATOR . 'manifest.json';

                if (!is_file($manifest)) {
                    throw new \RuntimeException(sprintf('%s has no manifest.json', $bundle));
                }

                $contents = @file_get_contents($manifest);

                if (false === $contents) {
                    throw new \RuntimeException(sprintf('%s is unreadable', $manifest));
                }

                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

                if (!is_array($decoded) || [] === $decoded) {
                    throw new \RuntimeException(sprintf('%s is not a non-empty manifest', $manifest));
                }
            }
        } catch (\Throwable $failure) {
            return new HealthResult(
                name: $this->getName(),
                status: HealthStatus::Unhealthy,
                message: 'a theme bundle manifest is missing or does not parse',
                duration: microtime(true) - $start,
                exception: $failure,
            );
        }

        if ([] === $bundles) {
            return new HealthResult(
                name: $this->getName(),
                status: HealthStatus::Degraded,
                message: 'no theme bundle built',
                duration: microtime(true) - $start,
            );
        }

        return new HealthResult(
            name: $this->getName(),
            status: HealthStatus::Healthy,
            message: sprintf('%d theme bundle manifests parsed', count($bundles)),
            duration: microtime(true) - $start,
        );
    }

    /**
     * Bundle directories under `public/dist`, sorted.
     *
     * Sorted because the message counts them and a monitor diffing two
     * responses should see a stable string, and because directory order is
     * filesystem-dependent.
     *
     * @return list<string>
     */
    private function bundles(): array
    {
        if (!is_dir($this->distDirectory)) {
            return [];
        }

        $entries = @scandir($this->distDirectory);

        if (false === $entries) {
            return [];
        }

        $bundles = [];

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $this->distDirectory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path . DIRECTORY_SEPARATOR . 'assets')) {
                $bundles[] = $path;
            }
        }

        sort($bundles);

        return $bundles;
    }
}
