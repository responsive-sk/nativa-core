<?php

declare(strict_types = 1);

namespace Modules\Locales\Domain\Model;

/**
 * Locale Value Object.
 *
 * Immutable representation of a locale code in ll_CC format (e.g. sk_SK, en_US).
 * The available set is the single source of truth for the whole Locales module:
 * URL prefixes, the admin switcher and the seeder all derive from it.
 */
final readonly class Locale implements \Stringable
{
    /** @var array<string, string> Known locale codes mapped to their display names */
    private const array AVAILABLE = [
        'sk_SK' => 'Slovenčina',
        'en_US' => 'English',
    ];

    private function __construct(private string $code)
    {
    }

    public function __toString(): string
    {
        return $this->code;
    }

    /**
     * Create from string with validation.
     *
     * @throws \InvalidArgumentException When the code is not a valid ll_CC format
     */
    public static function fromString(string $code): self
    {
        $code = trim($code);

        if (!self::isValidFormat($code)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid locale format: "%s". Expected ll_CC format (e.g. sk_SK, en_US).',
                $code,
            ));
        }

        return new self($code);
    }

    /**
     * Get the locale code (e.g. sk_SK).
     */
    public function code(): string
    {
        return $this->code;
    }

    /**
     * Get the language part (e.g. sk, en).
     */
    public function language(): string
    {
        return explode('_', $this->code)[0];
    }

    /**
     * Get the region part (e.g. SK, US).
     */
    public function region(): string
    {
        $parts = explode('_', $this->code);

        return $parts[1] ?? '';
    }

    /**
     * Get the human-readable name (e.g. Slovenčina, English).
     */
    public function name(): string
    {
        return self::AVAILABLE[$this->code] ?? $this->code;
    }

    /**
     * Get all available locales keyed by code.
     *
     * @return array<string, Locale>
     */
    public static function available(): array
    {
        $locales = [];

        foreach (self::AVAILABLE as $code => $name) {
            $locales[$code] = new self($code);
        }

        return $locales;
    }

    /**
     * Check if this locale is available in the system.
     */
    public function isAvailable(): bool
    {
        return isset(self::AVAILABLE[$this->code]);
    }

    /**
     * Get the built-in default locale.
     */
    public static function default(): self
    {
        return new self('sk_SK');
    }

    /**
     * Compare with another Locale.
     */
    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    /**
     * Validate locale code format: two lowercase letters, underscore, two uppercase letters.
     */
    private static function isValidFormat(string $code): bool
    {
        return (bool) preg_match('/^[a-z]{2}_[A-Z]{2}$/', $code);
    }
}
