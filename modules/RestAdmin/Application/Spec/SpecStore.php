<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Spec;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\RestAdmin\Domain\Model\ServiceSpec;
use Modules\RestAdmin\Infrastructure\Persistence\AtomicJsonFile;

/**
 * SpecStore — a portable, versioned snapshot of one service's specification.
 *
 * SQLite (`SpecRepository`) is what the panel reads: it can ask "which
 * resources point at a resource that is gone" without parsing documents. This
 * store writes the same aggregate as one JSON file, atomically, for the two
 * jobs a table is bad at: exporting a service to move it between installs, and
 * handing a whole specification to a reviewer (or to the MCP tools in F5) as
 * one readable document.
 *
 * The snapshot carries `version` (the document shape, not the service) and
 * `updated_at` (UTC, ISO 8601). A version this build does not read is refused
 * by name instead of being half-understood — a future field silently ignored
 * is the failure mode the number exists to prevent.
 *
 * `load()` re-validates the document through `SpecValidator`: a file can be
 * edited by hand or written by an older build, and the aggregate is only worth
 * having if it is a valid one.
 */
final readonly class SpecStore
{
    /** The document shape this build writes and reads. */
    private const int VERSION = 1;

    private string $directory;

    public function __construct(
        ?string $directory = null,
        private SpecValidator $validator = new SpecValidator(),
        private AtomicJsonFile $files = new AtomicJsonFile(),
    ) {
        // The default lives under storage/data, beside restadmin.db, because a
        // snapshot and the rows it mirrors belong to the same backup.
        $this->directory = $directory ?? AppPaths::instance()->data('restadmin');
    }

    /**
     * @return string the snapshot path
     *
     * @throws SpecValidationException when the service id is not a safe file name
     * @throws \RuntimeException       when the file cannot be replaced
     */
    public function save(ServiceSpec $spec): string
    {
        $path = $this->pathFor($spec->service->id);

        $this->files->write($path, [
            'version'    => self::VERSION,
            'updated_at' => gmdate('c'),
            'spec'       => $spec->toArray(),
        ]);

        return $path;
    }

    /**
     * @throws SpecValidationException when the snapshot's version or content is not valid
     * @throws \RuntimeException       when the file exists but cannot be read
     */
    public function load(string $serviceId): ?ServiceSpec
    {
        $path = $this->pathFor($serviceId);

        if (!is_file($path)) {
            return null;
        }

        $document = $this->files->read($path);
        $version = $document['version'] ?? null;

        if (self::VERSION !== $version) {
            throw new SpecValidationException('version', sprintf(
                'this snapshot is version %s; this build reads version %d.',
                is_int($version) || is_string($version) ? (string) $version : 'unknown',
                self::VERSION,
            ));
        }

        return $this->validator->validate($document['spec'] ?? null);
    }

    /**
     * @throws \RuntimeException when the snapshot exists and cannot be removed
     */
    public function remove(string $serviceId): void
    {
        $path = $this->pathFor($serviceId);

        if (is_file($path) && !@unlink($path)) {
            throw new \RuntimeException(sprintf('Cannot remove the snapshot %s.', $path));
        }
    }

    /**
     * The id is validated again here, not only in `SpecValidator`, because this
     * is the method that turns it into a path: a caller that skipped the
     * validator must still not reach `../`.
     */
    private function pathFor(string $serviceId): string
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $serviceId)) {
            throw new SpecValidationException('service.id', 'is not a safe snapshot name: lower-case letters, digits, "-" or "_", at most 32 characters.');
        }

        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $serviceId . '.json';
    }
}
