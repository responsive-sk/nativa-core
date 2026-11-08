<?php

declare(strict_types = 1);

namespace Modules\Core\Domain;

use Themes\ThemeSettings;

/**
 * ThemeSettingValues — where per-theme settings are stored and how they are
 * resolved back into renderable values.
 *
 * Two facts this class owns, both of which used to have no owner:
 *
 * 1. **The storage key.** Values live in the one settings table as
 *    `theme.{theme}.{field}` (group `design`, private). The prefix is what
 *    makes "each theme keeps its own values" true while only the ACTIVE
 *    theme's block is on screen — switching the public theme to Mono does
 *    not overwrite what Boson was configured with, and switching back
 *    restores it.
 * 2. **The resolution rule.** The stored row wins only when the theme's own
 *    schema still accepts it; otherwise the field's default applies. That is
 *    what keeps a hand-written value in the database, or a value retired from
 *    the schema, from reaching markup the theme never designed for.
 *
 * Pure on purpose — no settings service, no database, no theme registry. The
 * caller supplies the schema and the stored map, which is what makes this
 * unit-testable without a migrated tree (same split as `ThemePreference`).
 */
final class ThemeSettingValues
{
    /** The settings row a field is stored in. */
    public static function storageKey(string $theme, string $key): string
    {
        return self::storagePrefix($theme) . $key;
    }

    /**
     * `theme.boson.` — the prefix every row of one theme shares.
     *
     * Spelled here and nowhere else: a second copy of the prefix is how a
     * writer and a reader end up disagreeing about where theme values live,
     * and the disagreement shows up as settings that save and never apply.
     */
    public static function storagePrefix(string $theme): string
    {
        return 'theme.' . $theme . '.';
    }

    /**
     * The field name a stored row belongs to, or null when the row is not this
     * theme's. Used when reading a whole settings table back.
     */
    public static function fieldFromStorageKey(string $theme, string $storageKey): ?string
    {
        $prefix = self::storagePrefix($theme);

        if (!str_starts_with($storageKey, $prefix)) {
            return null;
        }

        $field = substr($storageKey, strlen($prefix));

        return '' !== $field ? $field : null;
    }

    /**
     * Stored values merged with the schema's defaults — exactly what a theme
     * may render.
     *
     * A theme without the contract answers `[]`: there is no schema, so there
     * is nothing a stored row could be validated against, and handing one over
     * would let a value from a previous theme outlive it.
     *
     * @param array<string, mixed> $stored
     *
     * @return array<string, string>
     */
    public static function resolve(?ThemeSettings $theme, array $stored): array
    {
        if (null === $theme) {
            return [];
        }

        $values = [];
        $seen = [];

        foreach ($theme->settingsSchema() as $field) {
            if (isset($seen[$field->key])) {
                error_log('[ThemeSettings] duplicate field `' . $field->key . '` in ' . $theme::class . ' — first wins');

                continue;
            }

            $seen[$field->key] = true;

            if (!$field->hasAcceptableDefault()) {
                error_log('[ThemeSettings] field `' . $field->key . '` default `' . $field->default
                    . '` is not an accepted value in ' . $theme::class . ' — falling back to `' . $field->fallbackValue() . '`');
            }

            $raw = $stored[$field->key] ?? null;
            $candidate = is_string($raw) ? trim($raw) : '';

            if ('' !== $candidate && $field->accepts($candidate)) {
                $values[$field->key] = $candidate;

                continue;
            }

            if ('' !== $candidate) {
                error_log('[ThemeSettings] dropped `' . $field->key . '=' . $candidate . '` — not in ' . $theme::class . ' schema');
            }

            $values[$field->key] = $field->fallbackValue();
        }

        return $values;
    }

    /**
     * The value to store for one field, or null when the theme does not have
     * that field at all.
     *
     * Invalid input is not an error worth failing the save for: the admin
     * posted a form rendered from THIS schema, so an unknown value means the
     * request was hand-made or the schema changed mid-session. The default is
     * the honest answer, and the caller logs the substitution.
     */
    public static function sanitize(?ThemeSettings $theme, string $key, string $value): ?string
    {
        if (null === $theme) {
            return null;
        }

        foreach ($theme->settingsSchema() as $field) {
            if ($field->key !== $key) {
                continue;
            }

            return $field->accepts($value) ? trim($value) : $field->fallbackValue();
        }

        return null;
    }
}
