<?php

declare(strict_types = 1);

namespace Modules\Media\Domain\Model;

/**
 * MediaAsset — immutable value object representing a tracked uploaded file.
 *
 * Created by MediaService::upload(), stored in media.db, and served from
 * the existing LocalStorageProvider filesystem under storage/uploads/.
 */
final readonly class MediaAsset
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        private string $id,
        private string $filename,
        private string $originalName,
        private string $path,
        private int $size,
        private string $mimeType,
        private string $uploadedBy,
        private string $createdAt,
        private string $altText = '',
        private string $caption = '',
        private string $provider = '',
        private string $category = '',
        private string $folder = '',
        private array $tags = [],
        private int $viewCount = 0,
    ) {
    }

    /**
     * @param list<string> $tags
     */
    public static function create(
        string $filename,
        string $originalName,
        string $path,
        int $size,
        string $mimeType,
        string $uploadedBy,
        string $provider = '',
        string $category = '',
        string $folder = '',
        array $tags = [],
    ): self {
        return new self(
            id: bin2hex(random_bytes(16)),
            filename: $filename,
            originalName: $originalName,
            path: $path,
            size: $size,
            mimeType: $mimeType,
            uploadedBy: $uploadedBy,
            createdAt: date('Y-m-d H:i:s'),
            provider: $provider,
            category: $category,
            folder: $folder,
            tags: $tags,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::s($row['id'] ?? ''),
            filename: self::s($row['filename'] ?? ''),
            originalName: self::s($row['original_name'] ?? ''),
            path: self::s($row['path'] ?? ''),
            size: self::i($row['size'] ?? 0),
            mimeType: self::s($row['mime_type'] ?? ''),
            uploadedBy: self::s($row['uploaded_by'] ?? ''),
            createdAt: self::s($row['created_at'] ?? ''),
            altText: self::s($row['alt_text'] ?? ''),
            caption: self::s($row['caption'] ?? ''),
            provider: self::s($row['provider'] ?? ''),
            category: self::s($row['category'] ?? ''),
            folder: self::s($row['folder'] ?? ''),
            viewCount: self::i($row['view_count'] ?? 0),
        );
    }

    private static function s(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private static function i(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function uploadedBy(): string
    {
        return $this->uploadedBy;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function altText(): string
    {
        return $this->altText;
    }

    public function caption(): string
    {
        return $this->caption;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function category(): string
    {
        return $this->category;
    }

    /**
     * The folder path this asset lives in ('' = root). For a Cloudinary
     * asset it is the remote folder it was imported from — a local
     * reference to where the file came from, not a copy of the bytes.
     */
    public function folder(): string
    {
        return $this->folder;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    public function viewCount(): int
    {
        return $this->viewCount;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    /**
     * @return array{id: string, filename: string, original_name: string, path: string, size: int, mime_type: string, uploaded_by: string, created_at: string, alt_text: string, caption: string, provider: string, category: string, folder: string, view_count: int}
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'filename'      => $this->filename,
            'original_name' => $this->originalName,
            'path'          => $this->path,
            'size'          => $this->size,
            'mime_type'     => $this->mimeType,
            'uploaded_by'   => $this->uploadedBy,
            'created_at'    => $this->createdAt,
            'alt_text'      => $this->altText,
            'caption'       => $this->caption,
            'provider'      => $this->provider,
            'category'      => $this->category,
            'folder'        => $this->folder,
            'view_count'    => $this->viewCount,
        ];
    }
}
