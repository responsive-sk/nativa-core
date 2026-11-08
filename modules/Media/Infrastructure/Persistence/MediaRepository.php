<?php

declare(strict_types = 1);

namespace Modules\Media\Infrastructure\Persistence;

use Modules\Media\Domain\Model\MediaAsset;

/**
 * MediaRepository — SQLite CRUD for the assets table in media.db.
 *
 * Follows the same thin-repository pattern as AuditLogRepository.
 * No cross-database joins — media.db is self-contained.
 */
final readonly class MediaRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(MediaAsset $asset): void
    {
        $stmt = Statement::prepare($this->pdo, <<<'SQL'
                INSERT INTO assets (id, filename, original_name, path, size, mime_type, uploaded_by, created_at, provider, folder, category)
                VALUES (:id, :filename, :original_name, :path, :size, :mime_type, :uploaded_by, :created_at, :provider, :folder, :category)
            SQL);

        $row = $asset->toArray();
        $stmt->execute([
            ':id'            => $row['id'],
            ':filename'      => $row['filename'],
            ':original_name' => $row['original_name'],
            ':path'          => $row['path'],
            ':size'          => $row['size'],
            ':mime_type'     => $row['mime_type'],
            ':uploaded_by'   => $row['uploaded_by'],
            ':created_at'    => $row['created_at'],
            ':provider'      => $row['provider'],
            ':folder'        => $row['folder'],
            ':category'      => $row['category'],
        ]);
    }

    public function findById(string $id): ?MediaAsset
    {
        $stmt = Statement::prepare($this->pdo, 'SELECT * FROM assets WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? MediaAsset::fromRow($row) : null;
    }

    /**
     * Find one asset by its uploads-relative path.
     *
     * The counterpart of incrementViewByPath(): the serving route knows the
     * path and never the id, so reading a counter back has to be keyed the
     * same way.
     */
    public function findByPath(string $path): ?MediaAsset
    {
        $stmt = Statement::prepare($this->pdo, 'SELECT * FROM assets WHERE path = :path LIMIT 1');
        $stmt->execute([':path' => $path]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? MediaAsset::fromRow($row) : null;
    }

    /**
     * Resolve a group of upload paths in one indexed lookup per chunk.
     *
     * @param list<string> $paths
     *
     * @return list<string> Paths already tracked in the local registry
     */
    public function findTrackedPaths(array $paths): array
    {
        if ([] === $paths) {
            return [];
        }

        $tracked = [];
        foreach (array_chunk(array_values(array_unique($paths)), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = Statement::prepare($this->pdo, "SELECT path FROM assets WHERE path IN ({$placeholders})");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $path) {
                if (is_string($path)) {
                    $tracked[$path] = true;
                }
            }
        }

        return array_keys($tracked);
    }

    /**
     * Count assets per folder under the active non-folder filters. The
     * selected folder is omitted so sibling folders remain navigable.
     *
     * @param list<string> $providers
     *
     * @return array<string, int> folder path => matching asset count
     */
    public function folderCounts(string $mimePrefix = '', string $category = '', string $tag = '', array $providers = []): array
    {
        ['from' => $from, 'where' => $where, 'params' => $params] = $this->filterClause($mimePrefix, $category, $tag, $providers, '');
        $sql = 'SELECT a.folder, COUNT(*) AS total FROM ' . $from . ' WHERE ' . $where
            . " AND a.folder != '' GROUP BY a.folder ORDER BY a.folder";
        $stmt = Statement::prepare($this->pdo, $sql);
        $this->bindFilterParams($stmt, $params);
        $stmt->execute();

        $counts = [];
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $folder = is_string($row['folder'] ?? null) ? $row['folder'] : '';
            if ('' !== $folder) {
                $counts[$folder] = is_numeric($row['total'] ?? null) ? (int) $row['total'] : 0;
            }
        }

        return $counts;
    }

    /**
     * One image per folder, for the folder tiles.
     *
     * Not filtered by the active type/category/tag: a tile's preview answers
     * "what is in this folder", not "what survives the current filter". A
     * folder holding no image is absent from the answer, and its tile keeps
     * the folder glyph.
     *
     * @param list<string> $folders
     *
     * @return array<string, MediaAsset> folder path => newest image in it
     */
    public function folderPreviews(array $folders): array
    {
        if ([] === $folders) {
            return [];
        }

        $names = [];
        $params = [];
        foreach (array_values($folders) as $index => $folder) {
            $name = ':preview_' . $index;
            $names[] = $name;
            $params[$name] = $folder;
        }

        $sql = 'SELECT * FROM ('
            . 'SELECT a.*, ROW_NUMBER() OVER (PARTITION BY a.folder ORDER BY a.created_at DESC) AS rn '
            . 'FROM assets a WHERE a.folder IN (' . implode(', ', $names) . ") AND a.mime_type LIKE 'image/%'"
            . ') WHERE rn = 1';

        $stmt = Statement::prepare($this->pdo, $sql);
        $this->bindFilterParams($stmt, $params);
        $stmt->execute();

        $previews = [];
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $asset = MediaAsset::fromRow($row);
            if ('' !== $asset->folder()) {
                $previews[$asset->folder()] = $asset;
            }
        }

        return $previews;
    }

    /**
     * Distinct non-empty folders, optionally restricted to resolved providers.
     *
     * @param list<string> $providers
     *
     * @return list<string>
     */
    public function getAllFolders(array $providers = []): array
    {
        $where = '';
        $params = [];
        if ([] !== $providers) {
            $names = [];
            foreach ($providers as $index => $provider) {
                $name = ':provider_' . $index;
                $names[] = $name;
                $params[$name] = $provider;
            }
            $where = ' AND provider IN (' . implode(', ', $names) . ')';
        }

        $stmt = Statement::prepare($this->pdo, "SELECT DISTINCT folder FROM assets WHERE folder != ''{$where} ORDER BY folder");
        $this->bindFilterParams($stmt, $params);
        $stmt->execute();

        /** @var list<string> $folders */
        $folders = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        return $folders;
    }

    public function updateFolder(string $id, string $folder): bool
    {
        $stmt = Statement::prepare($this->pdo, 'UPDATE assets SET folder = :folder WHERE id = :id');
        $stmt->execute([':folder' => $folder, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * List all assets, newest first.
     *
     * @return list<MediaAsset>
     */
    public function all(int $limit = 50, int $offset = 0): array
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT * FROM assets ORDER BY created_at DESC LIMIT :limit OFFSET :offset',
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $assets = [];
        while (true) {
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (false === $row) {
                break;
            }
            $assets[] = MediaAsset::fromRow($row);
        }

        return $assets;
    }

    public function delete(string $id): bool
    {
        $stmt = Statement::prepare($this->pdo, 'DELETE FROM assets WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete multiple assets by ID in a single query.
     *
     * @param list<string> $ids
     */
    public function deleteBatch(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Statement::prepare($this->pdo, "DELETE FROM assets WHERE id IN ({$placeholders})");
        $stmt->execute($ids);

        return $stmt->rowCount();
    }

    /**
     * Fetch assets by ID (for batch filesystem cleanup).
     *
     * @param list<string> $ids
     *
     * @return list<MediaAsset>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Statement::prepare($this->pdo, "SELECT * FROM assets WHERE id IN ({$placeholders})");
        $stmt->execute($ids);

        $assets = [];
        while (true) {
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (false === $row) {
                break;
            }
            $assets[] = MediaAsset::fromRow($row);
        }

        return $assets;
    }

    public function updateMetadata(string $id, string $altText, string $caption): bool
    {
        $stmt = Statement::prepare($this->pdo,
            'UPDATE assets SET alt_text = :alt_text, caption = :caption WHERE id = :id',
        );
        $stmt->execute([
            ':alt_text' => $altText,
            ':caption'  => $caption,
            ':id'       => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function updateCategory(string $id, string $category): bool
    {
        $stmt = Statement::prepare($this->pdo, 'UPDATE assets SET category = :category WHERE id = :id');
        $stmt->execute([':category' => $category, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function count(): int
    {
        $stmt = Statement::query($this->pdo, 'SELECT COUNT(*) FROM assets');
        $result = $stmt->fetchColumn();

        return is_numeric($result) ? (int) $result : 0;
    }

    // ─── Composed filtering (one query, every dimension ANDed) ───────

    /**
     * The one filtered listing. Every dimension is optional and they
     * compose: mime type, category, tag, provider source and folder are
     * ANDed, so selecting two filters narrows the result instead of one
     * silently winning — the old early-return chain did the latter while
     * the UI kept showing all three selects.
     *
     * @param list<string> $providers  Resolved provider names; empty = no source filter at all
     * @param string       $mimePrefix Already-mapped prefix ('image/', …); '' = any type
     *
     * @return list<MediaAsset>
     */
    public function findByFilters(
        string $sort,
        string $mimePrefix,
        string $category,
        string $tag,
        array $providers,
        string $folder,
        int $limit = 50,
        int $offset = 0,
    ): array {
        ['from' => $from, 'where' => $where, 'params' => $params] = $this->filterClause($mimePrefix, $category, $tag, $providers, $folder);

        $stmt = Statement::prepare($this->pdo,
            'SELECT a.* FROM ' . $from . ' WHERE ' . $where
            . ' ORDER BY ' . $this->orderBy($sort)
            . ' LIMIT :limit OFFSET :offset',
        );
        $this->bindFilterParams($stmt, $params);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $this->fetchAll($stmt);
    }

    /**
     * The counting twin of findByFilters() — the same WHERE, so a page's
     * total can never describe a different query than the page it labels.
     *
     * @param list<string> $providers
     */
    public function countByFilters(
        string $mimePrefix,
        string $category,
        string $tag,
        array $providers,
        string $folder,
    ): int {
        ['from' => $from, 'where' => $where, 'params' => $params] = $this->filterClause($mimePrefix, $category, $tag, $providers, $folder);

        $stmt = Statement::prepare($this->pdo, 'SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where);
        $this->bindFilterParams($stmt, $params);
        $stmt->execute();
        $result = $stmt->fetchColumn();

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Build the WHERE both filtered queries share. Returns the FROM
     * fragment (the tag filter needs the junction join), the WHERE string
     * and the bound values — every value bound, nothing concatenated.
     *
     * @param list<string> $providers
     *
     * @return array{from: string, where: string, params: array<string, string>}
     */
    private function filterClause(string $mimePrefix, string $category, string $tag, array $providers, string $folder): array
    {
        $from = '' !== $tag
            ? 'assets a INNER JOIN media_tags mt ON a.id = mt.asset_id'
            : 'assets a';

        $conditions = [];
        $params = [];

        if ('' !== $tag) {
            $conditions[] = 'mt.tag = :tag';
            $params[':tag'] = $tag;
        }
        if ('' !== $mimePrefix) {
            $conditions[] = 'a.mime_type LIKE :mime_prefix';
            $params[':mime_prefix'] = $mimePrefix . '%';
        }
        if ('' !== $category) {
            $conditions[] = 'a.category = :category';
            $params[':category'] = $category;
        }
        if ('' !== $folder) {
            $conditions[] = 'a.folder = :folder';
            $params[':folder'] = $folder;
        }
        if ([] !== $providers) {
            $names = [];
            foreach ($providers as $index => $provider) {
                $name = ':provider_' . $index;
                $names[] = $name;
                $params[$name] = $provider;
            }
            $conditions[] = 'a.provider IN (' . implode(', ', $names) . ')';
        }

        return [
            'from'   => $from,
            'where'  => [] === $conditions ? '1=1' : implode(' AND ', $conditions),
            'params' => $params,
        ];
    }

    /**
     * @param array<string, string> $params
     */
    private function bindFilterParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, \PDO::PARAM_STR);
        }
    }

    private function orderBy(string $sort): string
    {
        return match ($sort) {
            'most_viewed' => 'a.view_count DESC, a.created_at DESC',
            'name_asc'    => 'a.original_name ASC',
            default       => 'a.created_at DESC',
        };
    }

    /**
     * Count one server hit on the asset stored at the given relative path.
     *
     * Keyed on `path` rather than `id` because the only place a view is
     * observable is the serving route, which receives the path
     * (`Y/m/d/{hex}.{ext}`) and never the id. An untracked file updates
     * nothing — the WHERE simply matches no row.
     *
     * `view_count = view_count + 1` is a single statement, so SQLite's
     * write lock makes concurrent serves safe without a read-modify-write.
     */
    public function incrementViewByPath(string $path): void
    {
        $stmt = Statement::prepare($this->pdo, 'UPDATE assets SET view_count = view_count + 1 WHERE path = :path');
        $stmt->execute([':path' => $path]);
    }

    /**
     * @param list<string> $tags
     */
    public function setTagsForAsset(string $id, array $tags): void
    {
        $this->deleteTagsForAsset($id);

        if ([] === $tags) {
            return;
        }

        $stmt = Statement::prepare($this->pdo, 'INSERT INTO media_tags (asset_id, tag) VALUES (:asset_id, :tag)');
        foreach ($tags as $tag) {
            $tag = trim(strtolower($tag));
            if ('' === $tag) {
                continue;
            }
            $stmt->execute([':asset_id' => $id, ':tag' => $tag]);
        }
    }

    public function deleteTagsForAsset(string $id): void
    {
        $stmt = Statement::prepare($this->pdo, 'DELETE FROM media_tags WHERE asset_id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * @param list<string> $ids
     */
    public function deleteTagsForAssets(array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        Statement::prepare($this->pdo, "DELETE FROM media_tags WHERE asset_id IN ({$placeholders})")->execute($ids);
    }

    /**
     * @return list<string>
     */
    public function getAllCategories(): array
    {
        $stmt = Statement::query($this->pdo, "SELECT DISTINCT category FROM assets WHERE category != '' ORDER BY category");

        /** @var list<string> $categories */
        $categories = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        return $categories;
    }

    /**
     * @return list<string>
     */
    public function getAllTags(): array
    {
        $stmt = Statement::query($this->pdo, 'SELECT DISTINCT tag FROM media_tags ORDER BY tag');

        /** @var list<string> $tags */
        $tags = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        return $tags;
    }

    /**
     * @param \PDOStatement $stmt
     *
     * @return list<MediaAsset>
     */
    private function fetchAll(\PDOStatement $stmt): array
    {
        $assets = [];
        while (true) {
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (false === $row) {
                break;
            }
            $assets[] = MediaAsset::fromRow($row);
        }

        return $assets;
    }
}
