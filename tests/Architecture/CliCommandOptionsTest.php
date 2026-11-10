<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/bin/cms';

/**
 * `commandOptions()` in `bin/cms` is the one owner of what a kernel command
 * accepts.
 *
 * The defect these tests pin: a command that ignored an option kept running as
 * if the option were absent. `php bin/cms queue:work --lmit=3` worked the
 * default batch of ten and exited 0, so a typo read as success. The dispatch,
 * the table and the help text now have to agree, and an undeclared option is
 * refused before the command does anything.
 */
final class CliCommandOptionsTest extends TestCase
{
    private function scriptPath(): string
    {
        return dirname(__DIR__, 2) . '/bin/cms';
    }

    /**
     * @return array<string, array<string, string>|null>
     */
    private function table(): array
    {
        return \commandOptions();
    }

    public function testEveryDispatchedCommandHasATableEntryAndViceVersa(): void
    {
        $source = (string) file_get_contents($this->scriptPath());
        $main = substr($source, (int) strpos($source, 'function main('));

        preg_match_all("/case '([a-z][a-z:-]*[a-z])':/", $main, $matches);

        /** @var list<string> $dispatched */
        $dispatched = array_values(array_unique($matches[1]));
        $declared = array_keys($this->table());

        sort($dispatched);
        sort($declared);

        self::assertNotEmpty($dispatched);
        self::assertSame($dispatched, $declared, 'every dispatched command must declare its options');
    }

    public function testTheHelpAdvertisesExactlyTheDeclaredOptions(): void
    {
        $lines = \usageLines();

        foreach ($this->table() as $command => $spec) {
            if (null === $spec) {
                continue;
            }

            $line = null;

            foreach ($lines as $candidate) {
                $text = ltrim($candidate);

                if ($text === $command || str_starts_with($text, $command . ' ')) {
                    $line = $candidate;

                    break;
                }
            }

            self::assertNotNull($line, sprintf('no help line names %s', $command));

            preg_match_all('/--[a-z][a-z-]*=?/', $line, $matches);

            /** @var list<string> $advertised */
            $advertised = array_values(array_unique($matches[0]));
            $declared = array_keys($spec);

            sort($advertised);
            sort($declared);

            self::assertSame($declared, $advertised, sprintf('%s: help and table disagree', $command));
        }
    }

    public function testEveryDeclaredOptionIsAccepted(): void
    {
        foreach ($this->table() as $command => $spec) {
            foreach (array_keys($spec ?? []) as $token) {
                $takesValue = str_ends_with($token, '=');
                $typed = $takesValue ? $token . '7' : $token;

                $parsed = \parseArguments($command, ['cms', $command, $typed]);

                self::assertArrayHasKey($token, $parsed['options'], $command);
                self::assertSame(
                    $takesValue ? '7' : null,
                    $parsed['options'][$token],
                    sprintf('%s: %s parsed as the wrong value', $command, $token),
                );
            }
        }
    }

    public function testAnUndeclaredOptionIsRefusedBeforeTheCommandRuns(): void
    {
        foreach ($this->table() as $command => $spec) {
            if (null === $spec) {
                continue;
            }

            try {
                \parseArguments($command, ['cms', $command, '--definitely-not-an-option']);
                self::fail(sprintf('%s accepted an option it does not declare', $command));
            } catch (InvalidArgumentException $e) {
                $message = $e->getMessage();

                self::assertStringContainsString('--definitely-not-an-option', $message);
                self::assertStringContainsString($command, $message);

                $known = [] === $spec ? 'known: none' : 'known: ' . implode(', ', array_keys($spec));

                self::assertStringContainsString($known, $message, $command);
            }
        }
    }

    public function testQueueWorkParsesItsLimitInsteadOfIgnoringTheOption(): void
    {
        $parsed = \parseArguments('queue:work', ['cms', 'queue:work', 'critical', '--limit=3']);

        self::assertSame(['critical'], $parsed['args']);
        self::assertSame('3', $parsed['options']['--limit='] ?? null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown option "--lmit" for queue:work');

        \parseArguments('queue:work', ['cms', 'queue:work', '--lmit=3']);
    }

    public function testADelegatedCommandKeepsItsRawArgumentsForItsOwnValidator(): void
    {
        $parsed = \parseArguments('user:admin', ['cms', 'user:admin', 'a@example.test', '--name=Ann']);

        self::assertTrue($parsed['delegated']);
        self::assertSame(['a@example.test', '--name=Ann'], $parsed['args']);
        self::assertSame([], $parsed['options']);
    }
}
