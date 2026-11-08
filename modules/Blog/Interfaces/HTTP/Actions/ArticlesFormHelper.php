<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Blog\Application\BlogServices;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;
use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Media\Interfaces\HTTP\MediaEditorHelper;

/**
 * Shared helpers for article create/edit forms.
 *
 * Delegates to MediaEditorHelper for media picker + inline upload.
 * Kept for backward compatibility — existing action classes import this.
 */
final class ArticlesFormHelper
{
    /**
     * Article form guard script — base64-encodes the Markdown body into
     * "body_b64" on submit so hosting-WAF rules never see shell snippets
     * from article content; ArticleFormPayload decodes it server-side.
     */
    public static function formGuardScript(): string
    {
        return ViewRenderer::render('Modules/Blog/Interfaces/HTTP/templates/article-form-guard.phtml', []);
    }

    /**
     * Media picker modal + script — delegates to shared MediaEditorHelper.
     */
    public static function mediaPickerScript(RouterInterface $router): string
    {
        return MediaEditorHelper::editorScript($router, 'body');
    }

    /**
     * Category input with a datalist of the categories already in use
     * (derived from article front-matter in the given locale). A datalist
     * suggests existing categories but still accepts free text, so a new
     * category can be typed directly — no extra JS, CSP-safe.
     */
    public static function categoryInput(string $currentValue, string $locale): string
    {
        $options = self::datalistOptions(self::existingCategories($locale));

        return '<input type="text" id="category" name="category"'
            . ' value="' . AdminLayout::e($currentValue) . '"'
            . ' list="category-options" autocomplete="off">'
            . '<datalist id="category-options">' . $options . '</datalist>';
    }

    /**
     * Tags input with a datalist of the tags already in use (derived from
     * article front-matter in the given locale). Same free-text semantics
     * as the category picker: suggestions only — new tags can be typed
     * directly, comma-separated. No extra JS, CSP-safe.
     */
    public static function tagsInput(string $currentValue, string $locale): string
    {
        $options = self::datalistOptions(self::existingTags($locale));

        return '<input type="text" id="tags" name="tags"'
            . ' value="' . AdminLayout::e($currentValue) . '"'
            . ' list="tags-options" autocomplete="off" placeholder="php, clean-code, architecture">'
            . '<datalist id="tags-options">' . $options . '</datalist>';
    }

    /**
     * Author picker for the article forms: active users merged with the
     * distinct author_email/author bylines already present in the locale's
     * article front matter. Emails are identity values — never rendered,
     * only used as option values and for the current-value match.
     *
     * @return list<array{email: string, name: string, linked: bool}>
     */
    public static function authorOptions(string $locale): array
    {
        $byEmail = [];

        // Active registered users are the linked authors (users.db).
        $users = new UserRepository(DatabaseConnectionManager::instance()->connection('users'));

        foreach ($users->all() as $user) {
            if ($user->isActive()) {
                $byEmail[strtolower($user->email())] = ['email' => $user->email(), 'name' => $user->name(), 'linked' => true];
            }
        }

        // Legacy/free-text bylines from article front matter stay pickable
        // (linked: false) so existing articles can be re-saved unchanged.
        foreach (BlogServices::articles()->all($locale) as $article) {
            if ('' !== $article->authorEmail) {
                $key = strtolower($article->authorEmail);
                $byEmail[$key] ??= ['email' => $article->authorEmail, 'name' => $article->author, 'linked' => false];
            }
        }

        $options = array_values($byEmail);
        usort($options, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $options;
    }

    /**
     * Author select + "Custom author (free text)" toggle for the article
     * forms. The select offers authorOptions(); the current value is
     * preselected — when it matches no option (free-text byline), a custom
     * option carrying it verbatim is prepended so nothing is silently
     * rewritten on save. The checkbox swaps the select for a text input
     * (inline ES5 script, CSP nonce placeholder) to preserve guest bylines.
     */
    public static function authorSelect(string $currentValue, string $locale): string
    {
        $options = self::authorOptions($locale);
        $hasCurrent = false;

        foreach ($options as $option) {
            if (0 === strcasecmp($option['email'], $currentValue)) {
                $hasCurrent = true;

                break;
            }
        }

        $html = '<select id="author_email" name="author_email">';

        if (!$hasCurrent) {
            $label = '' !== $currentValue ? $currentValue : 'Custom author';
            $html .= '<option value="' . AdminLayout::e($currentValue) . '" selected>' . AdminLayout::e($label) . '</option>';
        }

        foreach ($options as $option) {
            $selected = 0 === strcasecmp($option['email'], $currentValue) ? ' selected' : '';
            $html .= '<option value="' . AdminLayout::e($option['email']) . '"' . $selected . '>' . AdminLayout::e($option['name']) . '</option>';
        }

        $html .= '</select>';

        // Custom row starts hidden with its input disabled: disabled controls
        // are never successful, so exactly one author_email value is submitted
        // (select while unchecked, text input while checked — synced by JS).
        $html .= '<div id="author-custom-row" style="display:none;">'
            . '<input type="text" id="author_email_custom" name="author_email" value="" disabled placeholder="guest@example.test">'
            . '</div>'
            . '<label class="m-check" for="author_custom_toggle">'
            . '<input type="checkbox" id="author_custom_toggle">'
            . ' Custom author (free text)'
            . '</label>'
            . self::authorToggleScript();

        return $html;
    }

    /**
     * ES5 toggle script (Chrome 70 baseline): the checkbox swaps the select
     * for the custom text input. Select and input share the author_email
     * name — the script disables the inactive one so exactly one value is
     * submitted, and copies the current selection into the text input so a
     * linked author can be edited into a custom byline in place.
     */
    private static function authorToggleScript(): string
    {
        return ViewRenderer::render('Modules/Blog/Interfaces/HTTP/templates/article-form.phtml', []);
    }

    /**
     * @param list<string> $values
     */
    private static function datalistOptions(array $values): string
    {
        $options = '';

        foreach ($values as $value) {
            $options .= '<option value="' . AdminLayout::e($value) . '">';
        }

        return $options;
    }

    /**
     * Distinct non-empty categories across the given locale's articles plus
     * the pre-created registry entries, case-insensitively sorted.
     *
     * @return list<string>
     */
    private static function existingCategories(string $locale): array
    {
        $categories = [];

        foreach (BlogServices::articles()->all($locale) as $article) {
            if ('' !== $article->category) {
                $categories[$article->category] = true;
            }
        }

        foreach (BlogServices::taxonomy()->allCategories() as $category) {
            $categories[$category] = true;
        }

        return self::sorted(array_keys($categories));
    }

    /**
     * Distinct non-empty tags across the given locale's articles plus the
     * pre-created registry entries, case-insensitively sorted.
     *
     * @return list<string>
     */
    private static function existingTags(string $locale): array
    {
        $tags = [];

        foreach (BlogServices::articles()->all($locale) as $article) {
            foreach ($article->tags as $tag) {
                if ('' !== $tag) {
                    $tags[$tag] = true;
                }
            }
        }

        foreach (BlogServices::taxonomy()->allTags() as $tag) {
            $tags[$tag] = true;
        }

        return self::sorted(array_keys($tags));
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values, SORT_STRING | SORT_FLAG_CASE);

        return $values;
    }
}
