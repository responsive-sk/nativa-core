<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services;

/**
 * PreferenceVote — one contention rule for every port registry.
 *
 * Each port answers "first non-null wins (priority, then discovery order)",
 * but until now a tie at the winning priority was silent: two providers
 * answering the same question, one of them invisible. This decides the
 * winner AND names the ignored, so the registries can log what they drop
 * instead of dropping it quietly.
 *
 * Pure on purpose: answers arrive pre-filtered (only answerers, in
 * priority-then-discovery order) and the decision needs nothing else, which
 * is what makes ties unit-testable without installing providers.
 *
 * The winner stays FIRST, not last: discovery order reversed is equally
 * arbitrary, and flipping it would change which menu renders today for no
 * reason. The improvement is visibility (the log), not a new winner.
 */
final class PreferenceVote
{
    /**
     * @template T
     *
     * @param list<array{priority: int, name: string, value: T}> $answers answerers only, priority-then-discovery order
     *
     * @return array{winner: array{priority: int, name: string, value: T}|null, ignored: list<array{priority: int, name: string, value: T}>}
     */
    public static function select(array $answers): array
    {
        if ([] === $answers) {
            return ['winner' => null, 'ignored' => []];
        }

        $best = min(array_column($answers, 'priority'));

        $winner = null;
        $ignored = [];

        foreach ($answers as $answer) {
            if ($answer['priority'] !== $best) {
                continue;
            }

            if (null === $winner) {
                $winner = $answer;
            } else {
                $ignored[] = $answer;
            }
        }

        return ['winner' => $winner, 'ignored' => $ignored];
    }
}
