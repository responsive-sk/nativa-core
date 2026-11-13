<?php

declare(strict_types = 1);

namespace Modules\Media\Infrastructure\Persistence;

use Modules\Database\Contracts\ConnectionInterface;

/**
 * MediaFolderRepository — the folder registry (`media_folders`) and the
 * subtree rewrites a folder operation performs on the asset rows.
 *
 * ## Why the rewrites live here rather than in MediaRepository
 *
 * Renaming `photos` is one operation over two tables: the registry row and
 * every local asset that carries the path. Splitting them across two
 * repositories is how they drift — the registry says `media/photos` while the
 * assets still say `photos`, and nothing fails, because both answers look
 * authoritative. One owner, one transaction.
 *
 * ## Provenance rows are never rewritten
 *
 * Every asset rewrite is restricted to `provider != 'cloudinary'`. For an
 * imported Cloudinary row, `folder` records where the REMOTE file lives and is
 * written from the API at import time; rewriting it would make the local
 * database disagree with the cloud while the panel claims the file moved. The
 * skipped rows are counted separately so a caller can report them.
 *
 * ## No LIKE wildcards in the subtree match
 *
 * `photos/%` as a LIKE pattern also matches `photos-old%` and treats `_` in a
 * folder name as a wildcard. The subtree predicate is therefore
 * `substr(col, 1, length(:path)) = :path AND substr(col, length(:path) + 1, 1)
 * = '/'` — exact prefix plus a real separator, with nothing interpreted.
 *
 * All statements are prepared; no value is concatenated into SQL.
 */
