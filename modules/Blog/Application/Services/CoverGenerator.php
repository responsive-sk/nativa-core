<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use InvalidArgumentException;

/**
 * CoverGenerator — deterministic, series-aware blog cover generation.
 *
 * Replaces the SVG assembly that used to live in the Core CLI command (v1):
 * the same article always produces a byte-identical SVG (no timestamps,
 * purely positional extraction), series get a shared identity from explicit
 * front matter (`series:` + `series-part:`), and covers can carry a
 * code-evidence element — a terminal-window snippet extracted from the
 * article's own body, so the cover proves it belongs to the article.
 *
 * Identity rules:
 *  - explicit CLI `--template`/`--color` win over everything,
 *  - a series name hashes to one identity shared by all its members,
 *  - a series-less slug hashes to its own stable identity,
 *  - SK/EN translation pairs each extract content from their own body.
 *
 * Provenance is contract-tested (CoverCodeIsFromArticleTest): every
 * auto-extracted snippet line must be literally contained in the article.
 */
final class CoverGenerator
{
    public const int WIDTH = 720;

    public const int HEIGHT = 360;

    public const int DEFAULT_CODE_LINES = 5;

    /** Monospace cell budget per rendered snippet line (11 px ≈ 68 chars). */
    public const int MAX_LINE_CHARS = 68;

    /** @var array<string, string> */
    public const array TEMPLATES = [
        'code' => 'code-pattern',
        'grid' => 'grid-pattern',
        'wave' => 'wave-pattern',
        'circuit' => 'circuit-pattern',
        'gradient' => 'gradient-pattern',
        'noise' => 'noise-pattern',
        'abstract' => 'abstract-pattern',
        'rust' => 'rust-pattern',
    ];

    /**
     * Named palettes as data — dark-first backgrounds (the blog is
     * dark-first), one accent per palette. Order is the identity contract:
     * the hash index points into IDENTITIES below, which pairs each palette
     * with a template.
     *
     * @var array<string, array{bg: string, accent: string}>
     */
    public const array PALETTES = [
        'blue' => ['bg' => '#0f172a', 'accent' => '#3b82f6'],
        'green' => ['bg' => '#0f172a', 'accent' => '#10b981'],
        'orange' => ['bg' => '#0f172a', 'accent' => '#DEA584'],
        'purple' => ['bg' => '#0f172a', 'accent' => '#8b5cf6'],
        'cyan' => ['bg' => '#0f172a', 'accent' => '#06b6d4'],
    ];

    /**
     * Deterministic identity table — hash index → [template, palette].
     * "Rust & Lekalo" hashes to index 2 → rust/orange, matching the series'
     * committed covers.
     *
     * @var list<array{string, string}>
     */
    private const array IDENTITIES = [
        ['code', 'blue'],
        ['grid', 'green'],
        ['rust', 'orange'],
        ['wave', 'purple'],
        ['circuit', 'cyan'],
    ];

    private const string FONT_SANS = 'Segoe UI, Roboto, sans-serif';

    private const string FONT_MONO = 'monospace';

    /** Diff-row inks/tints — decorative (≥ 3:1 on every dark palette bg). */
    private const string DIFF_ADD_INK = '#86efac';

    private const string DIFF_ADD_TINT = '#22c55e';

    private const string DIFF_REMOVE_INK = '#fca5a5';

    private const string DIFF_REMOVE_TINT = '#ef4444';

    /**
     * The muted slate both roles share: an unchanged diff row, and the version
     * stamp on a dev build (LTS carries the accent instead). It is ONE colour in
     * one palette, so it has one name — `VERSION_INK_MUTED` claimed a second
     * name for this literal and used it once, which is the defect rule 1 of
     * `NameOwnershipContractTest` exists to catch.
     *
     * Not to be confused with `TERMINAL_LABEL`, which is a different grey.
     */
    private const string DIFF_CONTEXT_INK = '#94a3b8';

    private const string TERMINAL_BG = '#0b1222';

    private const string TERMINAL_HEADER = '#16213a';

    private const string TERMINAL_LABEL = '#64748b';

