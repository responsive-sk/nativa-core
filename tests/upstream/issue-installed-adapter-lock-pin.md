# Installed target adapters cannot be pinned: `lekalo lock -- <program>` refuses with `lock.schema-invalid`

**Area:** CLI / lockfile — issue #91 catalog seam, issue #32 installed provenance
**Version:** lekalo 1.2.0 (resolver 0.2.16, registry 1.1.0, target protocol 0.3.2)
**Severity:** blocking. No installed adapter of any shape can be pinned, so
`adapters.inventory` stays blocked in `doctor` (`targets-without-adapters`) for
every project that installs one.

## Summary

`lekalo lock -- <program>` is the documented way to discover an adapter through
its describe handshake and pin it into a created lock. It succeeds when the
adapter is **not** installed in `.lekalo/adapters`. Once the same package is
installed, the same command refuses with

```
status: invalid
reasonCodes: ["lock.schema-invalid"]
LEK-LOCK-017: "The lock file is not a valid v1 wire document."
data: {}
```

There is no lock file at that point — the message describes a read failure,
while the real failure is an internal digest comparison. The command gives no
hint about which digest disagreed.

## Reproduction

`tests/upstream/lock-pin-repro.sh` (attached to this report) runs the whole
thing in a temporary project:

```sh
LEKALO=/path/to/lekalo-1.2.0 tests/upstream/lock-pin-repro.sh
```

By hand, in an empty directory:

```sh
lekalo init
mkdir -p adapters && cp -R <adapter-package> adapters/php-nativa
plan=$(lekalo adapter install path:adapters/php-nativa --dry-run --json | ...)  # read planId
lekalo adapter install path:adapters/php-nativa --confirm "$plan"             # status: valid
lekalo lock --json -- php adapters/php-nativa/adapter.php                     # invalid, LEK-LOCK-017
rm -rf .lekalo/adapters && rm -f lekalo.lock
lekalo lock --json -- php adapters/php-nativa/adapter.php                     # valid
```

The adapter is a complete, conforming package: `lekalo adapter install` reports
`valid` at `local-development`, and `lekalo adapter test php …` reports
`pass 15, fail 0, skipped 14`, badge `verified protocol=0.3.2 ir=0.2.16`. The
package format is not the variable here; the install store is.

## Root cause

Two different digests are compared.

1. The seam builds its candidate from the discovered **executable**:
   `crates/lekalo-core/src/orchestration/catalog.rs:303-328`

   ```rust
   let adapter = CandidateAdapter::new(
       ComponentId::parse(&discovered.adapter.id)?,
       SemVer::parse(&discovered.adapter.version)?,
       executable,          // <- candidate digest becomes executable_digest
       source,              // SourceRef::new(Project, supply.source_id, executable)
       vec![ArtifactPin::new(platform, executable)],
       manifest,
   );
   ```

2. `run_lock` then, when an installed row matches `(id, version)`, asks for
   installed provenance using the **package** digest of that row:
   `crates/lekalo-cli/src/main.rs:3656+`

   ```rust
   candidates.with_installed_provenance(
       &row.version, &row.digest, &row.manifest_digest, …)
   ```

   where `row.digest` is `manifest.package_digest()`
   (`crates/lekalo-core/src/adapter_package/inventory.rs:214`).

3. The provenance step filters the candidate set by that value:
   `crates/lekalo-core/src/lockfile/resolution.rs:516-555`

   ```rust
   for adapter in self.adapters.iter_mut().filter(|a|
           a.version.as_str() == version && a.digest.as_str() == package_digest)
   { … pinned += 1; }
   if pinned == 0 {
       return Err(LockFailure::SchemaInvalid);   // <- LEK-LOCK-017
   }
   ```

Measured with one package, in one project, with 1.2.0:

| value | digest |
| --- | --- |
| `sha256(adapter.php)`, the candidate’s digest | `sha256:bb100064cf9ef86d6d1601b5298e9b18168d9eff5cf29264cd1370f4aaa579da` |
| `manifest.integrity.packageDigest`, the inventory row | `sha256:2e064c9e3071f5cedf98ce3a14b4838e04371ac804d945bfdbe75b8873a22209` |

They cannot agree. The package digest is framed over every declared file in
path order **and then the manifest** (ADR-0042, `adapter_package/integrity.rs`),
while the candidate carries the digest of the single entry file. The two are
equal only for a package with exactly one file and no manifest, which the
format does not produce. So the filter matches nothing for every installed
adapter, and the failure is universal rather than package-specific.

The generic `SchemaInvalid` (`lock.schema-invalid`, “The lock file is not a
valid v1 wire document”) is raised for what is really a candidate/provenance
mismatch, which is why the report is not actionable.

## Expected

* `lock -- php <path>` on an installed package creates a lock that pins the
  package with installed provenance:
  `adapters[0].provenance.source.kind == "installed"` and
  `adapters[0].provenance.install_plan_id` equal to the confirmed install plan.
* A genuine mismatch (installed bytes no longer match the discovered program)
  refuses with a diagnostic that names the cause, e.g. `adapter.source-changed`
  or a dedicated `lock.candidate-provenance-mismatch`, not `lock.schema-invalid`.

## Proposed fix

Preferred: carry the package identity the candidate actually belongs to.

* Add the package digest (and manifest digest) to `CandidateAdapter` when the
  supply is a `path:` package, so the candidate has both “what bytes run” and
  “which package they came from”. `with_installed_provenance` then matches on
  the package identity, and the lock can pin `digest` = package digest with the
  executable kept in `artifacts`.

Minimal alternative, if the candidate must keep one digest: match on
`(id, version)` and verify the artifact digest separately, refusing when
`row.manifest_digest` contradicts the candidate’s manifest rather than
returning `SchemaInvalid` for a lookup that can never hit.

Either way, replace `Err(LockFailure::SchemaInvalid)` in the `pinned == 0`
branch with a diagnostic that names the two identities that failed to meet.
The same branch should distinguish “nothing to attach provenance to” from
“provenance contradicts the package”.

## Workaround until fixed

Create the lock **before** installing the adapter:

```sh
lekalo lock -- php adapters/php-nativa/adapter.php   # pins source.kind project
lekalo adapter install path:adapters/php-nativa --dry-run --json
lekalo adapter install path:adapters/php-nativa --confirm <planId>
```

The pin then exists in `lekalo.lock` (with `source.kind: "project"`, no
provenance), and `lock --check` stays satisfied because the verification
request is merged with the identities the lock already pins. Provenance
(`source.kind: "installed"`, plan id) is not recorded this way.

## Acceptance criteria

* `tests/upstream/lock-pin-repro.sh` exits 0: the installed adapter pins, the
  lock carries `provenance.source.kind == "installed"` and an
  `install_plan_id`, and the control scenario still pins without an install.
* The script exits 1 with `PARTIAL FIX` when a pin appears without provenance.
* A deliberately tampered installed package refuses with a diagnostic naming
  the mismatch, not `lock.schema-invalid`.
* `doctor` on a project with one installed adapter no longer reports
  `adapters.inventory blocked targets-without-adapters`.
