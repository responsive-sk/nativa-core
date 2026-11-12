#!/bin/bash
#
# =============================================================================
# dist-profile-test.sh — the deployment-profile branches, exercised.
# =============================================================================
# `bin/build/build-dist.sh` decides the artifact's identity in four ways, and
# three of them end in a GREEN build, which is what makes them easy to break in
# a refactor without noticing:
#
#   1. kernel-only tree (no Settings module) → skip, kernel defaults;
#   2. `--no-profile`                        → skip, kernel defaults;
#   3. Settings installed (with its View → Layout closure) + a BROKEN profile
#      → fail the build, and a valid one
#                                              applies (the positive control
#                                              that proves #3 fails because of
#                                              the profile, not the install).
#   4. the whole catalogue installed         → the artifact boots and the HTTP
#                                              smoke test passes. Cases 1–3
#                                              ask what identity the build
#                                              claims; this one asks whether
#                                              the tree a deployment gets can
#                                              serve at all.
#   5. `--with-catalogue`                    → the artifact ships `modules/`, so
#                                              the CLI it carries can install
#                                              from the catalogue. The default
#                                              artifact cannot, by design.
#
# Every case clones HEAD, so the test cannot borrow a module, a `config.db` or a
# `vendor/` from the developer's checkout. `--skip-smoke` keeps cases 1–3 about
# the profile: the HTTP smoke test is `dist-check.sh`'s job, and running it
# three more times would only make a red test slower to read. Case 4 runs the
# smoke deliberately — it is the one that installs everything. Case 5 reuses
# case 4's clone and its installed catalogue: it asks only what the flag put in
# the artifact, so it needs neither a build of the catalogue nor a smoke test.
#
# Usage: bin/build/dist-profile-test.sh   (CI runs `make dist-profile-test`)
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/nativa-dist-profile.XXXXXX")"

cleanup() {
    rm -rf "$WORK"
}
trap cleanup EXIT

fail() {
    echo "dist-profile-test: $1" >&2
    exit 1
}

pass() {
    echo "ok   $*"
}

clone_into() {
    git clone --quiet "$ROOT" "$1"
}

# Build one clone; the log stays beside it so a failed assertion can print it.
# `|| code=$?` rather than toggling errexit: a `set -e` inside a function is
# global, so it would fire on the `return` of an expected non-zero build.
build() {
    local tree="$1"
    shift

    local code=0
    "$tree/bin/build/build-dist.sh" --skip-assets --skip-smoke "$@" > "$tree/build.log" 2>&1 || code=$?

    return "$code"
}

expect() {
    local log="$1" pattern="$2" case_name="$3"

    if ! grep -q -- "$pattern" "$log"; then
        cat "$log" >&2
        fail "$case_name: log does not contain '$pattern'"
    fi
}

# --- 1. Kernel-only clone: the module is absent, so the profile is skipped ----
kernel="$WORK/kernel-only"
clone_into "$kernel"

if [ -d "$kernel/src/Modules/Settings" ]; then
    fail "the clone already carries src/Modules/Settings — that is local state, not a clean clone"
fi

build "$kernel" && kernel_code=0 || kernel_code=$?

if [ "$kernel_code" -ne 0 ]; then
    cat "$kernel/build.log" >&2
    fail "a kernel-only clone must build (exit 0), it exited $kernel_code"
fi

expect "$kernel/build.log" \
    'Skipping the deployment profile: the Settings module is not installed' \
    'kernel-only'
expect "$kernel/build.log" \
    'identity:   kernel defaults (no Settings module to apply config/deploy.php)' \
    'kernel-only'
pass "kernel-only clone → skipped, kernel defaults"

# --- 2. The same clone with --no-profile: skipped for the explicit reason ------
build "$kernel" --no-profile && no_profile_code=0 || no_profile_code=$?

if [ "$no_profile_code" -ne 0 ]; then
    cat "$kernel/build.log" >&2
    fail "--no-profile must still build (exit 0), it exited $no_profile_code"
fi

expect "$kernel/build.log" \
    'Skipping the deployment profile (--no-profile)' \
    '--no-profile'
expect "$kernel/build.log" \
    'identity:   kernel defaults (theme=kernel, site_name=Nativa PHP Core)' \
    '--no-profile'
pass "--no-profile → skipped, kernel defaults"

# --- 3. Settings installed: a valid profile applies, a broken one fails -------
settings="$WORK/with-settings"
clone_into "$settings"

# The closure, in order: Settings imports Layout (theme settings, registry) and
# Layout imports View (the renderer). `module:add` installs one module and
# refuses an incomplete closure by name, so the gate installs the dependencies
# first instead of assuming Settings stands alone.
for module in View Layout Settings; do
    if ! php "$settings/bin/cms" module:add "$module" >> "$settings/install.log" 2>&1; then
        cat "$settings/install.log" >&2
        fail "module:add $module failed in the clone — the case below would be vacuous"
    fi
done

if [ ! -d "$settings/src/Modules/Settings" ]; then
    fail "the closure exited 0 without installing Settings"
fi

build "$settings" && valid_code=0 || valid_code=$?

if [ "$valid_code" -ne 0 ]; then
    cat "$settings/build.log" >&2
    fail "Settings installed + the committed profile must build (exit 0), it exited $valid_code"
