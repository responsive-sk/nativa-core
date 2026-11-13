<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Blog\Infrastructure\Persistence\Statement;
use Modules\Markdown\Application\Services\MarkdownRenderer;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;

/**
 * BlogAuthorResolver — resolve a blog article's optional author_email
 * front-matter key to the linked active user's public profile at render time.
 *
 * Contract coupling: reads only the users table columns email/name/id/avatar/
 * created_at/is_active directly through the shared kernel (Database), so Core
 * keeps its position as the shared kernel — no reverse import of the Users
 * feature module (the isolation test forbids Core's Application layer from
 * reaching into feature modules). Lookups are a pure display concern: on a
 * hit the rendered name follows users.name (renames propagate automatically),
 * otherwise callers fall back to the plain author string. They must never
 * throw — a missing or unmigrated users.db must not break the blog.
 */
final readonly class BlogAuthorResolver
{
    public function __construct(private DatabaseConnectionManager $manager)
    {
    }

    /**
     * Resolve an email to the linked user's display name, or fall back.
     */
    public function resolve(string $email, string $fallback): string
    {
        $profile = $this->profile($email);

        return null === $profile ? $fallback : $profile['name'];
    }

    /**
     * Resolve an email to the linked active user's public profile.
     *
     * @return array{id: string, name: string, avatar: ?string}|null
     */
    public function profile(string $email): ?array
    {
        $row = $this->lookup(
            'SELECT id, name, avatar FROM users WHERE email = :email COLLATE NOCASE AND is_active = 1 LIMIT 1',
            [':email' => $email],
        );

        if (null === $row) {
            return null;
        }

        // The email profile shape is deliberately narrower — no timestamps.
        return [
            'id'     => $row['id'],
            'name'   => $row['name'],
            'avatar' => $row['avatar'],
        ];
    }

    /**
     * Resolve a user id to the active user's public profile. The email is a
     * lookup key only and is deliberately NOT part of the returned shape —
     * it never leaves the resolver, so it can never be rendered.
     *
     * @return array{id: string, name: string, avatar: ?string, memberSince: string}|null
     */
    public function profileById(string $id): ?array
    {
        $row = $this->lookup(
            'SELECT id, name, avatar, created_at FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
            [':id' => $id],
        );

        if (null === $row) {
            return null;
        }

        return $this->withMemberSince($row);
    }

    /**
     * Resolve a URL slug (the diacritics-folded author name, e.g. nativa-dev)
     * to the active user's public profile. The slug is derived from the
     * display name via MarkdownRenderer::slugify() — matching the same
     * convention used for tags/categories — so renaming a user in /mark/users
     * changes their public URL. Returns null when no active user matches.
     *
     * @return array{id: string, name: string, avatar: ?string, memberSince: string}|null
     */
    public function profileBySlug(string $slug): ?array
    {
        $rows = $this->allActive();

        foreach ($rows as $row) {
            if (MarkdownRenderer::slugify($row['name']) === $slug) {
                return $this->withMemberSince($row);
            }
        }

        return null;
    }

    /**
     * Run a bounded SELECT against users.db. Never throws — a missing table or
     * connection degrades to null so callers fall back to plain text. Returns
     * the raw normalized row — callers decide the public shape via
     * withMemberSince() (applied exactly once, or not at all for email lookups).
     *
     * @param array<string, string> $params
     *
     * @return array{id: string, name: string, avatar: ?string, created_at: string}|null
     */
    private function lookup(string $sql, array $params): ?array
    {
        try {
            $stmt = Statement::prepare($this->manager->connect('users'), $sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
        } catch (\PDOException) {
            return null;
        }

        if (false === $row || !is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */

        return [
            'id'         => is_string($row['id'] ?? null) ? $row['id'] : '',
            'name'       => is_string($row['name'] ?? null) ? $row['name'] : '',
            'avatar'     => is_string($row['avatar'] ?? null) && '' !== $row['avatar'] ? $row['avatar'] : null,
            'created_at' => is_string($row['created_at'] ?? null) ? $row['created_at'] : '',
        ];
    }

    /**
     * All active users — the slug lookup scans this small set (the public
     * blog author surface) instead of maintaining a separate slug column.
     *
     * @return list<array{id: string, name: string, avatar: ?string, created_at: string}>
     */
    private function allActive(): array
    {
        try {
            $stmt = Statement::query(
                $this->manager->connect('users'),
                'SELECT id, name, avatar, created_at FROM users WHERE is_active = 1',
            );
            $rows = $stmt->fetchAll();
        } catch (\PDOException) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $normalized[] = [
                'id'         => is_string($row['id'] ?? null) ? $row['id'] : '',
                'name'       => is_string($row['name'] ?? null) ? $row['name'] : '',
                'avatar'     => is_string($row['avatar'] ?? null) && '' !== $row['avatar'] ? $row['avatar'] : null,
                'created_at' => is_string($row['created_at'] ?? null) ? $row['created_at'] : '',
            ];
        }

        return $normalized;
    }

    /**
     * @param array{id: string, name: string, avatar: ?string, created_at?: string} $row
     *
     * @return array{id: string, name: string, avatar: ?string, memberSince: string}
     */
    private function withMemberSince(array $row): array
    {
        return [
            'id'          => $row['id'],
            'name'        => $row['name'],
            'avatar'      => $row['avatar'],
            'memberSince' => is_string($row['created_at'] ?? null) ? $row['created_at'] : '',
        ];
    }
}
