#!/usr/bin/env bash
#
# Upstream reproduction: `lekalo lock -- <program>` refuses an **installed**
# target adapter with `lock.schema-invalid` (LEK-LOCK-017) on lekalo 1.2.0.
# The same command against the same adapter, not installed, succeeds and pins
# the *executable* digest.
#
# Status: ichinya/lekalo#259, closed as completed by #273 (merge 7441eed,
# 2026-11-21). Against a build of main at a8a6372 this script exits 0; the
# published 1.3.1 CLI still reproduces the refusal, so the fix is on main only.
# The root-cause notes and measurements below are the original 1.2.0 report.
#
# Root cause, in one sentence: the issue #91 catalog seam carries the
# adapter's executable digest in its candidate, but the installed-provenance
# step looks the candidate up by the package digest, finds nothing, and
# refuses with a generic schema error instead of naming the mismatch.
#
#   crates/lekalo-core/src/orchestration/catalog.rs:317-327
#       CandidateAdapter::new(id, version, executable, source, [ArtifactPin],
#                            manifest)      // candidate digest = executable_digest
#   crates/lekalo-core/src/lockfile/resolution.rs:516-555
#       with_installed_provenance(version, package_digest, ...):
#           filter(|a| a.version == version && a.digest == package_digest)
#           if pinned == 0 { return Err(LockFailure::SchemaInvalid) }
#   crates/lekalo-core/src/adapter_package/inventory.rs:214
#       row.digest = manifest.package_digest()
#
# Measured with the adapter package beside this script: the executable digest
# is sha256:bb100064cf9ef86d6d1601b5298e9b18168d9eff5cf29264cd1370f4aaa579da,
# the package digest is sha256:2e064c9e3071f5cedf98ce3a14b4838e04371ac804d945bfdbe75b8873a22209.
# The two are equal only for a package with one file and no manifest, which
# the format never produces: the package digest frames every declared file
# and then the manifest (ADR-0042). So the filter matches nothing for every
# installed adapter of every shape, and no installed adapter can be pinned.
#
# Expected after a fix: scenario 1 is `valid`, and its pin carries what the
# installed provenance is for — `adapters[0].provenance.source.kind`
# `"installed"` with the `install_plan_id` of the plan that installed it.
# This script checks all three and exits 0 only when they hold; it exits 1
# when the pin is refused, and also when a partial fix pins the adapter but
# drops its provenance, so a half-fix cannot read as success.
#
# Usage:
#   LEKALO=/path/to/lekalo-1.2.0 tests/upstream/lock-pin-repro.sh
#   tests/upstream/lock-pin-repro.sh --self-test   # checks this script's own
#                                                  # verdict logic, no CLI
#
# Needs bash and python3 (only to read the JSON documents). It runs in a
# temporary project and touches nothing in this repository.
set -uo pipefail

LEKALO="${LEKALO:-lekalo}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ADAPTER="${ADAPTER:-$HERE/../../adapters/php-nativa}"

jget() { # jget <dotted.path>, JSON on stdin
    python3 -c 'import json, sys
value = json.load(sys.stdin)
for part in sys.argv[1].split("."):
    if value is None:
        break
    value = value[int(part)] if part.isdigit() else (
        value.get(part) if isinstance(value, dict) else None)
print("" if value is None else value)' "$1"
}

reasons() { # reasons, JSON on stdin
    python3 -c 'import json, sys
print(",".join(json.load(sys.stdin).get("reasonCodes") or []))'
}

# The post-fix verdict on one lock document. Prints exactly one token:
#   ok | no-provenance | kind-<kind> | no-plan-id | plan-mismatch
provenance_verdict() { # provenance_verdict <lock-file> <expected-install-plan-id>
    python3 - "$1" "${2:-}" <<'PY'
import json, sys

lock = json.load(open(sys.argv[1]))
expected = sys.argv[2]
try:
    provenance = lock["adapters"][0]["provenance"]
except (KeyError, IndexError, TypeError):
    print("no-provenance")
    raise SystemExit
kind = (provenance.get("source") or {}).get("kind")
plan = provenance.get("install_plan_id")
if kind != "installed":
    print("kind-" + str(kind))
elif not plan:
    print("no-plan-id")
elif expected and plan != expected:
    print("plan-mismatch")
else:
    print("ok")
PY
}

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

