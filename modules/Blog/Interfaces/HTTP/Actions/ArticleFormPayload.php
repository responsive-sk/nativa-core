<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Core\Infrastructure\Http\Request;

/**
 * ArticleFormPayload — normalizes the POST payload of the article
 * create/edit forms.
 *
 * Two concerns live here:
 *
 * 1. Body guard (hosting-WAF workaround). The Markdown body of a technical
 *    article routinely contains strings that hosting-level WAF rules
 *    misread as an attack (shell commands like "curl … | bash", "sudo …",
 *    SQL snippets, inline script tags) and the whole POST is rejected with
 *    "blocked by web firewall" before it ever reaches PHP. The form's JS
 *    guard (article-form-guard.js) therefore base64-encodes the body into
 *    the "body_b64" hidden field right before submit — the firewall sees
 *    only base64 letters. This class decodes it back. Plain "body" posts
 *    keep working so the form degrades gracefully without JS.
 *
 * 2. Hidden meta passthrough. The form is title/slug-driven and has no
 *    fields for every front-matter key an article may carry (series,
 *    series-part, version, views, …). Those keys travel through hidden
 *    "meta[key]" inputs so an edit can never silently drop them — dropping
 *    "series:" would for example break cover generation and the LTS badge.
 */
final class ArticleFormPayload
{
    /** Front-matter key values that must never be supplied from POST meta. */
    private const array GUARDED_META_KEYS = [
        'title', 'date', 'tags', 'excerpt', 'author', 'author_email',
        'category', 'image', 'translation', 'members_only', 'published',
    ];

    /**
     * Extract the raw form data from the request, resolving the guarded
     * body field ("body_b64" wins over "body" when present).
     *
     * @return array<string, string>
     */
    public static function extract(Request $request): array
    {
        $str = static fn (mixed $v): string => is_string($v) ? $v : '';

        return [
            'title'        => trim($str($request->body('title'))),
            'slug'         => trim($str($request->body('slug'))),
            'date'         => trim($str($request->body('date'))),
            'category'     => trim($str($request->body('category'))),
            'tags'         => trim($str($request->body('tags'))),
            'excerpt'      => trim($str($request->body('excerpt'))),
            'author_email' => trim($str($request->body('author_email'))),
            'image'        => trim($str($request->body('image'))),
            'members_only' => '1' === $str($request->body('members_only')) ? '1' : '',
            'published'    => '1' === $str($request->body('published')) ? '1' : '',
            'translation'  => trim($str($request->body('translation'))),
            'body'         => self::resolveBody($request),
            'locale'       => trim($str($request->body('locale')) ?: 'sk_SK'),
        ];
    }

    /**
     * Hidden passthrough meta values collected from "meta[key]" fields,
     * filtered against the form-owned keys. Scalars only — this data comes
     * from the client, so it must never smuggle arrays/objects into meta.
     *
     * @return array<string, string|bool|int|float>
     */
    public static function passthroughMeta(Request $request): array
    {
        $meta = [];

        foreach ($request->getBodyParams() as $key => $value) {
            if (!str_starts_with($key, 'meta[') || !str_ends_with($key, ']')) {
                continue;
            }

            $metaKey = substr($key, 5, -1);

            if ('' === $metaKey || in_array($metaKey, self::GUARDED_META_KEYS, true)) {
                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $trimmed = trim((string) $value);

            if ('' === $trimmed) {
                continue;
            }

            $meta[$metaKey] = $trimmed;
        }

        return $meta;
    }

    /**
     * Passthrough values carried in form data (the "passthrough" entry
     * assembled by the edit action's normalizeArticle / error fallback).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    public static function passthroughValues(array $data): array
    {
        $values = $data['passthrough'] ?? null;

        if (!is_array($values)) {
            return [];
        }

        $out = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * Guarded body value: base64 in "body_b64" (set by the form guard
     * script) wins over the plain "body" textarea; a malformed payload
     * falls back to the raw textarea so nothing is silently lost.
     */
    private static function resolveBody(Request $request): string
    {
        $encoded = $request->body('body_b64');

        if (is_string($encoded) && '' !== trim($encoded)) {
            $decoded = base64_decode($encoded, true);

            if (is_string($decoded)) {
                return $decoded;
            }
        }

        $plain = $request->body('body');

        return is_string($plain) ? $plain : '';
    }
}
