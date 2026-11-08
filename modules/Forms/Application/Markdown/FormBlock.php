<?php

declare(strict_types = 1);

namespace Modules\Forms\Application\Markdown;

use Modules\Core\Infrastructure\Attributes\AsMarkdownBlock;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\Markdown\MarkdownBlockContext;
use Modules\Core\Interfaces\HTTP\Markdown\MarkdownBlockInterface;
use Modules\Forms\Application\FormsServices;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Interfaces\HTTP\FormBlockRenderer;

/**
 * FormBlock — owns `{{form:<slug>}}` and nothing else.
 *
 * The block is discovered through `#[AsMarkdownBlock]`, so neither Pages nor
 * Blog nor the renderer imports the Forms module: the placeholder travels as
 * markdown and is expanded here, after the content is already HTML.
 *
 * A definition that is missing, unpublished, in another locale or unparseable
 * renders NOTHING and logs one line. An editor sees the form's state in
 * `/mark/forms`; a reader sees a page without a broken box rather than a box
 * with no fields.
 *
 * The database read is wrapped in `catch (\Throwable)`: a freshly installed
 * module has a published page carrying `form:` before anyone runs `migrate`,
 * and a missing table must not take the page down. Same shape as
 * `QuestionsBadge`.
 */
#[AsMarkdownBlock(module: 'forms', priority: 60)]
final readonly class FormBlock implements MarkdownBlockInterface
{
    private const string PATTERN = '/\{\{form:([a-z0-9][a-z0-9_-]{0,79})\}\}/';

    public function name(): string
    {
        return 'forms';
    }

    public function expand(string $html, MarkdownBlockContext $context): string
    {
        if (!str_contains($html, '{{form:')) {
            return $html;
        }

        return (string) preg_replace_callback(
            self::PATTERN,
            fn (array $match): string => $this->render($match[1], $context),
            $html,
        );
    }

    /**
     * @param array<int|string, string> $match
     */
    private function render(string $slug, MarkdownBlockContext $context): string
    {
        try {
            $form = $this->resolve($slug, $context->locale);
        } catch (\Throwable $e) {
            error_log(sprintf('[forms] could not resolve "%s": %s', $slug, $e->getMessage()));

            return '';
        }

        if (null === $form) {
            return '';
        }

        if (!$form->schema->isValid()) {
            error_log(sprintf('[forms] the definition "%s" has an invalid field list and was not rendered.', $slug));

            return '';
        }

        return FormBlockRenderer::render(
            $form,
            $context->cssPrefix,
            $context->locale,
            $context->stateFor('forms'),
            $context->path,
        );
    }

    /**
     * Exact locale first, then the configured default, mirroring
     * `ContentLocaleMapper` and the Pages admin toggle.
     */
    private function resolve(string $slug, string $locale): ?Form
    {
        $form = FormsServices::forms()->findPublished($slug, $locale);

        if (null !== $form) {
            return $form;
        }

        $default = ContentServices::mapper()->defaultLocale();

        if ($default === $locale) {
            return null;
        }

        return FormsServices::forms()->findPublished($slug, $default);
    }
}