    /**
     * Generate the cover SVG for one article. Byte-deterministic: identical
     * inputs always render identical bytes.
     *
     * @param array<string, mixed> $meta Front matter (title, category, series, series-part, cover-code, …)
     * @param string               $body Article body below the front matter
     */
    public function generate(
        string $slug,
        array $meta,
        string $body,
        string $locale = 'sk',
        ?string $template = null,
        ?string $palette = null,
        ?int $codeLines = null,
    ): string {
        $identity = $this->resolveIdentity(
            is_string($meta['series'] ?? null) ? $meta['series'] : null,
            $slug,
        );
        $template ??= $identity['template'];
        $palette ??= $identity['palette'];

        if (!isset(self::TEMPLATES[$template]) || !isset(self::PALETTES[$palette])) {
            throw new InvalidArgumentException('Unknown cover template or palette: ' . $template . '/' . $palette);
        }

        $snippet = $this->extractSnippet($meta, $body, $codeLines ?? self::DEFAULT_CODE_LINES);

        return $this->renderSvg($slug, $meta, $body, $locale, $template, $palette, $snippet);
    }

    /**
     * Resolve the (template, palette) identity for an article: a series name
     * hashes to one identity shared by all its members; a series-less slug
     * hashes to its own stable identity. Pure function of the input string.
     *
     * @return array{template: string, palette: string}
     */
    public function resolveIdentity(?string $series, string $slug): array
    {
        $seed = null !== $series && '' !== trim($series) ? $series : $slug;
        $index = abs(crc32($seed)) % count(self::IDENTITIES);
        [$template, $palette] = self::IDENTITIES[$index];

        return ['template' => $template, 'palette' => $palette];
    }

    /**
     * Deterministic snippet extraction (the evidence chain):
     * front-matter `cover-code:` override → the first diff-fenced code block →
     * the first fenced block → null (pattern-only cover). Lines are
     * hard-truncated to MAX_LINE_CHARS; rendering escapes them.
     *
     * @param array<string, mixed> $meta
     *
     * @return array{source: string, lines: list<string>}|null
     */
    public function extractSnippet(array $meta, string $body, int $maxLines = self::DEFAULT_CODE_LINES): ?array
    {
        $override = $meta['cover-code'] ?? null;

        if (is_string($override) && '' !== trim($override)) {
            return ['source' => 'override', 'lines' => $this->clip($override, $maxLines)];
        }

        if (1 === preg_match('/```diff\s*\n(.*?)```/s', $body, $match)) {
            return ['source' => 'diff', 'lines' => $this->clip($match[1], $maxLines)];
        }

        if (1 === preg_match('/```[a-zA-Z0-9_-]*\s*\n(.*?)```/s', $body, $match)) {
            return ['source' => 'code', 'lines' => $this->clip($match[1], $maxLines)];
        }

        return null;
    }

    /**
     * Cache-busting URL for a regenerated asset: appends ?v=<8 hex of the
     * file's md5> so every regeneration produces a fresh URL instead of
     * serving a year-old cover from the immutable browser cache.
     */
    public function versionedUrl(string $url, string $filePath): string
    {
        return $url . '?v=' . substr((string) md5_file($filePath), 0, 8);
    }

