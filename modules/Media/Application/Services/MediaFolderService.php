<?php

declare(strict_types = 1);

namespace Modules\Media\Application\Services;

use Modules\Core\Domain\SubmitResult;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\UnitOfWork;
use Modules\Media\Infrastructure\Persistence\MediaFolderRepository;

/**
 * MediaFolderService — the decisions a folder operation makes, in one place.
 *
 * ## Why validation is not `normalizeFolder()`
 *
 * `MediaService::normalizeFolder()` is a COERCION: it strips slashes and
 * returns `''` for anything malformed, and `''` means the root. That is the
 * right answer for an upload field pre-filled from a previous value, and the
 * wrong one for an explicit folder operation — a user who types `..` and gets
 * their folder silently created at the root has been obeyed into a state they
 * did not ask for. `validate()` therefore REFUSES and returns a message
 * naming the problem, and nothing is stored anywhere.
 *
 * ## Why rename is transactional
 *
 * One rename writes two tables: the registry row and every local asset that
 * carries the path. A failure between them leaves the registry saying
 * `media/photos` while the assets still say `photos` — both answers look
 * authoritative and nothing errors. `UnitOfWork` over the one connection
 * makes the rewrite all-or-nothing.
 *
 * ## What this service does NOT do
 *
 * It never touches a file. Deleting a folder returns the ids a cascade should
 * remove; the provider-aware deletion is `MediaService::batchDelete()`'s job,
 * and this class is deliberately free of both the storage provider and
 * `MediaService` — `MediaService` calls INTO here for folder listings, so a
 * dependency in the other direction would be a cycle.
 */
