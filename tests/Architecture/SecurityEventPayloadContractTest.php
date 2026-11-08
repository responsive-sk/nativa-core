<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SecurityEventPayloadContractTest — the declared payload vocabulary against the
 * emit sites that have to obey it.
 *
 * Measured 2026-10-16, all three sides at once:
 *
 *     emit sites send      payloadKeys() declared
 *     -----------------    ---------------------
 *     AUTHZ_DENIED      path, required, userId, roles, ip   path, required, userId, roles, ip   ok
 *     USER_CHANGED      userId, operation, ip               userId, operation                  MISSING ip
 *     ROLE_CHANGED      roleId, operation, ip               roleId, operation                  MISSING ip
 *     AUTH_ATTEMPT      email, outcome, ip                  email, outcome, ip                 ok
 *     RATELIMIT_TRIPPED key, scope, ip                      key, scope, ip                     ok
 *
 * and the Audit handler reads `ip` for EVERY message — `SecurityEventHandler::ip()`
 * is called unconditionally, not per type. So two of the five arms declared a
 * payload smaller than the one their emit sites send and the one the handler
 * reads. It is the same defect the day's other fix closed, half-done: `ip` was
 * put back on the two refusal arms because "the Audit handler reads it and every
 * emit site sends it" — a sentence that was true of the two account arms as
 * well, and nobody re-ran it across the match.
 *
 * Why an architecture test rather than a behavioural one: the five arms have
 * TWO owners in one module. Four are the `SecurityEventServices` helpers; the
 * two refusal arms are emitted by `OutboxSecurityEventSink`, the module's
 * implementation of the kernel's `SecurityEventSink` port — the kernel says
 * which fact happened and this module decides the event type and the keys, so
 * both halves stay in the module that declares them. A behavioural sweep still
 * cannot reach the rate-limit arm: `RateLimitMiddlewareTest` hands the
 * middleware a sink that throws on purpose, to prove a recorder that cannot
 * write never turns a 429 into a 500. Every file is read as TEXT, like its
 * neighbours: no class is loaded, so the kernel-only gate is unaffected.
 *
 * `modules/Outbox/...` is the committed catalogue (the source the installed
 * slice is copied from); `src/Modules/Core/...` is the kernel itself, which is
 * always in the tree.
 *
 * @internal
 *
 * @coversNothing
 */
final class SecurityEventPayloadContractTest extends TestCase
{
    /** Where the vocabulary is declared: the one owner of both halves. */
    private const string VOCABULARY = 'modules/Outbox/Domain/SecurityEvents.php';

    /**
     * The files an event type is sent from.
     *
     * `UsersAuthProvider` is deliberately absent: it forwards a context it is
     * handed rather than shaping one, so it has no array of its own to compare.
     */
    private const array EMIT_SITES = [
        'modules/Outbox/Interfaces/HTTP/SecurityEventServices.php',
        'modules/Outbox/Application/Services/OutboxSecurityEventSink.php',
    ];

    /**
     * @return string the file's source, asserted readable
     */
    private static function source(string $relative): string
    {
        $file = dirname(__DIR__, 2) . '/' . $relative;

        self::assertFileExists($file, sprintf('%s is missing — it is part of the contract.', $relative));

        return (string) file_get_contents($file);
    }

    /**
     * The vocabulary, all three halves of it, parsed as text.
     *
     * - `constants`: constant name ⇒ event type, so a site's `::NAME` resolves;
     * - `declared`:  event type ⇒ the payload keys `payloadKeys()` promises;
     * - `all`:       every type `SecurityEvents::all()` lists.
     *
     * @return array{constants: array<string, string>, declared: array<string, list<string>|null>, all: list<string>}
     */
    private static function vocabulary(): array
    {
        $source = self::source(self::VOCABULARY);

        $constants = [];

        if (preg_match_all("/public const string (\w+) = '([^']*)';/", $source, $declarations, PREG_SET_ORDER) > 0) {
            foreach ($declarations as $declaration) {
                $constants[$declaration[1]] = $declaration[2];
            }
        }

        self::assertNotEmpty(
            $constants,
            'No constants parsed out of ' . self::VOCABULARY . ' — it was rewritten and this check reads nothing.',
        );

        // The `all()` list: a bare `self::NAME,` per line, never `=>`, so the
        // same slice that finds the match arms below cannot see these.
        $allBody = self::methodBody($source, 'all');
        $all = [];

        // `payloadKeys()`'s arms: `self::NAME => [...]`.
        $armsBody = self::methodBody($source, 'payloadKeys');
        $declared = [];

        if (preg_match_all('/self::(\w+)\s*=>\s*\[([^\]]*)\]/', $armsBody, $arms, PREG_SET_ORDER) > 0) {
            foreach ($arms as $arm) {
                preg_match_all("/'([^']+)'/", $arm[2], $named);
                $declared[$constants[$arm[1]] ?? $arm[1]] = $named[1];
            }
        }

        self::assertNotEmpty(
            $declared,
            'No payloadKeys() arms parsed out of ' . self::VOCABULARY . ' — the match was rewritten.',
        );

        if (preg_match_all('/self::(\w+),/', $allBody, $listed) > 0) {
            $all = array_values(array_filter(array_map(
                static fn (string $name): ?string => $constants[$name] ?? null,
                $listed[1],
            )));
        }

        self::assertNotEmpty(
            $all,
            'No types parsed out of SecurityEvents::all() — the guard that names the vocabulary is now unreadable.',
        );

        return ['constants' => $constants, 'declared' => $declared, 'all' => $all];
    }

