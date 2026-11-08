<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ModelPayloadContractTest — the model's declared payloads against the payloads
 * the code owns.
 *
 * Measured 2026-10-16, before this round, for `users.authorization_denied`:
 *
 *     model payload      -> required, path, user_id, ip            (4)
 *     payloadKeys()      -> path, required, userId, roles, ip      (5)
 *     in code, not model -> roles
 *     in model, not code -> (none)
 *     the model's own DESCRIPTION lists roles                      (yes)
 *     the model's own PAYLOAD does not                             (the defect)
 *
 * That is the model-side remainder of the defect the code side closed the same
 * day: `payloadKeys()` declared `ip` for both refusal types while the Audit
 * handler read it and every emit site sent it — a key present in prose and
 * absent from the list, which is exactly the shape here. Both emit sites DO
 * send `roles` (`OutboxSecurityEventSink::authorizationDenied()` — the module's
 * side of the kernel port that `AuthMiddleware::recordDenial()` calls — and
 * `SecurityEventServices::bulkRefused()`), and `SecurityEvents` is the one owner
 * of that vocabulary
 * ("no emit site and no handler spells an event type or invents a payload key
 * of its own"), so the model was the only side still describing four keys.
 *
 * Nothing else read this either: `ModelReferenceContractTest` beside it
 * resolves the `error_ref`s in `lekalo/authorization.yaml`, and
 * `ModelCatalogueContractTest` compares the model's module SET. Neither opens
 * an event's `payload`.
 *
 * The same question was then asked of the ARTICLE events, and the model failed it
 * in both directions. Measured the same day: `blog.article_published` declared
 * `slug, locale, published_at` while the one emit site —
 * `OutboxServices::articleEvent()`, the only place either type is recorded —
 * builds `['slug' => $slug, 'locale' => $locale]` and carries the acting user as
 * METADATA (`['actor' => $actor]`). The article's date is front matter, not event
 * data. And the model declared no event at all for `article.unpublished`, the
 * other half of the toggle the code has raised from that same call site since it
 * shipped. Both are declared now, both name their constant, and both are compared
 * against that literal — the article vocabulary has no `payloadKeys()` to obey,
 * which is exactly why the MODEL is the side compared to it here rather than an
 * emit site being compared to a declaration.
 *
 * Deliberately dumb, in the shape of its neighbours, and for a harder reason
 * than theirs: `SecurityEvents` lives in an OPTIONAL module, and `phpstan.neon`
 * analyses `tests/` while `src/Modules/<Name>` outside Core/Database is
 * operator state. Loading that class here would make the kernel-only gate fail
 * in a tree where Outbox is not installed — so the file is read as TEXT, the
 * way the module scanner reads a class it will not load and the way
 * `ModelReferenceContractTest` reads a YAML file with no parser.
 *
 * @internal
 *
 * @coversNothing
 */
final class ModelPayloadContractTest extends TestCase
{
    /**
     * The event this binding was written for, named rather than matched, so a
     * rewrite that drops the vocabulary from a description cannot quietly
     * narrow what this file covers — the same reason `ModelCatalogueContractTest`
     * names the kernel slices and the catalogue fixture instead of excusing
     * them with a pattern.
     */
    private const string ANCHOR_EVENT = 'users.authorization_denied';

    /**
     * The constant the anchor event is recorded as, and the type it carries.
     */
    private const string ANCHOR_CONSTANT = 'AUTHZ_DENIED';

    /**
     * Where the article vocabulary is declared: two constants and one payload
     * literal, built by the single call site `articleEvent()`.
     */
    private const string ARTICLE_VOCABULARY = 'modules/Outbox/Interfaces/HTTP/OutboxServices.php';

    /**
     * Every event the model declares, with its payload key names.
     *
     * `lekalo/modules/<module>/events.yaml`, JSON content despite the suffix.
     * Payload shape is asserted as it is read, because the key sets below are
     * compared after a snake_case→camelCase fold: a name that is not snake_case
     * would be folded into something the code never declares, and the failure
     * would name the code instead of the model.
     *
     * @return array<string, array{file: string, keys: list<string>, description: string}>
     */
    private static function modelledEvents(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/lekalo/modules/*/events.yaml');

        self::assertIsArray($files, 'lekalo/modules/*/events.yaml is not readable.');
        self::assertNotEmpty($files, 'No model event slices found — lekalo/modules is empty or moved.');

        $events = [];

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);

            self::assertIsArray($decoded, sprintf('%s is not a JSON model document.', $file));

            $definitions = $decoded['definitions'] ?? [];

            self::assertIsArray($definitions, sprintf('%s has no definitions list.', $file));

            foreach ($definitions as $definition) {
                self::assertIsArray($definition, sprintf('%s has a non-object definition.', $file));
                self::assertSame(
                    'event',
                    $definition['kind'] ?? null,
                    sprintf('%s holds a definition that is not an event.', $file),
                );

                $id = $definition['id'] ?? null;
                self::assertIsString($id, sprintf('%s has an event with no id.', $file));
                self::assertArrayNotHasKey($id, $events, sprintf('Event "%s" is declared twice.', $id));

                $payload = $definition['payload'] ?? [];
                self::assertIsArray($payload, sprintf('Event "%s" has a non-list payload.', $id));

                $keys = [];
                $description = $definition['description'] ?? '';

                foreach ($payload as $entry) {
                    self::assertIsArray($entry, sprintf('Event "%s" has a non-object payload entry.', $id));

                    $name = $entry['name'] ?? null;

                    self::assertIsString($name, sprintf('Event "%s" has a payload entry with no name.', $id));
                    self::assertMatchesRegularExpression(
                        '/^[a-z][a-z0-9_]*$/',
                        $name,
                        sprintf(
                            'Event "%s" declares the payload key "%s". The model names payload keys in'
                            . ' snake_case and the code names them in camelCase; a key outside that shape'
                            . ' cannot be folded into the other side without guessing, which is not'
                            . ' something this check may do.',
                            $id,
                            $name,
                        ),
                    );
                    self::assertNotContains(
                        $name,
                        $keys,
                        sprintf('Event "%s" declares the payload key "%s" twice.', $id, $name),
                    );

                    $keys[] = $name;
                }

                self::assertNotEmpty(
                    $keys,
                    sprintf(
                        'Event "%s" declares no payload key at all. An event nobody can read is'
                        . ' documentation-shaped, not a contract — if it really carries nothing,'
                        . ' say so in the description rather than leaving the list empty here.',
                        $id,
                    ),
                );

                $events[$id] = [
                    'file'        => $file,
                    'keys'        => $keys,
                    'description' => is_string($description) ? $description : '',
                ];
            }
        }

        return $events;
    }

    /**
     * The code's event-type vocabulary and its payload keys, read as TEXT.
     *
     * From `modules/Outbox/Domain/SecurityEvents.php` — the committed catalogue
     * copy, which is the source the installed slice is copied FROM, so reading
     * it cannot observe a different vocabulary than the one an emit site uses.
     *
     * @return array{constants: array<string, string>, keys: array<string, list<string>>}
     */
    private static function securityVocabulary(): array
    {
        $file = dirname(__DIR__, 2) . '/modules/Outbox/Domain/SecurityEvents.php';

        self::assertFileExists(
            $file,
            'modules/Outbox/Domain/SecurityEvents.php is missing — the catalogue is the vocabulary owner.',
        );

        $source = (string) file_get_contents($file);

        $constants = [];

        if (preg_match_all("/public const string (\w+) = '([^']*)';/", $source, $declarations, PREG_SET_ORDER) > 0) {
            foreach ($declarations as $declaration) {
                $constants[$declaration[1]] = $declaration[2];
            }
        }

        // Only the payloadKeys() body: `self::X => [...]` appears nowhere else in
        // the file (measured: the constants are `= '…'`, and `all()` is a plain
        // list), so the slice keeps a future match arm somewhere else from being
        // counted as a payload declaration.
        $body = false === ($start = strpos($source, 'function payloadKeys'))
            ? ''
            : substr($source, $start);

        $keys = [];

        if (preg_match_all('/self::(\w+)\s*=>\s*\[([^\]]*)\]/', $body, $arms, PREG_SET_ORDER) > 0) {
            foreach ($arms as $arm) {
                preg_match_all("/'([^']+)'/", $arm[2], $named);
                $keys[$constants[$arm[1]] ?? $arm[1]] = $named[1];
            }
        }

        self::assertNotEmpty(
            $constants,
            'No SecurityEvents constants parsed out of the catalogue file — it was rewritten, and'
            . ' this check is now reading nothing.',
        );
        self::assertNotEmpty(
            $keys,
            'No payloadKeys() arms parsed out of the catalogue file — the match was rewritten, and'
            . ' this check is now reading nothing.',
        );

        return ['constants' => $constants, 'keys' => $keys];
    }

    /**
     * The ARTICLE vocabulary: the two event types `OutboxServices` emits, and the
     * payload `articleEvent()` builds for them.
     *
     * Measured 2026-10-16, why this is here rather than in
     * `SecurityEventPayloadContractTest` beside it: that file asks whether an emit
     * site obeys a declaration. `articleEvent()` has no declaration to obey — it
     * builds `['slug' => $slug, 'locale' => $locale]` inline, once — so there is
     * nothing to compare a site against, and instead the MODEL is compared to the
     * one literal. Which matters, because the model declared `slug, locale,
     * published_at` for `blog.article_published` while the code sends two keys and
     * no timestamp (the article's date is front matter), and had no event at all
     * for `article.unpublished`, the other of the two types this file names.
     *
     * Only the PAYLOAD literal is read. `['actor' => $actor]` is the metadata
     * argument beside it, a different channel: the model says so in prose ("the
     * acting user travelling as the message's metadata") and declares no key for
     * it, which is a modelling decision rather than a mismatch.
     *
     * Read as TEXT for the same reason the other vocabularies are: Outbox is an
     * optional module and `phpstan.neon` analyses `tests/`.
     *
     * @return array{constants: array<string, string>, keys: array<string, list<string>>}
     */
    private static function articleVocabulary(): array
    {
        $source = self::source(self::ARTICLE_VOCABULARY);

        $constants = [];

        if (preg_match_all("/public const string (ARTICLE_\w+) = '([^']*)';/", $source, $declarations, PREG_SET_ORDER) > 0) {
            foreach ($declarations as $declaration) {
                $constants[$declaration[1]] = $declaration[2];
            }
        }

        self::assertCount(
            2,
            $constants,
            'OutboxServices should declare exactly the two article event types; this check read'
            . ' something else and would otherwise compare the model against a wrong vocabulary.',
        );

        $body = self::methodBody($source, 'articleEvent');
        $create = strpos($body, 'OutboxMessage::create(');

        self::assertNotFalse($create, 'articleEvent() no longer calls OutboxMessage::create() — read how it sends now.');

        // The FIRST literal after the call is the payload: `create($type, [payload],
        // [metadata])`. Searched for rather than expected at the next character,
        // because `$type` sits between the parenthesis and the payload.
        $literal = strpos($body, '[', $create);

        self::assertNotFalse($literal, 'articleEvent() passes OutboxMessage::create() no payload literal.');

        $payload = self::followingLiteral($body, $literal);

        self::assertNotNull($payload, 'The first literal in articleEvent() is not an array literal.');

        preg_match_all("/'([^']+)'\s*=>/", $payload, $named);

        self::assertNotEmpty($named[1], 'The article payload literal names no keys.');

        $keys = [];

        foreach ($constants as $type) {
            $keys[$type] = $named[1];
        }

        return ['constants' => $constants, 'keys' => $keys];
    }

    /**
     * @return string the catalogue file's source, asserted readable
     */
    private static function source(string $relative): string
    {
        $file = dirname(__DIR__, 2) . '/' . $relative;

        self::assertFileExists($file, sprintf('%s is missing — the vocabulary cannot be read.', $relative));

        return (string) file_get_contents($file);
    }

    /**
     * One method's body, from its declaration to the closing brace on its own
     * indentation.
     */
    private static function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method . '(');

        self::assertNotFalse($start, sprintf('%s() was not found in the vocabulary file.', $method));

        $end = strpos($source, "\n    }", $start);

        self::assertNotFalse($end, sprintf('%s() has no closing brace at method indent.', $method));

        return substr($source, $start, $end - $start);
    }

    /**
     * The array literal starting at the first non-space character at or after
     * `$from`, or null when the next token is not a `[`.
     *
     * Bracket-matched rather than a flat `[^\]]*` run: the same mistake was made
     * and caught in `SecurityEventPayloadContractTest`, where a payload containing
     * `'roles' => []` was truncated at the inner bracket and the check accused the
     * code of a defect it did not have.
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

        self::fail('An array literal in the article emit site is not closed.');
    }

    /**
     * The model key names, folded onto the code's camelCase form.
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private static function asCamelCase(array $keys): array
    {
        return array_map(
            static fn (string $key): string => (string) preg_replace_callback(
                '/_([a-z])/',
                static fn (array $match): string => strtoupper($match[1]),
                $key,
            ),
            $keys,
        );
    }

    #[Test]
    public function everyModelledEventThatNamesACodeTypeDeclaresTheKeysTheCodeDeclares(): void
    {
        $vocabulary = self::securityVocabulary();
        $article = self::articleVocabulary();
        $events = self::modelledEvents();

        $checked = 0;

        foreach ($events as $id => $event) {
            // The model states which code type it is recorded as, e.g.
            // "Recorded through the Outbox as SecurityEvents::AUTHZ_DENIED", or
            // "as OutboxServices::ARTICLE_PUBLISHED, article.published".
            if (1 !== preg_match('/(SecurityEvents|OutboxServices)::(\w+)/', $event['description'], $named)) {
                continue;
            }

            $owner = $named[1];
            $constant = $named[2];
            $declaring = 'SecurityEvents' === $owner ? $vocabulary : $article;
            $eventType = $declaring['constants'][$constant] ?? null;

            self::assertNotNull(
                $eventType,
                sprintf(
                    'Event "%s" (%s) says it is recorded as %s::%s, which the code no longer'
                    . ' declares. Either the constant was renamed and the description was not, or the'
                    . ' reference was never real — the constants that file declares are: %s.',
                    $id,
                    $event['file'],
                    $owner,
                    $constant,
                    implode(', ', array_keys($declaring['constants'])),
                ),
            );

            $declared = $declaring['keys'][$eventType] ?? null;

            self::assertNotNull(
                $declared,
                sprintf(
                    '%s::%s resolves to "%s", for which no payload is declared, so the model cannot'
                    . ' be checked against it.',
                    $owner,
                    $constant,
                    $eventType,
                ),
            );

            $modelKeys = self::asCamelCase($event['keys']);
            sort($modelKeys);
            sort($declared);

            self::assertSame(
                $declared,
                $modelKeys,
                sprintf(
                    'Event "%s" declares payload keys [%s], but %s::%s ("%s") declares [%s]. The'
                    . ' code is the owner of this vocabulary, so the model is the side to correct —'
                    . ' and the model must describe what is sent, not what would be nicer to send.'
                    . ' `roles` reached the refusal list that way, and `published_at` left the'
                    . ' article payload that way (measured 2026-10-16).',
                    $id,
                    implode(', ', $event['keys']),
                    $owner,
                    $constant,
                    $eventType,
                    implode(', ', $declared),
                ),
            );

            $checked++;
        }

        self::assertGreaterThan(
            2,
            $checked,
            'Fewer than three modelled events name a code constant, so this check proves less than it'
            . ' is written to. That is a fact about the model, not about the code: the refusal event'
            . ' and the publish/unpublish pair name one each, and both are asserted by name in the'
            . ' tests below. If one of them stopped naming its constant, say so deliberately rather'
            . ' than letting this check quietly cover less.',
        );
    }

    /**
     * The article pair, asserted by name for the same reason the refusal event is:
     * they are the events this file was extended for, and a description rewrite
     * that dropped the constant would otherwise narrow the check in silence.
     */
    #[Test]
    public function theArticlePublishPairIsBoundToTheOutboxVocabulary(): void
    {
        $articles = self::articleVocabulary();
        $events = self::modelledEvents();

        $pair = [
            'blog.article_published'   => 'ARTICLE_PUBLISHED',
            'blog.article_unpublished' => 'ARTICLE_UNPUBLISHED',
        ];

        foreach ($pair as $id => $constant) {
            self::assertArrayHasKey(
                $id,
                $events,
                sprintf(
                    'The model declares no "%s". The code has raised both halves of the toggle from one'
                    . ' call site since it shipped: a model with the publish and not the unpublish is'
                    . ' the state Boot 32 called out from the other direction.',
                    $id,
                ),
            );

            self::assertStringContainsString(
                'OutboxServices::' . $constant,
                $events[$id]['description'],
                sprintf(
                    'Event "%s" no longer names OutboxServices::%s, so the payload comparison above'
                    . ' skips it and the article half of this binding goes unchecked.',
                    $id,
                    $constant,
                ),
            );

            $eventType = $articles['constants'][$constant] ?? null;
            self::assertNotNull($eventType, sprintf('OutboxServices::%s is not declared.', $constant));

            $modelKeys = self::asCamelCase($events[$id]['keys']);
            sort($modelKeys);
            $declared = $articles['keys'][$eventType] ?? [];
            sort($declared);

            self::assertSame(
                $declared,
                $modelKeys,
                sprintf(
                    '"%s" must carry exactly the keys articleEvent() builds for "%s", whatever the'
                    . ' generic loop above concludes.',
                    $id,
                    $eventType,
                ),
            );
        }
    }

    #[Test]
    public function theAnchorRefusalEventIsBoundToTheCodeVocabulary(): void
    {
        $vocabulary = self::securityVocabulary();
        $events = self::modelledEvents();

        self::assertArrayHasKey(
            self::ANCHOR_EVENT,
            $events,
            sprintf(
                'The anchor event "%s" is gone from the model. This file was written for it — a'
                . ' rename is a decision to make here, not a diff to accept.',
                self::ANCHOR_EVENT,
            ),
        );

        $anchor = $events[self::ANCHOR_EVENT];

        self::assertStringContainsString(
            'SecurityEvents::' . self::ANCHOR_CONSTANT,
            $anchor['description'],
            sprintf(
                'The anchor event "%s" no longer names SecurityEvents::%s in its description, so'
                . ' the payload comparison above skips it and the one event this binding exists'
                . ' for is unchecked.',
                self::ANCHOR_EVENT,
                self::ANCHOR_CONSTANT,
            ),
        );

        $eventType = $vocabulary['constants'][self::ANCHOR_CONSTANT] ?? null;
        self::assertNotNull(
            $eventType,
            sprintf('SecurityEvents::%s is not declared in the catalogue copy.', self::ANCHOR_CONSTANT),
        );

        $modelKeys = self::asCamelCase($anchor['keys']);
        sort($modelKeys);
        $declared = $vocabulary['keys'][$eventType] ?? [];
        sort($declared);

        self::assertSame(
            $declared,
            $modelKeys,
            sprintf(
                'The refused-authorization event must carry exactly the keys payloadKeys(\'%s\')'
                . ' declares, whatever the generic loop above concludes.',
                $eventType,
            ),
        );
    }
}
