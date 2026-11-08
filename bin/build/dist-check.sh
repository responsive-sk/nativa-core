#!/bin/bash
#
# =============================================================================
# dist-check.sh — build the artifact from a CLEAN CLONE and prove it boots.
# =============================================================================
# `bin/build/build-dist.sh` copies the working tree, and the claim a deployment
# makes is the one this gate tests: a fresh clone builds a green artifact. So
# this CLONES the repo into a temp directory and builds there — tracked files
# only, no `vendor/`, no locally installed slice under `src/Modules/`, no
# `storage/data/`. Anything the build borrows from the developer's checkout
# fails HERE instead of on a server.
#
# What it checks, in order:
#   1. the clone is clean: no untracked and no ignored files;
#   2. `vendor/` is absent BEFORE the build — the whole point of the job;
#   3. `build-dist.sh` exits 0 with its default flags. `--skip-assets` is passed
#      because the bundles are committed and rebuilding them is `make assets`,
#      a different gate; the migrate, deployment-profile and smoke steps all run;
#   4. the artifact carries no `vendor/`, its `.build-info` says `VENDOR=absent`,
#      and the build's own smoke test reached a clean error log.
#
# Usage: bin/build/dist-check.sh   (CI runs `make dist-check`)
#
# Note: the clone is of HEAD, not of the working tree — uncommitted edits are
# deliberately invisible here, because that is what a deployment would get.
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/nativa-dist-check.XXXXXX")"
TREE="$WORK/tree"
LOG="$WORK/build.log"

cleanup() {
    rm -rf "$WORK"
}
trap cleanup EXIT

fail() {
    echo "dist-check: $1" >&2
    exit 1
}

step() {
    echo "==> $*"
}

step "Cloning $ROOT into $TREE (HEAD, tracked bytes only — a deployment's start)"
git clone --quiet "$ROOT" "$TREE"

if [ -d "$TREE/vendor" ]; then
    fail "the clone carries vendor/ — this is not a clean checkout"
fi

dirty="$(git -C "$TREE" status --porcelain --ignored)"
if [ -n "$dirty" ]; then
    fail "the clone is not clean:
$dirty"
fi

step "Building the artifact (default flags, migrate + profile + smoke included)"
if ! "$TREE/bin/build/build-dist.sh" --skip-assets > "$LOG" 2>&1; then
    tail -40 "$LOG" >&2
    fail "build-dist.sh failed in a clean clone (tail of its log above)"
fi

if [ ! -d "$TREE/dist" ]; then
    fail "the build exited 0 without producing dist/"
fi

if [ -d "$TREE/dist/vendor" ]; then
    fail "the artifact carries vendor/ — the kernel runtime has no Composer packages"
fi

if [ ! -f "$TREE/dist/.build-info" ]; then
    fail "the artifact carries no .build-info"
fi

if ! grep -qx 'VENDOR=absent' "$TREE/dist/.build-info"; then
    fail ".build-info does not claim VENDOR=absent"
fi

if ! grep -q 'error log is clean' "$LOG"; then
    fail "the smoke test did not report a clean error log"
fi

files="$(find "$TREE/dist" -type f | wc -l | tr -d ' ')"
size="$(du -sh "$TREE/dist" | cut -f1)"
identity="$(sed -n 's/^  identity:   //p' "$LOG" | head -1)"

step "OK — clean clone → $size, $files files, no vendor/, smoke clean"
echo "    identity: ${identity:-unknown}"