final readonly class MediaFolderRepository
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    /**
     * Every registered path, ordered.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $stmt = Statement::query($this->connection, 'SELECT path FROM media_folders ORDER BY path');


        /** @var list<string> $paths */
        $paths = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        return $paths;
    }

    public function exists(string $path): bool
    {
        $stmt = Statement::prepare($this->connection, 'SELECT 1 FROM media_folders WHERE path = :path LIMIT 1');
        $stmt->execute([':path' => $path]);

        return false !== $stmt->fetchColumn();
    }

    /**
     * Register a folder. Idempotent: `true` means this call created it.
     */
    public function insert(string $path): bool
    {
        $stmt = Statement::prepare($this->connection, 'INSERT OR IGNORE INTO media_folders (path) VALUES (:path)');
        $stmt->execute([':path' => $path]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Rewrite a folder path and its subtree in BOTH tables, in the caller's
     * transaction. Returns how many rows each table lost/gained a path.
     *
     * @return array{folders: int, assets: int}
     */
    public function renameSubtree(string $from, string $to): array
    {
        return [
            'folders' => $this->rewrite($this->registryRewriteSql(true), $from, $to, ''),
            'assets'  => $this->rewrite($this->assetsRewriteSql(true), $from, $to, " AND provider != 'cloudinary'"),
        ];
    }

    /**
     * Delete a folder and its subtree from the registry. Asset rows are NOT
     * touched: deleting a file is the provider-aware path's job, and this
     * class has no provider.
     */
    public function deleteSubtree(string $path): int
    {
        $stmt = Statement::prepare($this->connection,
            'DELETE FROM media_folders WHERE path = :path'
            . " OR substr(path, 1, length(:path)) = :path AND substr(path, length(:path) + 1, 1) = '/'",
        );
        $stmt->execute([':path' => $path]);

        return $stmt->rowCount();
    }

    /**
     * Every registered path at or below a path, ordered — the exact set a
     * rename rewrites.
     *
     * @return list<string>
     */
    public function subtreePaths(string $path): array
    {
        $stmt = Statement::prepare($this->connection,
            'SELECT path FROM media_folders WHERE path = :path'
            . " OR substr(path, 1, length(:path)) = :path AND substr(path, length(:path) + 1, 1) = '/'"
            . ' ORDER BY path',
        );
        $stmt->execute([':path' => $path]);

        /** @var list<string> $paths */
        $paths = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        return $paths;
    }

    /**
     * Local asset ids at or below a path — the rows a cascade would delete.
     *
     * @return list<string>
     */
    public function subtreeAssetIds(string $path): array
    {
        $stmt = Statement::prepare($this->connection,
            'SELECT id FROM assets WHERE (folder = :path'
            . " OR substr(folder, 1, length(:path)) = :path AND substr(folder, length(:path) + 1, 1) = '/')"
            . " AND provider != 'cloudinary' ORDER BY id",
        );
        $stmt->execute([':path' => $path]);

        /** @var list<string> $ids */
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        return $ids;
    }

    /**
     * How many rows a folder operation would touch, per kind. One query per
     * question, and `cloudinary` is reported rather than silently ignored, so
     * a refusal can say "12 files and 3 imported rows" instead of "12 files".
     *
     * @return array{assets: int, folders: int, cloudinary: int}
     */
    public function subtreeCounts(string $path): array
    {
        return [
            'assets'     => $this->count(
                'SELECT COUNT(*) FROM assets WHERE (folder = :path'
                . " OR substr(folder, 1, length(:path)) = :path AND substr(folder, length(:path) + 1, 1) = '/')"
                . " AND provider != 'cloudinary'",
                $path,
            ),
            'folders'    => $this->count(
                'SELECT COUNT(*) FROM media_folders WHERE path = :path'
                . " OR substr(path, 1, length(:path)) = :path AND substr(path, length(:path) + 1, 1) = '/'",
                $path,
            ),
            'cloudinary' => $this->count(
                'SELECT COUNT(*) FROM assets WHERE (folder = :path'
                . " OR substr(folder, 1, length(:path)) = :path AND substr(folder, length(:path) + 1, 1) = '/')"
                . " AND provider = 'cloudinary'",
                $path,
            ),
        ];
    }

    /**
     * Asset-derived counts, merged with the registry: a folder that is
     * registered but holds no matching file appears at `0` instead of
     * disappearing. That is the whole point of the registry — an empty folder
     * is still a folder.
     *
     * The merge is deliberately one-directional. A folder that has files but
     * no registry row (a Cloudinary path, or a row written before this table
     * existed) keeps its count and is NOT registered here: this class knows
     * which folders were declared, and only `insert()` declares one.
     *
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    public function countsWithRegistry(array $counts): array
    {
        foreach ($this->all() as $path) {
            $counts[$path] ??= 0;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * The rewrite statement for one table: the exact path, plus its subtree
     * (prefix + '/' separator, no wildcards).
     */
    private function registryRewriteSql(bool $subtree): string
    {
        $match = $subtree
            ? "path = :from OR substr(path, 1, length(:from)) = :from AND substr(path, length(:from) + 1, 1) = '/'"
            : 'path = :from';

        return 'UPDATE media_folders SET path = :to || substr(path, length(:from) + 1) WHERE ' . $match;
    }

    private function assetsRewriteSql(bool $subtree): string
    {
        $match = $subtree
            ? "folder = :from OR substr(folder, 1, length(:from)) = :from AND substr(folder, length(:from) + 1, 1) = '/'"
            : 'folder = :from';

        return 'UPDATE assets SET folder = :to || substr(folder, length(:from) + 1) WHERE ' . $match;
    }

    /**
     * Run one rewrite and report the affected row count. `substr(col, 1,
     * length(:from)) = :from` is true for the exact path too, and
     * `substr(path, length(:from) + 1)` is then the empty string, so one
     * statement covers the folder and its subtree.
     */
    private function rewrite(string $sql, string $from, string $to, string $extraCondition): int
    {
        $stmt = Statement::prepare($this->connection, $sql . $extraCondition);
        $stmt->execute([':from' => $from, ':to' => $to]);

        return $stmt->rowCount();
    }

    private function count(string $sql, string $path): int
    {
        $stmt = Statement::prepare($this->connection, $sql);
        $stmt->execute([':path' => $path]);
        $result = $stmt->fetchColumn();

        return is_numeric($result) ? (int) $result : 0;
    }
}
