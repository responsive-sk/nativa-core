# Proposal: a `scan.entries` row in the conformance battery

**Status:** patch attached (`conformance-scan-row.patch`), compiles and passes
the core conformance suite on the `master` this was written against.

## Why

The battery has no row that exercises `scan`. A target can declare
`scan.symbols: full`, answer `scan` with nothing, with duplicates, or with a
different entry set every run, and still report `verdict pass` with a verified
badge. That is the one declared surface with no observed behavior behind it,
and it is the surface every brownfield onboarding starts from.

`adapter test` currently measures: describe, capability declaration,
confinement, invalid-IR handling, structured diagnostics, validate/verify
determinism, generate/apply/clean, scenario normalization, cancellation,
transport, storage and classification. `scan` appears only as a string in
capability declarations.

## What the row asserts

| Outcome | Condition |
| --- | --- |
| `skipped` / `operation-undeclared` | `scan` is not in the declared operations |
| `fail` / `refusal-class` | an in-envelope error whose class is not `unsupported` |
| `fail` / `no-result`, `no-entries` | a successful envelope without a closed `result.entries` |
| `fail` / `no-truncated` | `truncated` is absent (the wire requires it on a scan result) |
| `fail` / `duplicate-entry` | two entries with the same `(path, kind, detail)` |
| `fail` / `nondeterministic` | two identical scans differ byte-wise |
| `pass` | every condition above holds, **or** the adapter refuses honestly with `unsupported` |

The honest-refusal path is deliberate: the wire says a lossy scan must refuse
rather than truncate, so an `unsupported` refusal is conforming behavior and
must not be scored as a failure. Nothing here reads a refusal as a support
claim; the capability declaration is the only thing that does that.

## What the row deliberately does not assert

Entry paths are **not** checked against the fixture view. The suite's view is
the IR fixture (`.lekalo/ir/*.json`, `src/routes/.keep`), not an enumerable
source tree, so "every entry path exists" would demand a contract the wire
does not state and would fail a conforming adapter that scans a root the
fixture does not carry. If maintainers want that rule, the fixture needs a
declared source root first; that is a separate change with its own fixture.

## Implementation

* `CheckId::ScanEntries` → `"scan.entries"`, class `feature`, group
  `lekalo.adapter.scan`.
* Appended to `CATALOG` (30 entries). Appending keeps every existing row's
  position and only adds a line, so no existing report shifts.
* `scan_phase` runs right after the read-only determinism probes and before
  the generate phase. A terminal transport failure records the failure and
  skips the rest, like every other phase.
* The determinism comparison serializes the two response envelopes; this
  catches the classic failure mode, an entry set assembled from an unordered
  directory walk.

## Verification

* `cargo check -p lekalo-core` — clean.
* `cargo test -p lekalo-core --test adapter_conformance` — **17 passed, 0
  failed**, including the new `the_scan_row_runs_when_declared_and_skips_when_not`
  (the reference adapter passes the row; an adapter declaring
  `describe,validate,verify` skips it with `operation-undeclared`).
* Reference adapter behavior is unchanged: it declares `scan.symbols: full`,
  answers deterministically, and the row records `pass`.

## Apply

```sh
git apply conformance-scan-row.patch
```

## Suggested commit message

```
feat(conformance): observe the declared scan surface

The battery has no scan row: an adapter can declare scan.symbols, answer with
nothing, duplicates or a different set per run, and still take a verified
badge. The row answers scan once, requires the closed result with truncated,
rejects duplicate entries and byte-differing repeats, and accepts an honest
unsupported refusal because the wire requires a lossy scan to refuse. Entry
paths are not checked against the fixture view: that contract would need an
enumerable source root in the fixture first.
```

## Partial support: the missed files have to be visible

`scan.symbols` may be declared `partial`, and the battery currently cannot see
the difference between a partial scan that admits its boundary and one that
looks complete. The wire offers exactly two channels for a file a scan did not
carry:

* `ScanEntry.kind` is a free token, not an enum, so a missed file can be an
  entry with `kind: "skipped"` and a closed reason code in `detail`. This works
  only while the path itself is representable.
* `error.code` / `error.message` (and `error.detail`, 128 bytes) on a refusal.
  This is the only channel for a path the logical-path grammar cannot carry,
  because such a path cannot be an entry either — not even a skipped one.

`findings` is not available: `validate_response_payload` admits it only for
`validate` and `verify`, so a scan result that carries findings is an invalid
response, not a richer one.

The convention this proposal recommends for a `partial` declaration:

1. every PHP file the scan read nothing from is one `skipped` entry with a
   stable reason code (`nativa.scan-symlink`, `nativa.scan-vendor` in the PHP
   adapter), never a silent omission and never a fabricated symbol;
2. a path the grammar cannot carry refuses the exchange, and the refusal counts
   the paths and names the first in sorted order, so the refusal is
   reproducible;
3. `full` must never emit a `skipped` entry — a full scan that skipped a file
   has a false declaration, and that is what a later row should catch.

`responsive-sk/nativa-php-core` pins (1) and (2) in its own gate today
(`scan.partial-skipped`, `scan.partial-refusal-names-file`, next to
`scan.entries`, `scan.refusal-path-grammar` and `scan.determinism`). Pinning
(3) in the battery needs a fixture adapter variant that declares `full` and
answers with a `skipped` entry; that variant does not exist yet, so this patch
does not claim the rule.

## Open questions for maintainers

1. Should `scan.symbols: full` require a non-empty entry set? The row cannot
   require it today without a fixture whose roots every adapter already scans.
2. The PHP adapter in `responsive-sk/nativa-php-core` declares
   `scan.symbols: partial` and refuses with `nativa.scan-path-grammar` when a
   path is outside the wire's lowercase grammar. That refusal passes this row
   by design; if maintainers prefer a `partial`-specific row, it belongs in the
   same phase and should assert the `skipped` convention above rather than a
   particular reason vocabulary.
3. Should a `skipped` entry count as an entry for `constraints.max_entries`?
   The PHP adapter counts it, so a tree of symlinks cannot push the symbol
   entries past the bound while the skips stay unreported.