if [ "${1:-}" = "--self-test" ]; then
    # A fixed lekalo is not available here, so the verdict logic is proved
    # against three synthetic locks: the expected one, a dropped provenance
    # and a missing plan id. Each case must produce its own token.
    printf '%s' '{"adapters":[{"provenance":{"source":{"kind":"installed"},"install_plan_id":"sha256:aa"}}]}' > "$work/ok.json"
    printf '%s' '{"adapters":[{"source":{"kind":"project"},"digest":"sha256:bb"}]}' > "$work/none.json"
    printf '%s' '{"adapters":[{"provenance":{"source":{"kind":"installed"}}}]}' > "$work/noplan.json"
    failures=0
    expect() { # expect <file> <expected> <expected-plan>
        actual="$(provenance_verdict "$1" "${3:-}")"
        if [ "$actual" = "$2" ]; then
            echo "  pass  $(basename "$1") -> $actual"
        else
            echo "  FAIL  $(basename "$1") -> $actual (wanted $2)"
            failures=$((failures + 1))
        fi
    }
    echo "self-test: provenance verdict"
    expect "$work/ok.json" ok "sha256:aa"
    expect "$work/ok.json" plan-mismatch "sha256:cc"
    expect "$work/none.json" no-provenance
    expect "$work/noplan.json" no-plan-id
    echo "self-test: $([ "$failures" -eq 0 ] && echo ok || echo "$failures failure(s)")"
    [ "$failures" -eq 0 ] || exit 1
    exit 0
fi

cd "$work" || exit 2

"$LEKALO" --version 2>/dev/null | head -1 | sed 's/^/lekalo:  /'
echo "adapter: $ADAPTER"

"$LEKALO" init --json >/dev/null || { echo "init failed in $work"; exit 2; }
mkdir -p adapters
cp -R "$ADAPTER" adapters/php-nativa

executable_digest="sha256:$(sha256sum adapters/php-nativa/adapter.php | cut -d' ' -f1)"
package_digest="$(jget integrity.packageDigest < adapters/php-nativa/adapter.manifest.json)"

plan_json="$("$LEKALO" adapter install path:adapters/php-nativa --dry-run --json)"
plan_id="$(printf '%s' "$plan_json" | jget planId)"
"$LEKALO" adapter install path:adapters/php-nativa --confirm "$plan_id" --json >/dev/null
inventory="$("$LEKALO" adapter list --json)"
inventory_digest="$(printf '%s' "$inventory" | jget packages.0.digest)"

echo
echo "executable digest (what the seam puts in the candidate): $executable_digest"
echo "package digest    (what the inventory row records):     $package_digest"
echo "inventory row digest (what the provenance step filters): $inventory_digest"

echo
echo "== scenario 1: installed adapter, no lock =="
installed_json="$("$LEKALO" lock --json -- php adapters/php-nativa/adapter.php 2>&1)"
installed_status="$(printf '%s' "$installed_json" | jget status 2>/dev/null)"
installed_reasons="$(printf '%s' "$installed_json" | reasons 2>/dev/null)"
echo "status: ${installed_status:-<no envelope>}  reasons: ${installed_reasons:-<none>}"

installed_verdict="<no lock>"
if [ "$installed_status" = "valid" ] && [ -f lekalo.lock ]; then
    echo "provenance.source.kind:     $(jget adapters.0.provenance.source.kind < lekalo.lock)"
    echo "provenance.install_plan_id: $(jget adapters.0.provenance.install_plan_id < lekalo.lock)  (install plan: $plan_id)"
    installed_verdict="$(provenance_verdict lekalo.lock "$plan_id")"
    echo "provenance verdict: $installed_verdict"
fi

echo
echo "== scenario 2: same adapter, not installed (control) =="
rm -f lekalo.lock
rm -rf .lekalo/adapters
control_json="$("$LEKALO" lock --json -- php adapters/php-nativa/adapter.php 2>&1)"
control_status="$(printf '%s' "$control_json" | jget status 2>/dev/null)"
echo "status: ${control_status:-<no envelope>}  reasons: $(printf '%s' "$control_json" | reasons 2>/dev/null)"
if [ -f lekalo.lock ]; then
    echo "pinned digest: $(jget adapters.0.digest < lekalo.lock)  source.kind: $(jget adapters.0.source.kind < lekalo.lock)"
fi

echo
if [ "$control_status" != "valid" ]; then
    echo "INCONCLUSIVE: the control (uninstalled) pin did not succeed, so this"
    echo "machine cannot distinguish the bug from an environment problem."
    exit 2
fi
case "$installed_verdict" in
    ok)
        echo "FIXED: an installed adapter pins, and the pin carries installed"
        echo "provenance with the install plan id."
        exit 0
        ;;
    no-provenance|kind-*|no-plan-id|plan-mismatch)
        echo "PARTIAL FIX: the pin succeeds but its installed provenance is"
        echo "wrong or incomplete (verdict: $installed_verdict). Expected"
        echo "source.kind \"installed\" and install_plan_id \"$plan_id\"."
        exit 1
        ;;
esac
echo "BUG PRESENT: the installed adapter cannot be pinned. The seam's candidate"
echo "carries the executable digest; the provenance step filters by the package"
echo "digest, matches nothing, and reports the generic LEK-LOCK-017."
exit 1
