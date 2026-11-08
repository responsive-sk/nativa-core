<?php

declare(strict_types = 1);

namespace Modules\Forms\Application\Services;

use Modules\Forms\Application\FormsServices;
use Modules\Forms\Domain\Entities\Form;

/**
 * FormResultsService — the counts behind the result bars.
 *
 * Owned here rather than in the renderer because the number has one meaning
 * and one limit: the newest `WINDOW` submissions of the form, tallied in PHP.
 * A bar is a display aid, not analytics, and the window is what keeps a page
 * render from turning into a scan of every answer a form ever received.
 *
 * `total` is the number of submissions that actually answered the field, which
 * is the denominator each percentage is divided by: a skipped optional
 * question must not make the bars for the others sum to less than 100%.
 */
final readonly class FormResultsService
{
    /**
     * How many of the newest submissions one render tallies.
     */
    public const int WINDOW = 500;

    /**
     * @return array<string, array{total: int, counts: array<string, int>}>
     *         field key -> the bars for that field, in declaration order
     */
    public function counts(Form $form): array
    {
        $payloads = FormsServices::submissions()->recentPayloads($form->id, self::WINDOW);
        $results = [];

        foreach ($form->schema->fields() as $field) {
            if (!$field->isChoice() || !$field->showResults) {
                continue;
            }

            $counts = array_fill_keys($field->options, 0);
            $total = 0;

            foreach ($payloads as $payload) {
                $value = $payload[$field->key] ?? '';

                if (!\array_key_exists($value, $counts)) {
                    continue;
                }

                ++$counts[$value];
                ++$total;
            }

            $results[$field->key] = ['total' => $total, 'counts' => $counts];
        }

        return $results;
    }

    /**
     * The fill width for one option, as a whole percentage.
     *
     * @param array{total: int, counts: array<string, int>} $field
     */
    public static function percent(array $field, string $option): int
    {
        if ($field['total'] <= 0) {
            return 0;
        }

        return (int) round(($field['counts'][$option] ?? 0) * 100 / $field['total']);
    }
}
