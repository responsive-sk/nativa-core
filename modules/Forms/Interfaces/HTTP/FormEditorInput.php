<?php

declare(strict_types = 1);

namespace Modules\Forms\Interfaces\HTTP;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Forms\Domain\Model\FormSchema;

/**
 * FormEditorInput — extraction and checking for the definition editor.
 *
 * Create and edit must accept exactly the same definition. Sharing this class
 * is what makes that true rather than a promise: a rule added for one action
 * is a rule both actions apply.
 *
 * The slug falls back to a slugified title, the same convenience
 * `PagesCreateAction` offers, so a new form needs no second decision.
 */
final readonly class FormEditorInput
{
    /**
     * @return array{slug: string, title: string, intro: string, success_message: string, published: bool, fields: list<array<string, mixed>>}
     */
    public static function extract(Request $request): array
    {
        $fields = $request->body('fields');
        $rows = [];

        foreach (is_array($fields) ? $fields : [] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return [
            'slug'            => strtolower(trim($request->bodyString('slug'))),
            'title'           => trim($request->bodyString('title')),
            'intro'           => trim($request->bodyString('intro')),
            'success_message' => trim($request->bodyString('success_message')),
            'published'       => '1' === $request->bodyString('published'),
            'fields'          => $rows,
        ];
    }

    /**
     * @param array{slug: string, title: string, intro: string, success_message: string, published: bool, fields: list<array<string, mixed>>} $data
     *
     * @return list<string>
     */
    public static function errors(array $data, FormSchema $schema): array
    {
        $errors = [];

        if ('' === $data['title']) {
            $errors[] = 'Title is required.';
        } elseif (\strlen($data['title']) > 120) {
            $errors[] = 'Title may be at most 120 characters.';
        }

        if ('' !== $data['slug'] && 1 !== preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $data['slug'])) {
            $errors[] = 'Slug must be lowercase letters, digits, `_` or `-` (max 80).';
        }

        if (\strlen($data['intro']) > 500) {
            $errors[] = 'Intro may be at most 500 characters.';
        }

        if (\strlen($data['success_message']) > 300) {
            $errors[] = 'The success message may be at most 300 characters.';
        }

        return [...$errors, ...$schema->errorList()];
    }

    /**
     * The slug to store: what the editor typed, or one derived from the title.
     *
     * @param array{slug: string, title: string} $data
     */
    public static function slug(array $data): string
    {
        if ('' !== $data['slug']) {
            return $data['slug'];
        }

        return \Modules\Core\Application\Services\MarkdownRenderer::slugify($data['title']);
    }
}
