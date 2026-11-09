<?php

declare(strict_types = 1);

namespace Tests\Domain;

use Discovery\PreferenceVote;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PreferenceVoteTest — the contention rule every port registry shares,
 * without installing any provider.
 *
 * First answerer at the best (lowest) priority wins; co-answerers at that
 * priority are named as ignored (the registries log them); lower-priority
 * answers are precedence by design, not contention, and stay silent.
 */
final class PreferenceVoteTest extends TestCase
{
    #[Test]
    public function noAnswersMeansNoWinner(): void
    {
        /** @var list<array{priority: int, name: string, value: string}> $none */
        $none = [];

        $this->assertSame(['winner' => null, 'ignored' => []], PreferenceVote::select($none));
    }

    #[Test]
    public function singleAnswerWinsUncontested(): void
    {
        $answer = ['priority' => 0, 'name' => 'A', 'value' => 'a'];
        $result = PreferenceVote::select([$answer]);

        $this->assertSame($answer, $result['winner']);
        $this->assertSame([], $result['ignored']);
    }

    #[Test]
    public function bestPriorityWinsAndLowerPrioritiesStaySilent(): void
    {
        $result = PreferenceVote::select([
            ['priority' => 0, 'name' => 'A', 'value' => 'a'],
            ['priority' => 5, 'name' => 'B', 'value' => 'b'],
            ['priority' => 5, 'name' => 'C', 'value' => 'c'],
        ]);

        $this->assertNotNull($result['winner']);
        $this->assertSame('A', $result['winner']['name']);
        $this->assertSame([], $result['ignored'], 'Lower priority is precedence by design, not contention.');
    }

    #[Test]
    public function tieAtBestPriorityKeepsFirstAndNamesTheRest(): void
    {
        $result = PreferenceVote::select([
            ['priority' => -100, 'name' => 'Settings', 'value' => 'erik'],
            ['priority' => -100, 'name' => 'TempVote', 'value' => 'kernel'],
            ['priority' => 0, 'name' => 'Late', 'value' => 'mono'],
        ]);

        $this->assertNotNull($result['winner']);
        $this->assertSame('Settings', $result['winner']['name']);
        $this->assertSame('erik', $result['winner']['value']);
        $this->assertSame(['TempVote'], array_column($result['ignored'], 'name'));
    }

    #[Test]
    public function tieOrderFollowsListOrder(): void
    {
        $result = PreferenceVote::select([
            ['priority' => 5, 'name' => 'X', 'value' => 'x'],
            ['priority' => 0, 'name' => 'A', 'value' => 'a'],
            ['priority' => 0, 'name' => 'B', 'value' => 'b'],
        ]);

        $this->assertNotNull($result['winner']);
        $this->assertSame('A', $result['winner']['name']);
        $this->assertSame(['B'], array_column($result['ignored'], 'name'));
    }
}
