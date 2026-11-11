<?php

declare(strict_types = 1);

namespace Modules\Layout;

/**
 * ThemeSetting — one field a theme declares as admin-editable.
 *
 * The value object exists so the three places that must agree read the SAME
 * rule: the admin form (what to render and which option is current), the save
 * path (what may be stored), and the renderer (what the theme gets). Before
 * this, every admin `<select>` carried its own whitelist in an `if` next to
 * the form — the pattern that made a rejected value silently look saved.
 *
 * `select` is the only interactive type a theme needs today, but `text` is
 * here because the contract is the theme's, not this feature's: a theme with a
 * tagline or a URL should not have to grow a second interface to say so.
 */
final readonly class ThemeSetting
{
    /**
     * @param array<string, string> $options value => label, for `select` fields
     */
    private function __construct(
        public string $key,
        public string $label,
        public string $type,
        public array $options,
        public string $default,
    ) {
    }

    /**
     * A closed set of choices — the shape the nav-icon choice uses.
     *
     * @param array<string, string> $options
     */
    public static function select(string $key, string $label, array $options, string $default): self
    {
        return new self($key, $label, 'select', $options, $default);
    }

    /** Free text — stored trimmed, never empty (an empty field falls back to the default). */
    public static function text(string $key, string $label, string $default): self
    {
        return new self($key, $label, 'text', [], $default);
    }

    /** Whether this value may be stored or rendered for this field. */
    public function accepts(string $value): bool
    {
        // Trim BEFORE the lookup: a posted ` meatball ` is the same choice a
        // human made, and rejecting it would silently reset the field to the
        // default on every save made by a browser that pads the value.
        $trimmed = trim($value);

        return match ($this->type) {
            'select' => array_key_exists($trimmed, $this->options),
            default  => '' !== $trimmed && 200 >= strlen($trimmed),
        };
    }

    /** The label for a stored value — the raw value when the label is unknown. */
    public function optionLabel(string $value): string
    {
        return $this->options[$value] ?? $value;
    }

    /**
     * Whether the declared default is one the field would accept.
     *
     * A default outside `options` is a theme bug, not a runtime error: it is
     * logged by `ThemeSettingValues` and the first option renders instead, so
     * a typo never becomes a select with nothing selected.
     */
    public function hasAcceptableDefault(): bool
    {
        return $this->accepts($this->default);
    }

    /** The value to use when the declared default is unusable (first option / raw default). */
    public function fallbackValue(): string
    {
        if ($this->hasAcceptableDefault()) {
            return $this->default;
        }

        $first = array_key_first($this->options);

        return null !== $first ? (string) $first : '';
    }
}
