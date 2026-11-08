<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ModelReferenceContractTest — the authorization document against the model.
 *
 * Measured 2026-10-16, before this round:
 *
 *     grep -c 'error_ref:' lekalo/authorization.yaml           -> 15
 *     grep -c 'users\.error\.' lekalo/authorization.yaml       -> 15
 *     lekalo inspect users.error.forbidden    -> exit 1 [LEK-INS-001] symbol-unknown
 *     lekalo inspect users.error.unauthorized -> exit 1 [LEK-INS-001] symbol-unknown
 *     make lekalo-check                        -> 23 rules, 0 errors, 0 warnings (exit 0)
 *
 * Fifteen references to two symbols that exist nowhere, and the gate green. The
 * limit is GRANULARITY, not absence: the tool resolves the referenced MODULE and
 * denies with `LEK-AUTH-005` when it is missing from the model, but it does not
 * resolve the symbol, and it accepts any string in `error_ref`. Nothing else
 * read this either — `ModelCatalogueContractTest` beside it compares the model's
 * module SET and never opens this file.
 *
 * The model has no `error` kind at all (its vocabulary is closed: command,
 * effect, endpoint, entity, enum, event, policy, query, scalar, scenario,
 * target-binding, value-object), so the symbols those refs named could not have
 * been created. They now name the event the code actually raises —
 * `users.authorization_denied`, the one denial path in
 * `Modules\Core\Interfaces\HTTP\Middleware\AuthMiddleware` (recorded through
 * the kernel's `SecurityEventSink` port as `SecurityEvents::AUTHZ_DENIED`,
 * `security.authz.denied`, HTTP 403) — and this test is what keeps them honest.
 *
 * Deliberately dumb, like its neighbours: a line regex over the one YAML file in
 * `lekalo/` (there is no YAML parser in this project — the same reason
 * `PageFileStorage` splits front matter by hand) and an id index built from the
 * slices, which ARE JSON.
 *
 * @internal
 *
 * @coversNothing
 */
final class ModelReferenceContractTest extends TestCase
{
    /**
     * Model slice files, relative to the project root.
     *
     * `lekalo/modules/<module>/<family>.yaml`, JSON content despite the suffix,
     * measured: 64 files, one `definitions` list each.
     *
     * @return list<string>
     */
    private static function modelFiles(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/lekalo/modules/*/*.yaml');

        if (false === $files) {
            return [];
        }

        sort($files);

        return $files;
    }

    /**
     * Every symbol the model declares: id ⇒ kind.
     *
     * @return array<string, string>
     */
    private static function modelSymbols(): array
    {
        $files = self::modelFiles();

        self::assertNotEmpty(
            $files,
            'No model slice files found — lekalo/modules/*/*.yaml is empty or moved.',
        );

        $symbols = [];

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);

            self::assertIsArray(
                $decoded,
                sprintf('%s is not a JSON model document, so its symbols are invisible here.', $file),
            );

            $definitions = $decoded['definitions'] ?? [];

            self::assertIsArray($definitions, sprintf('%s has no definitions list.', $file));

            foreach ($definitions as $definition) {
                self::assertIsArray($definition, sprintf('%s has a non-object definition.', $file));
                self::assertIsString(
                    $definition['id'] ?? null,
                    sprintf('%s has a definition with no id.', $file),
                );

                $symbols[$definition['id']] = is_string($definition['kind'] ?? null) ? $definition['kind'] : '';
            }
        }

        self::assertNotEmpty($symbols, 'The model declares no symbols at all.');

