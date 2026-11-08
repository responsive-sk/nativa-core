#!/bin/bash
#
# =============================================================================
# build-prod.sh — production build: checks, theme bundles, then dist/.
# =============================================================================
# Thin wrapper over the existing pieces, so there is one path per step:
#
#   1. PHP tests            composer test (vendor/bin/phpunit)
#   2. Kernel guard         bin/build/extract-kernel.sh --check
#   3. Frontend typecheck   pnpm typecheck (Frontend/packages)
#   4. Theme bundles        make assets  (pnpm build → public/dist/<theme>)
#   5. Artifact             bin/build/build-dist.sh --skip-assets --to dist
#
# Any failing step stops the build (set -e); nothing is skipped silently.
#
# Usage:
#   bin/build/build-prod.sh              # all steps, artifact in dist/
#   bin/build/build-prod.sh --skip-checks  # steps 4-5 only (fast re-assemble)
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

SKIP_CHECKS=0
for arg in "$@"; do
    case "$arg" in
        --skip-checks) SKIP_CHECKS=1 ;;
        *) echo "build-prod: unknown option: $arg" >&2; exit 2 ;;
    esac
done

if [ "$SKIP_CHECKS" -eq 0 ]; then
    echo "==> [1/5] PHP tests"
    vendor/bin/phpunit

    echo "==> [2/5] Kernel guard"
    bin/build/extract-kernel.sh --check

    echo "==> [3/5] Frontend typecheck"
    (cd Frontend/packages && pnpm typecheck)
fi

echo "==> [4/5] Theme bundles (make assets)"
make assets

echo "==> [5/5] Artifact → dist/"
bin/build/build-dist.sh --skip-assets --to "$ROOT/dist"

echo "build-prod: OK → $ROOT/dist"