    /**
     * One method's body, from its declaration to the closing brace on its own
     * indentation. Small on purpose: this test needs `all()` and `payloadKeys()`
     * and nothing else in the file.
     */
    private static function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method . '(');

        self::assertNotFalse($start, sprintf('SecurityEvents::%s() was not found.', $method));

        $end = strpos($source, "\n    }", $start);

        self::assertNotFalse($end, sprintf('SecurityEvents::%s() has no closing brace at method indent.', $method));

        return substr($source, $start, $end - $start);
    }

    /**
     * Every payload an emit site hands to the outbox: type ⇒ keys, plus the file
     * it was found in so a failure names its home.
     *
     * The pattern is `::CONST,` followed by an array literal — the payload, which
     * comes first (`authAttempt()` passes a second array as metadata and it is not
     * the one matched). The literal is sliced by matching brackets rather than by
     * a `[^\]]*` run, and that is not pedantry: `bulkRefused()` sends
     * `'roles' => []`, and the flat pattern measured this file's first version
     * stopping at that inner `]` and reading four keys where five are sent.
     *
     * @return list<array{type: string, keys: list<string>, file: string}>
     */
    private static function emitted(): array
    {
        $vocabulary = self::vocabulary();
        $sites = [];

        foreach (self::EMIT_SITES as $relative) {
            $source = self::source($relative);

            if (preg_match_all('/::([A-Z_]+)\s*,\s*/', $source, $calls, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($calls[0] as $index => $match) {
                $literal = self::followingLiteral($source, $match[1] + strlen($match[0]));

                if (null === $literal) {
                    // Not a payload call: `::class_exists(SecurityEvents::X)`, or a
                    // constant passed to something that takes a value, not an array.
                    continue;
                }

                $name = $calls[1][$index][0];
                $type = $vocabulary['constants'][$name] ?? null;

                self::assertNotNull(
                    $type,
                    sprintf(
                        '%s sends SecurityEvents::%s, which the vocabulary does not declare. The constants'
                        . ' it does declare are: %s.',
                        $relative,
                        $name,
                        implode(', ', array_keys($vocabulary['constants'])),
                    ),
                );

                preg_match_all("/'([^']+)'\s*=>/", $literal, $named);

                $sites[] = ['type' => $type, 'keys' => $named[1], 'file' => $relative];
            }
        }

        self::assertNotEmpty(
            $sites,
            'No emit site parsed out of ' . implode(', ', self::EMIT_SITES) . ' — every pattern above matched'
            . ' nothing, which is a failure of this check rather than a fact about the code.',
        );

        return $sites;
    }

    /**
     * The array literal starting at the first non-space character at or after
     * `$from`, or null when the next token is not a `[`.
     */
    private static function followingLiteral(string $source, int $from): ?string
    {
        $length = strlen($source);

        while ($from < $length && ctype_space($source[$from])) {
            $from++;
        }

        if ($from >= $length || '[' !== $source[$from]) {
            return null;
        }

        $depth = 0;

        for ($index = $from; $index < $length; $index++) {
            if ('[' === $source[$index]) {
                $depth++;
            } elseif (']' === $source[$index]) {
                $depth--;

                if (0 === $depth) {
                    return substr($source, $from, $index - $from + 1);
                }
            }
        }

        self::fail('An array literal in an emit site is not closed — the pattern above cannot read it.');
    }

    #[Test]
    public function everyEmitSiteSendsExactlyTheKeysTheVocabularyDeclares(): void
    {
        $declared = self::vocabulary()['declared'];

        foreach (self::emitted() as $site) {
            $expected = $declared[$site['type']] ?? null;

            self::assertNotNull(
                $expected,
                sprintf(
                    '%s sends "%s", which payloadKeys() has no arm for. An arm-less type returns [], so'
                    . ' every key this site sends would look invented to the provider seam.',
                    $site['file'],
                    $site['type'],
                ),
            );

            $sent = $site['keys'];
            sort($sent);
            sort($expected);

            self::assertSame(
                $expected,
                $sent,
                sprintf(
                    '%s sends [%s] for "%s", but payloadKeys() declares [%s]. The declaration is what the'
                    . ' provider seam checks a context against and what an emit site is supposed to follow,'
                    . ' so the two cannot disagree: either the arm is short a key the handler reads, or the'
                    . ' site sends one nothing reads. Measured 2026-10-16, `ip` was missing from'
                    . ' USER_CHANGED and ROLE_CHANGED while both sites sent it and'
                    . ' SecurityEventHandler::ip() read it for every message.',
                    $site['file'],
                    implode(', ', $site['keys']),
                    $site['type'],
                    implode(', ', $expected),
                ),
            );
        }
    }

    #[Test]
    public function everyTypeTheVocabularyListsHasAnArmAndAnEmitSite(): void
    {
        $vocabulary = self::vocabulary();

        $sent = array_unique(array_map(
            static fn (array $site): string => $site['type'],
            self::emitted(),
        ));

        sort($sent);
        $listed = $vocabulary['all'];
        sort($listed);

        // `all()` is the guard the drain dispatches by and payloadKeys() is what
        // the provider checks a context against; a type in one and not the other
        // is a type that is either undeclarable or unreadable.
        $armed = array_keys($vocabulary['declared']);
        sort($armed);

        self::assertSame(
            $listed,
            $armed,
            'SecurityEvents::all() and payloadKeys() do not name the same types.',
        );

        self::assertSame(
            $listed,
            $sent,
            'Some type the vocabulary lists is never sent by a parsed emit site (or a site sends one the'
            . ' list omits). This is the check that notices a new event type with no arm, an arm with no'
            . ' site, and a site whose array literal moved out of reach of the pattern above — which is'
            . ' why it compares sets rather than counting.',
        );
    }
}