        return $symbols;
    }

    /**
     * The authorization document's policy ids, and every `error_ref` it carries.
     *
     * Sections are tracked by their top-level key so that a role's `- id:` is
     * never counted as a policy: `roles`, `policies` and `mappings` all use the
     * same list shape.
     *
     * @return array{policies: list<string>, refs: list<array{policy: string, ref: string, line: int}>}
     */
    private static function authorizationDocument(): array
    {
        $lines = file(dirname(__DIR__, 2) . '/lekalo/authorization.yaml', FILE_IGNORE_NEW_LINES);

        self::assertIsArray($lines, 'lekalo/authorization.yaml is not readable.');

        $policies = [];
        $refs = [];
        $section = '';
        $policy = '';

        foreach ($lines as $index => $line) {
            if (1 === preg_match('/^(\w+):/', $line, $sectionMatch)) {
                $section = $sectionMatch[1];
            }

            if (1 === preg_match('/^\s+-\s+id:\s*(\S+)/', $line, $idMatch)) {
                $policy = $idMatch[1];

                if ('policies' === $section) {
                    $policies[] = $policy;
                }

                continue;
            }

            if (1 === preg_match('/^\s+error_ref:\s*"?([^"\s]+)"?/', $line, $refMatch)) {
                $refs[] = [
                    'policy' => '' === $policy ? '(before any policy)' : $policy,
                    'ref'    => $refMatch[1],
                    'line'   => $index + 1,
                ];
            }
        }

        return ['policies' => $policies, 'refs' => $refs];
    }

    #[Test]
    public function everyAuthorizationReferenceResolvesToAModelSymbol(): void
    {
        $symbols = self::modelSymbols();
        $document = self::authorizationDocument();

        self::assertNotEmpty(
            $document['refs'],
            'lekalo/authorization.yaml declares no error_ref at all, so this check proves'
            . ' nothing. That is the state it was written to catch, not a state it accepts:'
            . ' if the field was renamed or the document rewritten, say so here deliberately.',
        );

        foreach ($document['refs'] as $reference) {
            self::assertArrayHasKey(
                $reference['ref'],
                $symbols,
                sprintf(
                    'lekalo/authorization.yaml line %d: policy "%s" raises "%s", which the model'
                    . ' does not declare anywhere. `lekalo validate` accepts this (measured: 23 rules,'
                    . ' 0 errors, exit 0 on 15 such refs) because it resolves the referenced MODULE and'
                    . ' not the symbol — this assertion is the symbol-level half. The symbol must be'
                    . ' created in a module slice, or the reference corrected to one that exists.',
                    $reference['line'],
                    $reference['policy'],
                    $reference['ref'],
                ),
            );
        }
    }

    #[Test]
    public function everyAuthorizationReferenceNamesADenialEvent(): void
    {
        $symbols = self::modelSymbols();

        foreach (self::authorizationDocument()['refs'] as $reference) {
            $kind = $symbols[$reference['ref']] ?? '';

            // The model's kind vocabulary is closed and has no `error`, so a
            // policy cannot name an error symbol however much the field name
            // suggests one. What it names is the event recorded when it denies.
            self::assertSame(
                'event',
                $kind,
                sprintf(
                    '"%s" (policy "%s") is a "%s", not an event. The model has no error kind'
                    . ' (measured: `kind: error` in a slice is refused with LEK-IR-003'
                    . ' ir.kind-unknown), so error_ref carries the event the policy raises when it'
                    . ' denies — the convention comments.comment_submit_denied and'
                    . ' questions.question_submit_denied already follow.',
                    $reference['ref'],
                    $reference['policy'],
                    '' === $kind ? 'symbol that does not exist' : $kind,
                ),
            );

            self::assertStringEndsWith(
                '_denied',
                $reference['ref'],
                sprintf('"%s" is an event but not a denial one.', $reference['ref']),
            );
        }
    }

    #[Test]
    public function everyPolicyDeclaresWhatItRaisesWhenItDenies(): void
    {
        $document = self::authorizationDocument();

        self::assertNotEmpty($document['policies'], 'The document declares no policies.');

        $refsByPolicy = [];

        foreach ($document['refs'] as $reference) {
            $refsByPolicy[$reference['policy']] = ($refsByPolicy[$reference['policy']] ?? 0) + 1;
        }

        foreach ($document['policies'] as $policy) {
            self::assertSame(
                1,
                $refsByPolicy[$policy] ?? 0,
                sprintf(
                    'Policy "%s" declares no error_ref (or more than one). `error_ref` is optional to'
                    . ' the tool — measured: a policy without it validates — which is exactly why a'
                    . ' policy that denies without naming the event would otherwise pass unnoticed.',
                    $policy,
                ),
            );
        }

        self::assertSame(
            count($document['policies']),
            count($document['refs']),
            'Some error_ref sits outside any policy, where nothing reads it.',
        );
    }
}
