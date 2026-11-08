<?php

declare(strict_types = 1);

namespace Modules\Settings\Infrastructure\Persistence\Repositories;

use Modules\Settings\Infrastructure\Persistence\Statement;

/**
 * SettingsRepository — key/value settings + design tokens persistence.
 */
final class SettingsRepository
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly \PDO $pdo)
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

        $stmt = Statement::prepare($this->pdo,
            'INSERT INTO settings (id, setting_key, setting_value, value_type, group_name, is_public, created_at, updated_at)
             VALUES (:id, :key, :value, :type, :group, :public, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON CONFLICT(setting_key) DO UPDATE SET
               setting_value = excluded.setting_value,
               value_type = excluded.value_type,
               group_name = excluded.group_name,
               is_public = excluded.is_public,
               updated_at = CURRENT_TIMESTAMP',
        );
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
        Statement::prepare($this->pdo, 'DELETE FROM settings WHERE setting_key = :key')->execute([':key' => $key]);
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

        $stmt = Statement::query($this->pdo, 'SELECT setting_key, setting_value, value_type FROM settings');
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
        $stmt = Statement::query($this->pdo,
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
        $stmt = Statement::query($this->pdo, 'SELECT token_key, token_value FROM design_tokens');
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

        Statement::prepare($this->pdo,
            'INSERT INTO design_tokens (id, token_key, token_value, created_at, updated_at)
             VALUES (:id, :key, :value, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON CONFLICT(token_key) DO UPDATE SET token_value = excluded.token_value, updated_at = CURRENT_TIMESTAMP',
        )->execute([
            ':id'    => bin2hex(random_bytes(16)),
            ':key'   => $key,
            ':value' => $json,
        ]);
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
