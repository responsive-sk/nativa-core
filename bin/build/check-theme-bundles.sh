#!/bin/bash
#
# =============================================================================
# check-theme-bundles.sh — every kernel theme has a bundle, or this says which.
# =============================================================================
# measured 2026-10-21 (docs/themes.md, Fáza 0.3): removing a theme's
# `Frontend/packages/<theme>` does NOT fail the build. `pnpm install` exits 0,
# `pnpm build` exits 0, and it simply builds one bundle fewer — `public/dist/
# <theme>/` never appears. `make assets` is green, the theme keeps rendering
# from the module's critical CSS, and for `erik`, `griffe` and `jednotka` that
# means a page without its full stylesheet.
#
# A green build is therefore not evidence that every theme was built. This
# script is that evidence, and it names the missing package so the fix is not a
# guess:
#
#   theme=boson package=yes bundle=public/dist/boson/manifest.json entries=1
#   FAIL theme=griffe — bundle missing and Frontend/packages/griffe is gone:
#        the module's criticalCss() is the fallback and the page loses its
#        full stylesheet. Restore the package or accept the degradation in
#        docs/themes.md.
#
# What it asserts beyond "the directory exists": the manifest parses, carries at
# least one entry, and every `file` and `css` path it names is on disk. A
# manifest that survives a clean while its assets do not is the same broken page
# with an extra step. Entry NAMES are deliberately not asserted here — `jsKey()`
# is the theme's own answer (`jednotka` returns `main`), and the ownership tests
# already pin `name()`/`bundle()`/`jsKey()` against the class that declares them;
# duplicating that mapping in bash would create a third place to keep in sync.
#
# Runs after `pnpm build` (Makefile `assets`, CI `assets` job). Needs jq, the
# same dependency the workspace gate already takes; a missing jq is a loud
# failure, never a silent pass.
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# Module name → Vite package / bundle directory. The list is read from the
# catalogue, not kept here: this tree carries six themes and the export carries
# one, and a hand-maintained constant made a third list (after the catalogue and
# `sync-themes.sh`'s allow-list) that could silently disagree with both.
THEMES=()
while IFS= read -r theme_dir; do
    THEMES+=("$(basename "$theme_dir")")
done < <(find "$ROOT/modules/Themes" -mindepth 1 -maxdepth 1 -type d | LC_ALL=C sort)

[ ${#THEMES[@]} -gt 0 ] || fail "no theme module under modules/Themes; the bundle check would pass on an empty list"

fail() {
    echo "check-theme-bundles: $*" >&2
    exit 1
}

command -v jq >/dev/null 2>&1 || fail "jq is required to read the manifests (apt install jq)"

missing=0
checked=0

for module in "${THEMES[@]}"; do
    key="$(printf '%s' "$module" | tr '[:upper:]' '[:lower:]')"
    bundle="public/dist/$key"
    manifest="$ROOT/$bundle/manifest.json"
    package="$ROOT/Frontend/packages/$key"
    if [ -d "$package" ]; then has_package="yes"; else has_package="no"; fi

    checked=$((checked + 1))

    if [ ! -f "$manifest" ]; then
        missing=$((missing + 1))
        echo "theme=$key package=$has_package bundle=$bundle/manifest.json entries=0"
        if [ "$has_package" = "yes" ]; then
            echo "FAIL theme=$key — $bundle/manifest.json is missing although Frontend/packages/$key exists; the build produced nothing for it." >&2
        else
            echo "FAIL theme=$key — bundle missing and Frontend/packages/$key is gone: the module's criticalCss() is the fallback, so the page loses its full stylesheet." >&2
        fi
        continue
    fi

    if ! jq -e 'type == "object" and length > 0' "$manifest" >/dev/null 2>&1; then
        missing=$((missing + 1))
        echo "theme=$key package=$has_package bundle=$bundle/manifest.json entries=0"
        echo "FAIL theme=$key — $bundle/manifest.json is empty or not a JSON object; the PHP side would read nothing." >&2
        continue
    fi

    entries="$(jq -r 'length' "$manifest")"
    echo "theme=$key package=$has_package bundle=$bundle/manifest.json entries=$entries"

    # Every path the manifest names, resolved against the bundle directory. The
    # build writes these itself; a missing one means the directory was pruned,
    # copied partially, or left behind by an older build with a cleared assets/.
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        if [ ! -f "$ROOT/$bundle/$rel" ]; then
            missing=$((missing + 1))
            echo "FAIL theme=$key — the manifest points at $bundle/$rel, which does not exist." >&2
        fi
    done < <(jq -r '.[] | .file, ((.css // [])[])' "$manifest" | LC_ALL=C sort -u)

    if [ "$(jq -r '[.[] | select(.isEntry == true)] | length' "$manifest")" = "0" ]; then
        missing=$((missing + 1))
        echo "FAIL theme=$key — the manifest carries no entry (isEntry), so nothing would be loaded." >&2
    fi
done

if [ "$missing" -gt 0 ]; then
    echo "check-theme-bundles: themes=$checked missing=$missing" >&2
    exit 1
fi

echo "check-theme-bundles: themes=$checked missing=0 — every theme has its bundle and the assets its manifest names."
