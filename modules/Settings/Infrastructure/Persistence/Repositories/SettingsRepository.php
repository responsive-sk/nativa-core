<?php

declare(strict_types = 1);

namespace Modules\Settings\Infrastructure\Persistence\Repositories;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Settings\Infrastructure\Persistence\Statement;

/**
 * SettingsRepository — key/value settings + design tokens persistence.
 */
final class SettingsRepository
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    /**
     * Get a setting value by key, cast to its stored type.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    /**
     * Set a setting value (create or update, typed).
     */
    public function set(string $key, mixed $value, string $group = 'general', bool $isPublic = false): void
    {
        $type = $this->detectType($value);
        if (is_array($value)) {
            $json = json_encode($value);
            $stored = false === $json ? '' : $json;
        } elseif (is_scalar($value) || null === $value) {
            $stored = (string) ($value ?? '');
        } else {
            $stored = '';
        }

        $stmt = Statement::prepare($this->connection, $this->upsert(
            'INSERT INTO settings (id, setting_key, setting_value, value_type, group_name, is_public, created_at, updated_at)
             VALUES (:id, :key, :value, :type, :group, :public, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            'setting_key',
            ['setting_value', 'value_type', 'group_name', 'is_public'],
            ['updated_at'],
        ));
        $stmt->execute([
            ':id'     => bin2hex(random_bytes(16)),
            ':key'    => $key,
            ':value'  => $stored,
            ':type'   => $type,
            ':group'  => $group,
            ':public' => $isPublic ? 1 : 0,
        ]);

        $this->cache = null; // invalidate (unset would leave the typed property uninitialized)
    }

    public function delete(string $key): void
    {
        Statement::prepare($this->connection, 'DELETE FROM settings WHERE setting_key = :key')->execute([':key' => $key]);
        $this->cache = null;
    }

    /**
     * All settings as key → typed value.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $stmt = Statement::query($this->connection, 'SELECT setting_key, setting_value, value_type FROM settings');
        $rows = $stmt->fetchAll();
        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row['setting_key']] = $this->cast((string) $row['setting_value'], (string) $row['value_type']);
        }

        return $this->cache = $result;
    }

    /**
     * Public (non-sensitive) settings as key → typed value.
     *
     * @return array<string, mixed>
     */
    public function publicOnly(): array
    {
        $stmt = Statement::query($this->connection,
            'SELECT setting_key, setting_value, value_type FROM settings WHERE is_public = 1',
        );
        $rows = $stmt->fetchAll();
        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row['setting_key']] = $this->cast((string) $row['setting_value'], (string) $row['value_type']);
        }

        return $result;
    }

    /**
     * Get design tokens (JSON key → value map).
     *
     * @return array<string, mixed>
     */
    public function designTokens(): array
    {
        $stmt = Statement::query($this->connection, 'SELECT token_key, token_value FROM design_tokens');
        $rows = $stmt->fetchAll();
        $result = [];

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['token_value'], true);
            $result[(string) $row['token_key']] = is_array($decoded) ? $decoded : (string) $row['token_value'];
        }

        return $result;
    }

    /**
     * Set a design token.
     */
    public function setDesignToken(string $key, mixed $value): void
    {
        $encoded = json_encode($value);
        $json = false === $encoded ? '""' : $encoded;

        Statement::prepare($this->connection, $this->upsert(
            'INSERT INTO design_tokens (id, token_key, token_value, created_at, updated_at)
             VALUES (:id, :key, :value, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            'token_key',
            ['token_value'],
            ['updated_at'],
        ))->execute([
            ':id'    => bin2hex(random_bytes(16)),
            ':key'   => $key,
            ':value' => $json,
        ]);
    }

    /**
     * An INSERT with an upsert clause, in the dialect the connection speaks.
     *
     * SQLite spells it `ON CONFLICT (col) DO UPDATE SET col = excluded.col`;
     * MariaDB and MySQL spell it `ON DUPLICATE KEY UPDATE col = VALUES(col)`.
     * The two are not interchangeable, which is why the repository asks the
     * connection for its driver instead of pinning the file dialect.
     *
     * @param list<string> $updates    columns overwritten from the proposed row
     * @param list<string> $timestamps columns reset to now
     */
    private function upsert(string $insert, string $conflict, array $updates, array $timestamps = []): string
    {
        $mysql = 'mysql' === $this->connection->driverName();
        $assignments = [];

        foreach ($updates as $column) {
            $assignments[] = $mysql
                ? $column . ' = VALUES(' . $column . ')'
                : $column . ' = excluded.' . $column;
        }

        foreach ($timestamps as $column) {
            $assignments[] = $column . ' = CURRENT_TIMESTAMP';
        }

        return $insert . ($mysql
            ? ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments)
            : ' ON CONFLICT(' . $conflict . ') DO UPDATE SET ' . implode(', ', $assignments));
    }

    private function detectType(mixed $value): string
    {
        return match (true) {
            is_bool($value)  => 'boolean',
            is_int($value)   => 'integer',
            is_array($value) => 'json',
            default          => 'string',
        };
    }

    private function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => in_array($value, ['1', 'true', 'on'], true),
            'integer' => (int) $value,
            'json'    => json_decode($value, true),
            default   => $value,
        };
    }
}
