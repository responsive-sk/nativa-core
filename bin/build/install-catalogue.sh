#!/usr/bin/env bash
# install-catalogue.sh — install every catalogued module into src/Modules/.
#
# `bin/cms module:add` refuses an incomplete CLOSURE and copies nothing, so
# retrying the set until a pass copies something IS the topological sort: a
# refusal that names missing dependencies is the graph talking, not a failure.
# A pass that copies nothing while modules are still pending is a real cycle (or
# a catalogue entry that cannot be installed at all), and it fails HERE rather
# than leaving a half-installed tree for a test suite to explain. Measured on a
# fresh clone before Boot 26: `Cardboard → Security → Outbox → Cardboard` made
# that pass impossible, and the message below is what said so.
#
# `Scratch` is skipped: it is the lifecycle FIXTURE (ModuleLifecycleTest installs
# and removes it in-process), and a tree where it is already installed makes that
# test fail on its own "already installed" refusal. It is a leaf with one route,
# so nothing else needs it present.
#
# The expectation is derived from the catalogue itself (+ KernelTheme, the one
# committed slice under src/Modules/ that is the kernel's own default
# presentation), so adding a module to `modules/` cannot leave this check
# silently passing on a smaller tree.
#
# Usage: bin/build/install-catalogue.sh      (from anywhere; it finds the root)
set -euo pipefail

cd "$(dirname "$0")/../.."

# The list comes from `module:list`, not from `modules/*/`: the catalogue has two
# shapes now (modules/<Name>/ and modules/Themes/<Name>/), and a glob would
# either count `Themes` as a module or silently skip the four themes inside it.
# One enumerator, the one the CLI itself uses.
pending=""
catalogue=0
while IFS= read -r name; do
    [ -n "$name" ] || continue
    [ "$name" = "Scratch" ] && continue
    pending="$pending $name"
    catalogue=$((catalogue + 1))
done < <(php bin/cms module:list | sed -n 's/^Catalogued ([0-9]*): //p' | tr ',' '\n' | sed 's/^ *//;s/ *$//')

expected=$((catalogue + 1)) # + KernelTheme
echo "catalogue to install:$pending (${catalogue} entries, ${expected} expected installed)"

# Installed membership comes from the CLI: a module may live under a group
# (`src/Modules/Themes/<Name>`), so `-d src/Modules/<Name>` is not the question.
installed_names=" "$(php bin/cms module:list | sed -n 's/^Installed ([0-9]*): //p' | tr ',' ' ')" "

for round in $(seq 1 20); do
    [ -n "${pending// /}" ] || break

    still=""
    progressed=0

    for name in $pending; do
        # Already installed: skip, so the script is re-runnable on a workstation
        # (`make modules-install`). It does NOT weaken the check — the count at
        # the end still demands the whole catalogue, so a tree that cannot
        # install an entry still fails here.
        case "$installed_names" in
            *" $name "*)
                echo "round $round: ok  $name already installed"
                progressed=1
                continue
                ;;
        esac

        if out="$(php bin/cms module:add "$name" 2>&1)"; then
            echo "round $round: ok  installed $name"
            progressed=1
        elif printf '%s' "$out" | grep -q 'missing dependencies'; then
            still="$still $name"
        else
            echo "::error::module:add $name failed: $out"
            exit 1
        fi
    done

    pending="$still"

    if [ -n "${pending// /}" ] && [ "$progressed" -eq 0 ]; then
        echo "::error::no progress in round $round — pending:$pending (a cycle in the closure graph: see docs/kernel-status/07-ci-modules-gate.md)"
        exit 1
    fi
done

[ -z "${pending// /}" ] || { echo "::error::still pending:$pending"; exit 1; }

php bin/cms module:list

installed="$(php bin/cms module:list | sed -n 's/^Installed (\([0-9]*\)).*/\1/p')"
[ "$installed" -ge "$expected" ] || {
    echo "::error::only $installed modules installed, expected $expected"
    exit 1
}

echo "ok  $installed modules installed (catalogue ${catalogue} + KernelTheme)"