    /**
     * Split fenced-block content into clipped, trimmed snippet lines.
     *
     * @return list<string>
     */
    private function clip(string $block, int $maxLines): array
    {
        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $block)),
            static fn (string $line): bool => '' !== $line,
        ));

        return array_slice(
            array_map(fn (string $line): string => $this->truncate($line, self::MAX_LINE_CHARS), $lines),
            0,
            $maxLines,
        );
    }

    /**
     * Hard truncate with a single-character ellipsis.
     */
    private function truncate(string $value, int $maxChars): string
    {
        return mb_strlen($value) > $maxChars
            ? mb_substr($value, 0, $maxChars - 1) . '…'
            : $value;
    }

    /**
     * Escape a value for safe inclusion in an SVG text node.
     */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Assemble the full cover SVG.
     *
     * @param array<string, mixed>                            $meta
     * @param array{source: string, lines: list<string>}|null $snippet
     */
    private function renderSvg(
        string $slug,
        array $meta,
        string $body,
        string $locale,
        string $template,
        string $palette,
        ?array $snippet,
    ): string {
        $colors = self::PALETTES[$palette];
        $bg = $colors['bg'];
        $accent = $colors['accent'];
        $part = is_string($meta['series-part'] ?? null) ? $meta['series-part'] : '';
        $series = is_string($meta['series'] ?? null) ? $meta['series'] : '';

        $pattern = $this->pattern($template, $accent);
        $terminal = null !== $snippet && [] !== $snippet['lines']
            ? $this->renderTerminal($snippet, $slug)
            : '';
        $badge = '' !== trim($series)
            ? $this->renderBadge(trim($series), trim($part), $locale, $accent)
            : '';
        $versionStamp = $this->renderVersionStamp(
            is_string($meta['version'] ?? null) ? trim((string) $meta['version']) : '',
            $accent,
        );
        $ghost = '' !== trim($part)
            ? '  <text x="664" y="272" text-anchor="end" font-family="' . self::FONT_SANS . '" font-size="170" font-weight="bold" fill="' . $accent . '" opacity="0.07">' . $this->esc(trim($part)) . '</text>' . "\n"
            : '';
        $title = $this->esc($this->truncate(is_string($meta['title'] ?? null) ? $meta['title'] : ucfirst($slug), 40));
        $category = $this->esc(mb_strtoupper(is_string($meta['category'] ?? null) ? $meta['category'] : ''));

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 720 360" width="720" height="360">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:{$bg}"/>
      <stop offset="100%" style="stop-color:#1e293b"/>
    </linearGradient>
  </defs>
  <rect width="720" height="360" fill="url(#bg)"/>
{$pattern}{$ghost}{$terminal}{$badge}{$versionStamp}
  <rect x="40" y="280" width="640" height="70" rx="8" fill="{$bg}" fill-opacity="0.82"/>
  <text x="56" y="300" font-family="{$this->esc(self::FONT_SANS)}" font-size="12" font-weight="bold" letter-spacing="2" fill="{$accent}">{$category}</text>
  <text x="56" y="330" font-family="{$this->esc(self::FONT_SANS)}" font-size="24" font-weight="bold" fill="#ffffff">{$title}</text>
</svg>
SVG;
    }

    /**
     * Terminal-window renderer — the code-evidence element. Window height
     * adapts to the row count; diff rows get green/red tints, other rows
     * stay muted monochrome (code is texture, the title stays primary).
     *
     * @param array{source: string, lines: list<string>} $snippet
     */
    private function renderTerminal(array $snippet, string $slug): string
    {
        $accent = '#DEA584';
        $winX = 56;
        $winY = 44;
        $winW = 608;
        $headerH = 24;
        $rowH = 20;
        $winH = $headerH + 8 + count($snippet['lines']) * $rowH + 12;
        $dotCy = $winY + 12;
        $labelY = $dotCy + 3;
        $label = $this->esc($snippet['source'] . ' — ' . $slug . '.md');
        $rows = $this->renderRows($snippet['lines'], $winX, $winY, $winW, $headerH, $rowH);

        return <<<SVG
  <g>
    <rect x="{$winX}" y="{$winY}" width="{$winW}" height="{$winH}" rx="10" fill="{$this->esc(self::TERMINAL_BG)}" stroke="{$accent}" stroke-opacity="0.45" stroke-width="1.5"/>
    <rect x="{$winX}" y="{$winY}" width="{$winW}" height="{$headerH}" rx="10" fill="{$this->esc(self::TERMINAL_HEADER)}"/>
    <circle cx="70" cy="{$dotCy}" r="4" fill="#ff5f56"/>
    <circle cx="86" cy="{$dotCy}" r="4" fill="#ffbd2e"/>
    <circle cx="102" cy="{$dotCy}" r="4" fill="#27c93f"/>
    <text x="120" y="{$labelY}" font-family="{$this->esc(self::FONT_MONO)}" font-size="10" fill="{$this->esc(self::TERMINAL_LABEL)}">{$label}</text>
{$rows}  </g>
SVG;
    }

    /**
     * Tinted snippet rows inside the terminal window.
     *
     * @param list<string> $lines
     */
    private function renderRows(array $lines, int $winX, int $winY, int $winW, int $headerH, int $rowH): string
    {
        $out = '';
        $y0 = $winY + $headerH + 8 + 12;

        foreach ($lines as $index => $line) {
            $y = $y0 + $index * $rowH;
            $ink = self::DIFF_CONTEXT_INK;
            $tint = '';

            if (str_starts_with($line, '+')) {
                $ink = self::DIFF_ADD_INK;
                $tint = '  <rect x="' . ($winX + 1) . '" y="' . ($y - 14) . '" width="' . ($winW - 2) . '" height="' . ($rowH - 1) . '" fill="' . self::DIFF_ADD_TINT . '" fill-opacity="0.10"/>' . "\n";
            } elseif (str_starts_with($line, '-')) {
                $ink = self::DIFF_REMOVE_INK;
                $tint = '  <rect x="' . ($winX + 1) . '" y="' . ($y - 14) . '" width="' . ($winW - 2) . '" height="' . ($rowH - 1) . '" fill="' . self::DIFF_REMOVE_TINT . '" fill-opacity="0.10"/>' . "\n";
            }

            $out .= $tint;
            $out .= '  <text x="' . ($winX + 14) . '" y="' . $y . '" font-family="' . self::FONT_MONO . '" font-size="11" fill="' . $ink . '">' . $this->esc($line) . '</text>' . "\n";
        }

        return $out;
    }

    /**
     * Version stamp (bottom-right of the title bar) — the release mark.
     * LTS releases (published, even MINOR per docs/blog-voice.md) carry the
     * accent ink and an explicit LTS label; dev versions and 0.x drafts stay
     * muted. Render-only: version never enters the identity hash, so a bump
     * re-renders the stamp and nothing else. Absent version → no stamp.
     */
    private function renderVersionStamp(string $version, string $accent): string
    {
        if ('' === $version) {
            return '';
        }

        $lts = $this->isLtsVersion($version);
        $text = $lts ? 'v' . $version . ' · LTS' : 'v' . $version;
        $ink = $lts ? $accent : self::DIFF_CONTEXT_INK;

        return '  <text x="664" y="300" text-anchor="end" font-family="' . self::FONT_MONO . '" font-size="12" font-weight="bold" letter-spacing="1" fill="' . $ink . '" opacity="0.85">'
            . $this->esc($text) . '</text>' . "\n";
    }

    /**
     * LTS = published release with an even, NON-ZERO MINOR (1.2.0, 1.4.0 …).
     * Drafts (0.x.y) and odd MINORs are development versions. MINOR 0 (the
     * initial 1.0.0 release) is deliberately excluded: freezing is a
     * deliberate act marked by the even-MINOR bump (docs/blog-voice.md,
     * "Ako sa diel stáva LTS") — otherwise every fresh article would ship
     * an LTS stamp and the mark would mean nothing.
     */
    private function isLtsVersion(string $version): bool
    {
        if (1 !== preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $match)) {
            return false;
        }

        $minor = (int) $match[2];

        return 0 !== (int) $match[1] && 0 !== $minor && 0 === $minor % 2;
    }

    /**
     * Series badge (top-right) — identity shared by all series members.
     */
    private function renderBadge(string $series, string $part, string $locale, string $accent): string
    {
        $partLabel = 'sk' === $locale ? 'DIEL' : 'PART';
        $text = mb_strtoupper($series) . ('' !== $part ? ' · ' . $partLabel . ' ' . $part : '');

        return '  <text x="664" y="32" text-anchor="end" font-family="' . self::FONT_SANS . '" font-size="11" font-weight="bold" letter-spacing="3" fill="' . $accent . '" opacity="0.9">'
            . $this->esc($text) . '</text>' . "\n";
    }

    /**
     * Pattern dispatcher — one method per template.
     */
    private function pattern(string $template, string $accent): string
    {
        $color = $this->esc($accent);

        return match ($template) {
            'code' => $this->codePattern($color),
            'grid' => $this->gridPattern($color),
            'wave' => $this->wavePattern($color),
            'circuit' => $this->circuitPattern($color),
            'gradient' => $this->gradientPattern($color),
            'noise' => $this->noisePattern($color),
            'abstract' => $this->abstractPattern($color),
            'rust' => $this->rustPattern($color),
            default => throw new InvalidArgumentException('Unknown cover template: ' . $template),
        };
    }

    private function codePattern(string $color): string
    {
        return <<<SVG
  <g opacity="0.1" stroke="{$color}" stroke-width="1">
    <line x1="0" y1="60" x2="720" y2="60"/>
    <line x1="0" y1="120" x2="720" y2="120"/>
    <line x1="0" y1="180" x2="720" y2="180"/>
    <line x1="0" y1="240" x2="720" y2="240"/>
    <line x1="0" y1="300" x2="720" y2="300"/>
    <line x1="120" y1="0" x2="120" y2="360"/>
    <line x1="240" y1="0" x2="240" y2="360"/>
    <line x1="360" y1="0" x2="360" y2="360"/>
    <line x1="480" y1="0" x2="480" y2="360"/>
    <line x1="600" y1="0" x2="600" y2="360"/>
  </g>
  <g fill="none" stroke="{$color}" stroke-width="2" opacity="0.6">
    <path d="M100,80 L80,80 L80,280 L100,280"/>
    <path d="M620,80 L640,80 L640,280 L620,280"/>
    <rect x="140" y="100" width="180" height="8" rx="4"/>
    <rect x="160" y="120" width="140" height="8" rx="4"/>
    <rect x="180" y="140" width="120" height="8" rx="4"/>
    <rect x="160" y="160" width="160" height="8" rx="4"/>
    <rect x="140" y="180" width="200" height="8" rx="4"/>
  </g>
  <g fill="{$color}">
    <circle cx="140" cy="104" r="4"/>
    <circle cx="160" cy="124" r="4"/>
    <circle cx="180" cy="144" r="4"/>
    <circle cx="160" cy="164" r="4"/>
    <circle cx="140" cy="184" r="4"/>
  </g>
SVG;
    }

    private function gridPattern(string $color): string
    {
        return <<<SVG
  <g opacity="0.15" stroke="{$color}" stroke-width="1">
    <line x1="0" y1="60" x2="720" y2="60"/>
    <line x1="0" y1="120" x2="720" y2="120"/>
    <line x1="0" y1="180" x2="720" y2="180"/>
    <line x1="0" y1="240" x2="720" y2="240"/>
    <line x1="0" y1="300" x2="720" y2="300"/>
    <line x1="120" y1="0" x2="120" y2="360"/>
    <line x1="240" y1="0" x2="240" y2="360"/>
    <line x1="360" y1="0" x2="360" y2="360"/>
    <line x1="480" y1="0" x2="480" y2="360"/>
    <line x1="600" y1="0" x2="600" y2="360"/>
  </g>
  <g fill="{$color}" opacity="0.3">
    <rect x="60" y="60" width="60" height="60" rx="8"/>
    <rect x="180" y="120" width="60" height="60" rx="8"/>
    <rect x="300" y="60" width="60" height="60" rx="8"/>
    <rect x="420" y="180" width="60" height="60" rx="8"/>
    <rect x="540" y="120" width="60" height="60" rx="8"/>
    <rect x="660" y="240" width="60" height="60" rx="8"/>
  </g>
  <g stroke="{$color}" stroke-width="2" fill="none" opacity="0.4">
    <path d="M90,90 L210,150 L330,90 L450,210 L570,150 L690,270"/>
  </g>
SVG;
    }

    private function wavePattern(string $color): string
    {
        return <<<SVG
  <g opacity="0.3" stroke="{$color}" stroke-width="3" fill="none">
    <path d="M0,180 Q180,120 360,180 T720,180"/>
    <path d="M0,200 Q180,140 360,200 T720,200"/>
    <path d="M0,220 Q180,160 360,220 T720,220"/>
  </g>
  <g opacity="0.15" stroke="{$color}" stroke-width="2" fill="none">
    <path d="M0,160 Q180,100 360,160 T720,160"/>
    <path d="M0,240 Q180,180 360,240 T720,240"/>
  </g>
  <g fill="{$color}" opacity="0.2">
    <circle cx="100" cy="100" r="30"/>
    <circle cx="300" cy="80" r="20"/>
    <circle cx="500" cy="120" r="25"/>
    <circle cx="650" cy="90" r="15"/>
  </g>
SVG;
    }

    private function circuitPattern(string $color): string
    {
        return <<<SVG
  <g stroke="{$color}" stroke-width="2" fill="none" opacity="0.4">
    <path d="M0,80 L200,80 L220,100 L400,100"/>
    <path d="M0,160 L150,160 L170,180 L350,180 L370,160 L500,160"/>
    <path d="M0,240 L250,240 L270,260 L450,260"/>
    <path d="M300,0 L300,80 L320,100 L320,160"/>
    <path d="M450,100 L450,200 L470,220 L470,300"/>
  </g>
  <g fill="{$color}" opacity="0.6">
    <circle cx="200" cy="80" r="6"/>
    <circle cx="400" cy="100" r="6"/>
    <circle cx="150" cy="160" r="6"/>
    <circle cx="350" cy="180" r="6"/>
    <circle cx="500" cy="160" r="6"/>
    <circle cx="250" cy="240" r="6"/>
    <circle cx="450" cy="260" r="6"/>
  </g>
  <g fill="#1e293b" stroke="{$color}" stroke-width="2" opacity="0.5">
    <rect x="280" y="120" width="40" height="40" rx="4"/>
    <rect x="430" y="180" width="40" height="40" rx="4"/>
    <rect x="580" y="120" width="40" height="40" rx="4"/>
  </g>
SVG;
    }

    private function gradientPattern(string $color): string
    {
        return <<<SVG
  <defs>
    <radialGradient id="glow1" cx="20%" cy="30%" r="40%">
      <stop offset="0%" style="stop-color:{$color};stop-opacity:0.4"/>
      <stop offset="100%" style="stop-color:{$color};stop-opacity:0"/>
    </radialGradient>
    <radialGradient id="glow2" cx="80%" cy="70%" r="35%">
      <stop offset="0%" style="stop-color:{$color};stop-opacity:0.3"/>
      <stop offset="100%" style="stop-color:{$color};stop-opacity:0"/>
    </radialGradient>
  </defs>
  <rect width="720" height="360" fill="url(#glow1)"/>
  <rect width="720" height="360" fill="url(#glow2)"/>
  <g stroke="{$color}" stroke-width="1" opacity="0.15" fill="none">
    <path d="M0,100 Q180,50 360,100 T720,100"/>
    <path d="M0,150 Q180,100 360,150 T720,150"/>
    <path d="M0,200 Q180,150 360,200 T720,200"/>
    <path d="M0,250 Q180,200 360,250 T720,250"/>
  </g>
  <circle cx="150" cy="120" r="60" fill="{$color}" opacity="0.08"/>
  <circle cx="550" cy="200" r="80" fill="{$color}" opacity="0.06"/>
  <circle cx="350" cy="80" r="40" fill="{$color}" opacity="0.1"/>
SVG;
    }

    private function noisePattern(string $color): string
    {
        return <<<SVG
  <defs>
    <filter id="noise">
      <feTurbulence type="fractalNoise" baseFrequency="0.65" numOctaves="3" stitchTiles="stitch"/>
      <feColorMatrix type="saturate" values="0"/>
    </filter>
  </defs>
  <rect width="720" height="360" filter="url(#noise)" opacity="0.03"/>
  <g fill="{$color}" opacity="0.2">
    <circle cx="60" cy="60" r="2"/><circle cx="120" cy="60" r="2"/><circle cx="180" cy="60" r="2"/>
    <circle cx="240" cy="60" r="2"/><circle cx="300" cy="60" r="2"/><circle cx="360" cy="60" r="2"/>
    <circle cx="420" cy="60" r="2"/><circle cx="480" cy="60" r="2"/><circle cx="540" cy="60" r="2"/>
    <circle cx="600" cy="60" r="2"/><circle cx="660" cy="60" r="2"/>
    <circle cx="60" cy="120" r="2"/><circle cx="120" cy="120" r="2"/><circle cx="180" cy="120" r="2"/>
    <circle cx="240" cy="120" r="2"/><circle cx="300" cy="120" r="2"/><circle cx="360" cy="120" r="2"/>
    <circle cx="420" cy="120" r="2"/><circle cx="480" cy="120" r="2"/><circle cx="540" cy="120" r="2"/>
    <circle cx="600" cy="120" r="2"/><circle cx="660" cy="120" r="2"/>
    <circle cx="60" cy="180" r="2"/><circle cx="120" cy="180" r="2"/><circle cx="180" cy="180" r="2"/>
    <circle cx="240" cy="180" r="2"/><circle cx="300" cy="180" r="2"/><circle cx="360" cy="180" r="2"/>
    <circle cx="420" cy="180" r="2"/><circle cx="480" cy="180" r="2"/><circle cx="540" cy="180" r="2"/>
    <circle cx="600" cy="180" r="2"/><circle cx="660" cy="180" r="2"/>
  </g>
  <g fill="{$color}" opacity="0.6">
    <circle cx="150" cy="100" r="4"/>
    <circle cx="400" cy="80" r="3"/>
    <circle cx="600" cy="150" r="5"/>
    <circle cx="250" cy="200" r="3"/>
    <circle cx="500" cy="220" r="4"/>
  </g>
SVG;
    }

    private function abstractPattern(string $color): string
    {
        return <<<SVG
  <g opacity="0.15">
    <circle cx="120" cy="120" r="80" fill="none" stroke="{$color}" stroke-width="2"/>
    <circle cx="600" cy="200" r="100" fill="none" stroke="{$color}" stroke-width="2"/>
    <circle cx="350" cy="280" r="60" fill="none" stroke="{$color}" stroke-width="1"/>
    <polygon points="300,50 350,130 250,130" fill="none" stroke="{$color}" stroke-width="2"/>
    <polygon points="550,80 600,160 500,160" fill="none" stroke="{$color}" stroke-width="1"/>
    <rect x="80" y="200" width="80" height="80" rx="8" fill="none" stroke="{$color}" stroke-width="2" transform="rotate(15 120 240)"/>
    <rect x="450" y="60" width="60" height="60" rx="8" fill="none" stroke="{$color}" stroke-width="1" transform="rotate(-10 480 90)"/>
  </g>
  <g opacity="0.3">
    <polygon points="190,100 220,83 250,100 250,134 220,151 190,134" fill="{$color}" opacity="0.4"/>
    <polygon points="540,200 570,183 600,200 600,234 570,251 540,234" fill="{$color}" opacity="0.3"/>
    <polygon points="400,120 430,90 460,120 430,150" fill="{$color}" opacity="0.3"/>
    <polygon points="200,250 230,220 260,250 230,280" fill="{$color}" opacity="0.25"/>
  </g>
  <g stroke="{$color}" stroke-width="1" opacity="0.2">
    <line x1="190" y1="100" x2="400" y2="120"/>
    <line x1="400" y1="120" x2="540" y2="200"/>
    <line x1="200" y1="250" x2="350" y2="280"/>
    <line x1="350" y1="280" x2="540" y2="200"/>
  </g>
  <g fill="{$color}">
    <circle cx="190" cy="100" r="4"/>
    <circle cx="400" cy="120" r="4"/>
    <circle cx="540" cy="200" r="4"/>
    <circle cx="200" cy="250" r="3"/>
    <circle cx="350" cy="280" r="3"/>
  </g>
SVG;
    }

    private function rustPattern(string $color): string
    {
        return <<<SVG
  <g opacity="0.12" stroke="{$color}" stroke-width="1">
    <line x1="0" y1="60" x2="720" y2="60"/>
    <line x1="0" y1="180" x2="720" y2="180"/>
    <line x1="0" y1="300" x2="720" y2="300"/>
    <line x1="120" y1="0" x2="120" y2="360"/>
    <line x1="360" y1="0" x2="360" y2="360"/>
    <line x1="600" y1="0" x2="600" y2="360"/>
  </g>
  <!-- Hexagons: precision / systems engineering -->
  <g opacity="0.18" fill="none" stroke="{$color}" stroke-width="1.5">
    <polygon points="520,80 540,68 560,80 560,104 540,116 520,104"/>
    <polygon points="560,80 580,68 600,80 600,104 580,116 560,104"/>
    <polygon points="540,92 560,80 580,92 580,116 560,128 540,116"/>
    <polygon points="80,180 100,168 120,180 120,204 100,216 80,204"/>
    <polygon points="120,180 140,168 160,180 160,204 140,216 120,204"/>
    <polygon points="100,192 120,180 140,192 140,216 120,228 100,216"/>
  </g>
  <!-- Ownership arrows: transfer / move semantics -->
  <g stroke="{$color}" stroke-width="2" fill="none" opacity="0.35">
    <path d="M180,100 L380,100"/>
    <path d="M370,90 L380,100 L370,110"/>
    <path d="M400,200 L600,200"/>
    <path d="M590,190 L600,200 L590,210"/>
  </g>
  <!-- Gears: compile-time machinery -->
  <g fill="{$color}" opacity="0.2">
    <circle cx="440" cy="50" r="30"/>
    <circle cx="440" cy="50" r="18" fill="#0f172a"/>
    <circle cx="200" cy="260" r="22"/>
    <circle cx="200" cy="260" r="13" fill="#0f172a"/>
  </g>
  <!-- Code bracket: ownership at the boundary -->
  <g fill="none" stroke="{$color}" stroke-width="2" opacity="0.25">
    <path d="M620,140 L600,140 L600,240 L620,240"/>
    <path d="M660,140 L680,140 L680,240 L660,240"/>
  </g>
  <g fill="{$color}" opacity="0.5">
    <circle cx="440" cy="50" r="3"/>
    <circle cx="200" cy="260" r="3"/>
    <circle cx="180" cy="100" r="3"/>
    <circle cx="600" cy="200" r="3"/>
  </g>
SVG;
    }
}