fi

expect "$settings/build.log" 'Applying the deployment profile' 'valid profile'
expect "$settings/build.log" \
    'identity:   config/deploy.php applied' \
    'valid profile'
pass "Settings installed → profile applied"

# The vocabulary is the migrator's seed, so an unknown key is the cheapest
# realistic mistake a profile can carry: it must fail the build, not ship.
printf "<?php\n\nreturn ['theme_typo' => 'boson'];\n" > "$settings/config/deploy.php"

broken_code=0
build "$settings" && broken_code=0 || broken_code=$?

if [ "$broken_code" -eq 0 ]; then
    cat "$settings/build.log" >&2
    fail "a profile naming an unknown setting must fail the build, it exited 0"
fi

expect "$settings/build.log" \
    'names settings this schema does not have' \
    'broken profile'
expect "$settings/build.log" \
    'build-dist: the deployment profile could not be applied' \
    'broken profile'
pass "broken profile → build fails (exit $broken_code)"

# --- 4. The whole catalogue installed: the artifact boots and serves ----------
# The cases above hold the smoke test out of the way. This one is the opposite
# question, asked once and with the smoke left ON: install every catalogued
# module and check that the artifact a deployment gets boots, applies the
# committed profile and answers the kernel's surfaces.
#
# `install-catalogue.sh` is invoked rather than reimplemented. Its retry loop IS
# the topological sort (`module:add` refuses an incomplete closure and names the
# missing entries), so this case cannot drift from the order `make
# modules-install` uses — a hand-written order here would be a second copy of
# the dependency graph, and the one that goes stale.
catalogue="$WORK/with-catalogue"
clone_into "$catalogue"

if ! "$catalogue/bin/build/install-catalogue.sh" > "$catalogue/install.log" 2>&1; then
    cat "$catalogue/install.log" >&2
    fail "install-catalogue.sh failed in a clean clone — the smoke case below would be vacuous"
fi

# The count is the installer's own expectation (catalogue + KernelTheme), so an
# install that silently skipped an entry cannot pass this case as "the whole
# catalogue".
expect "$catalogue/install.log" 'ok  7 modules installed' 'catalogue'

for slice in src/Modules/Themes/Nativa src/Modules/Layout src/Modules/Settings; do
    if [ ! -d "$catalogue/$slice" ]; then
        fail "the catalogue install exited 0 without $slice"
    fi
done

# A port of its own: case 4 runs while nothing else does, but the default the
# smoke helper uses is `dist-check.sh`'s, and two builds answering on one port
# would make a green run depend on scheduling.
smoke_code=0
SMOKE_PORT="${SMOKE_PORT:-8138}" \
    "$catalogue/bin/build/build-dist.sh" --skip-assets > "$catalogue/build.log" 2>&1 || smoke_code=$?

if [ "$smoke_code" -ne 0 ]; then
    cat "$catalogue/build.log" >&2
    fail "the full catalogue must build and pass its smoke test (exit 0), it exited $smoke_code"
fi

expect "$catalogue/build.log" 'Applying the deployment profile' 'catalogue'
expect "$catalogue/build.log" \
    'identity:   config/deploy.php applied' \
    'catalogue'
expect "$catalogue/build.log" 'Smoke-testing the artifact' 'catalogue'

expect "$catalogue/build.log" 'ok  / → 200' 'catalogue'
expect "$catalogue/build.log" 'ok  /sitemap.xml → 200' 'catalogue'
expect "$catalogue/build.log" 'ok  /robots.txt → 200' 'catalogue'
expect "$catalogue/build.log" 'error log is clean' 'catalogue'
pass "whole catalogue installed → build, profile and smoke all pass"

# --- 5. --with-catalogue: the artifact ships the catalogue it installs from ---
# The default artifact carries `Modules` once (`src/Modules/`) and says so; the
# flag is what makes `module:add` possible on a DEPLOYED tree. Nothing else in
# this gate reads that branch of build-dist.sh, so it is exercised here once,
# on the clone that already holds the whole catalogue.
build "$catalogue" --with-catalogue && shipped_code=0 || shipped_code=$?

if [ "$shipped_code" -ne 0 ]; then
    cat "$catalogue/build.log" >&2
    fail "--with-catalogue must build (exit 0), it exited $shipped_code"
fi

expect "$catalogue/build.log" \
    'Catalogue in the artifact (module:add works here)' \
    '--with-catalogue'
expect "$catalogue/build.log" \
    'catalogue INCLUDED (--with-catalogue)' \
    '--with-catalogue'
expect "$catalogue/build.log" 'byte-identical' '--with-catalogue'

if [ ! -d "$catalogue/dist/modules" ]; then
    cat "$catalogue/build.log" >&2
    fail "--with-catalogue exited 0 without dist/modules — the artifact cannot install what it does not ship"
fi

# The shipped catalogue is only worth shipping if the artifact's OWN CLI can
# read it: that is the capability the flag claims.
if ! php "$catalogue/dist/bin/cms" module:list 2>&1 | grep -q '^Catalogued ('; then
    fail "the --with-catalogue artifact's own CLI does not list a catalogue it ships"
fi

pass "--with-catalogue → catalogue shipped, the artifact's CLI lists it"

echo "==> OK — every profile branch and the catalogue's artifact behave as documented"