final readonly class MediaFolderService
{
    public const int MAX_LENGTH = 255;

    public function __construct(
        private MediaFolderRepository $folders,
        private ConnectionInterface $db,
    ) {
    }

    /**
     * Every registered path, ordered.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return $this->folders->all();
    }

    public function exists(string $path): bool
    {
        return $this->folders->exists($path);
    }

    /**
     * How many rows a folder operation would touch, per kind.
     *
     * @return array{assets: int, folders: int, cloudinary: int}
     */
    public function counts(string $path): array
    {
        return $this->folders->subtreeCounts($path);
    }

    /**
     * Delete a folder from the registry, refusing to take its files with it
     * unless the caller asked for that explicitly.
     *
     * The ids come back so the caller cascades through the provider-aware
     * path; the refusal names the number of files for the same reason.
     *
     * @return SubmitResult<array{folders: int, assets: int, skipped: int, ids: list<string>}>
     */
    public function delete(string $path, bool $cascade): SubmitResult
    {
        if (!$this->folders->exists($path)) {
            return self::deleteFailure('No folder named ' . $path . '.');
        }

        $counts = $this->folders->subtreeCounts($path);
        $ids = $this->folders->subtreeAssetIds($path);

        if ($counts['assets'] > 0 && !$cascade) {
            return self::deleteFailure(sprintf(
                'The folder holds %d file%s. Delete them too, or move them first.',
                $counts['assets'],
                1 === $counts['assets'] ? '' : 's',
            ));
        }

        $this->folders->deleteSubtree($path);

        return SubmitResult::success([
            'folders' => $counts['folders'],
            'assets'  => $counts['assets'],
            'skipped' => $counts['cloudinary'],
            'ids'     => $ids,
        ]);
    }

    /**
     * Register a folder that holds nothing yet.
     *
     * `path` travels on both answers: a refusal shows the field the reader
     * typed in, and a success is the name that was actually stored.
     *
     * @return SubmitResult<array{path: string}>
     */
    public function create(string $path): SubmitResult
    {
        $clean = trim($path);
        $error = self::validate($clean);

        if ('' !== $error) {
            return SubmitResult::failure($error, ['path' => $clean]);
        }

        if ($this->folders->exists($clean)) {
            return SubmitResult::failure(
                'A folder named ' . $clean . ' already exists.',
                ['path' => $clean],
            );
        }

        $this->folders->insert($clean);

        return SubmitResult::success(['path' => $clean]);
    }

    /**
     * Rename a folder and move its whole subtree with it.
     *
     * @return SubmitResult<array{folders: int, assets: int, skipped: int}>
     */
    public function rename(string $from, string $to): SubmitResult
    {
        $clean = trim($to);
        $error = $this->refusal($from, $clean);

        if (null !== $error) {
            return self::renameFailure($error);
        }

        // How many imported rows keep their folder — reported, never rewritten.
        $skipped = $this->folders->subtreeCounts($from)['cloudinary'];
        $moved = (new UnitOfWork($this->db))->transaction(
            fn (): array => $this->folders->renameSubtree($from, $clean),
        );

        return SubmitResult::success([
            'folders' => $moved['folders'],
            'assets'  => $moved['assets'],
            'skipped' => $skipped,
        ]);
    }

    /**
     * A folder name that is safe to store, or a message naming what is wrong
     * with it. `''` means valid — a name is never normalised into another one.
     */
    public static function validate(string $path): string
    {
        if ('' === $path) {
            return 'Enter a folder name.';
        }

        if (strlen($path) > self::MAX_LENGTH) {
            return sprintf('A folder name may be at most %d characters.', self::MAX_LENGTH);
        }

        if (str_contains($path, '\\')) {
            return 'A folder name cannot contain a backslash.';
        }

        if (str_starts_with($path, '/') || str_ends_with($path, '/')) {
            return 'A folder name cannot start or end with a slash.';
        }

        foreach (explode('/', $path) as $segment) {
            if ('' === $segment) {
                return 'A folder name cannot contain an empty segment.';
            }

            if ('.' === $segment || '..' === $segment) {
                return 'A folder name cannot contain a "." or ".." segment.';
            }
        }

        return '';
    }

    /**
     * The first reason a rename must be refused, or null when it may proceed.
     *
     * The subtree check is not redundant with "the target exists": renaming
     * `photos` onto `media/photos` when the registry already holds
     * `media/photos/2026` collides one level DOWN, where a plain existence
     * check on the target path sees nothing wrong — and the rewrite would then
     * die mid-way with a UNIQUE violation. Finding it first is what turns a
     * half-applied rename into a message.
     */
    private function refusal(string $from, string $to): ?string
    {
        $error = self::validate($to);

        if ('' !== $error) {
            return $error;
        }

        if (!$this->folders->exists($from)) {
            return 'No folder named ' . $from . '.';
        }

        if ($this->folders->exists($to)) {
            return 'A folder named ' . $to . ' already exists.';
        }

        if (str_starts_with($to . '/', $from . '/')) {
            return 'A folder cannot be moved inside itself.';
        }

        $subtree = $this->folders->subtreePaths($from);
        $incoming = array_map(static fn (string $path): string => $to . substr($path, strlen($from)), $subtree);
        $outside = array_values(array_diff($this->folders->all(), $subtree));
        $collisions = array_values(array_intersect($incoming, $outside));

        return [] === $collisions ? null : 'The target already holds ' . $collisions[0] . '.';
    }

    /**
     * A refusal still carries the counts at zero, so a caller that renders the
     * report does not have to know which answer it holds before reading them.
     *
     * @return SubmitResult<array{folders: int, assets: int, skipped: int}>
     */
    private static function renameFailure(string $error): SubmitResult
    {
        return SubmitResult::failure($error, ['folders' => 0, 'assets' => 0, 'skipped' => 0]);
    }

    /**
     * @return SubmitResult<array{folders: int, assets: int, skipped: int, ids: list<string>}>
     */
    private static function deleteFailure(string $error): SubmitResult
    {
        /** @var array{folders: int, assets: int, skipped: int, ids: list<string>} $context */
        $context = ['folders' => 0, 'assets' => 0, 'skipped' => 0, 'ids' => []];

        return SubmitResult::failure($error, $context);
    }
}
