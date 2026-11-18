# php-nativa — the Nativa PHP kernel target adapter

The target adapter for `lekalo/targets/php-nativa.yaml`. It speaks
`lekalo.target/v1` (negotiated `0.3.2`, IR `0.2.16`) and passes the
upstream conformance battery in the default profile:

```
lekalo adapter test php adapters/php-nativa/adapter.php
# verdict pass (pass 15, fail 0, skipped 14)
# badge verified protocol=0.3.2 ir=0.2.16
```

`make lekalo-adapter-test` runs that battery, then
`bin/build/lekalo-adapter-scan.sh`'s scan rows — a real exchange that asserts
the entry projection, the `skipped` entries a `partial` scan must show, the
typed refusal (and the paths it names) for a path the wire cannot carry, and
determinism over identical bytes, because the conformance suite has no scan row
for a 0.3.2 target. `bin/build/lekalo-adapter-manifest.php [--check]` owns the
manifest digests.

## What it declares, and what it does not

The surface is deliberately small and honest:

| Operation | Behavior |
|---|---|
| `describe` | identity, protocol `0.3.2`, IR `0.2.16`, operations `describe`/`scan`/`validate`/`verify`, transports `file`/`stdin`, read scopes `.lekalo/ir/**`, `src/**`, `modules/**`, `bin/**`, `tests/**`, **no write scope** |
| `scan` | walks those four roots in the granted project view and reports one entry per PHP declaration — class-like, function, method, constant — named by its namespace-qualified name, from `token_get_all`, so no file is ever executed. A PHP file it does not read (a symlink, or one under `vendor/`) is a `skipped` entry with a closed reason code, so the entry set never reads as the whole tree |
| `validate` | reads the wire IR from the granted view; refuses a missing, unreadable, unparseable or non-object document with a closed error envelope |
| `verify` | same probe, then an honest empty finding set |

`scan.symbols` is `partial`, not `full`. The wire's `logicalPath` grammar
admits lowercase segments only, at every admitted protocol version, while this
kernel's tree is `src/Modules/<Name>/<File>.php`. A scan cannot lowercase,
rename or drop such a path without lying about where a symbol lives, and the
protocol requires a lossy scan to refuse — so the scanner reports the real
relative paths and refuses the whole exchange with `nativa.scan-path-grammar`
when the wire cannot carry them. That refusal counts the paths and names the
alphabetically first one, because a path the grammar cannot carry cannot be an
entry either — not even a `skipped` one — so the refusal is the only place it
can be seen. It never fabricates a path and never quietly skips a file. On a tree whose paths the grammar admits, the same scan answers
with entries; a member of an anonymous class is reported as a `method` by its
bare name, because it has no stable qualified name to report.

`verify.scenarios` is `unsupported`, declared rather than omitted, so nothing
can read absence as support. There is no `generate`, no `plan-clean`, and no
write authority: the fourteen skipped battery rows are the operations this
adapter never claims (`generate.*`, `plan.clean-cycle`,
`artifact.manifest-evidence`, `transport.*`, `storage.*`).

Scanning symbols, generating artifacts and scenario execution are the next
increments, not silent behavior. Upstream's `adapters/php-laravel` package is
the reference for that larger surface.

## Files

- `adapter.php` — the whole adapter: the vendored, unmodified generated
  `lekalo-adapter-sdk-php` bundle v0.8.0 (protocol vocabulary, strict wire
  decoding, builders, serve loop) followed by this project's handler section.
  The confined host copies exactly this one file; it needs nothing but PHP.
- `adapter.manifest.json` — the `lekalo/adapter-manifest/v0.6.4` package
  manifest: identity, compatibility, capabilities, permissions, per-file
  digests, package digest.
- `notice.md` — the third-party notice for the vendored SDK
  (MIT OR Apache-2.0) and the license file the manifest points at.

## Installing it into the project

A `path:` package is trusted at `local-development` and never auto-selects
outside the owning project:

```
lekalo adapter install path:adapters/php-nativa --dry-run
lekalo adapter install path:adapters/php-nativa --confirm sha256:<plan id>
lekalo adapter list
```

The install store (`.lekalo/adapters/**`) is local runtime custody, not
committed. Re-run the install after changing the adapter's bytes: the
package digest is the installed identity, so new bytes are a new package.

## Why the digests are computed, not written by hand

`integrity.packageDigest` is the package identity the store pins, and the
verifier recomputes it from the exact bytes before any execution. It is
SHA-256 over the framed byte set (ADR-0042): every declared file in
path-sorted order — `path NUL <8-byte big-endian length> NUL bytes`, each
part prefixed with its own length — followed by the manifest's canonical
bytes with `packageDigest` zeroed and `manifestDigest` removed.
