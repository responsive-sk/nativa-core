#!/bin/bash
#
# =============================================================================
# dist-profile-test.sh — the three deployment-profile branches, exercised.
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
#
# Every case clones HEAD, so the test cannot borrow a module, a `config.db` or a
# `vendor/` from the developer's checkout. `--skip-smoke` keeps it about the
# profile: the HTTP smoke test is `dist-check.sh`'s job, and running it four
# times here would only make a red test slower to read.
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

echo "==> OK — all three profile branches behave as documented"
