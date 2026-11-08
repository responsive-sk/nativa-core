<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Markdown;

/**
 * MarkdownBlockContext — what a block needs to expand a placeholder.
 *
 * A block cannot guess the visitor's language or the theme's CSS prefix: both
 * belong to the request, and Core has no ambient request to read them from
 * (a service locator for the request would be exactly the magic this kit
 * refuses). So the renderer takes the context as an argument and every caller
 * that knows the request passes it.
 *
 * `cssPrefix` is the resolved theme prefix (`st`, `er`, `ms`, `mag` — NO
 * trailing dash; that is what `ThemeModule::prefix()` returns and what every
 * other prefixed class in the kit is built from) so a block's markup can be
 * theme-native without importing anything from `Themes\` — the only file
 * allowed to do that is `ThemeKernel`.
 *
 * Two more request facts belong here for the same reason:
 *
 *  - `path` — the embedding request path as `getPathInfo()` returns it, with
 *    the locale already stripped by `LocaleMiddleware`. A form block posts
 *    back to the subject it was embedded in, and a block cannot read
 *    `Request` to learn where that is.
 *  - `blockState` — the per-block state a re-render carries back: the errors,
 *    the invalid field keys and the old input of a submission that failed
 *    validation. Keyed by block name, so Core learns no module's vocabulary:
 *    the Forms module reads `blockState['forms']` and another block owns
 *    another key.
 *
 * Both default to empty, so a caller that has no request (CLI, docs, tests)
 * keeps constructing the context exactly as before.
 */
final readonly class MarkdownBlockContext
{
    /**
     * Keyed by block name, one entry per block. Typed `mixed` on purpose: the
     * value arrives from a request attribute, which carries anything, so
     * `stateFor()` is where the narrowing happens rather than a docblock
     * claiming a shape the attribute never promised.
     *
     * @param array<string, mixed> $blockState
     */
    public function __construct(
        public string $locale,
        public string $cssPrefix = 'st',
        public string $path = '',
        public array $blockState = [],
    ) {
    }

    /**
     * Context for a caller that has no request at hand (CLI, tests) — the
     * default locale of the content tree, which is where the configured
     * default lives.
     */
    public static function default(): self
    {
        return new self(locale: '');
    }

    /**
     * The state one block was handed for this render, normalised.
     *
     * The attribute values are `mixed` by construction — anything may have
     * written them — so the cast happens at the seam that admits it rather
     * than in every block.
     *
     * @return array{errors: list<string>, invalid: list<string>, old: array<string, string>, sent: bool}
     */
    public function stateFor(string $block): array
    {
        $state = $this->blockState[$block] ?? [];
        $state = is_array($state) ? $state : [];

        $strings = static function (mixed $values): array {
            $strings = [];

            foreach (is_array($values) ? $values : [] as $value) {
                if (is_string($value) || is_numeric($value)) {
                    $strings[] = (string) $value;
                }
            }

            return $strings;
        };

        $old = [];
        $rawOld = $state['old'] ?? null;

        foreach (is_array($rawOld) ? $rawOld : [] as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $old[$key] = (string) $value;
            }
        }

        return [
            'errors'  => $strings($state['errors'] ?? []),
            'invalid' => $strings($state['invalid'] ?? []),
            'old'     => $old,
            'sent'    => true === ($state['sent'] ?? false),
        ];
    }
}
