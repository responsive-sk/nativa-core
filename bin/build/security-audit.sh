#!/bin/bash
#
# =============================================================================
# security-audit.sh — the two checks the vendored aif script cannot hold.
# =============================================================================
# `.agents/skills/aif-security-checklist/scripts/audit.sh` is gitignored,
# hash-managed tooling installed by `$aif` and recorded in `.ai-factory.json`.
# Its two defects were fixed locally on 2026-10-16 (PR #3, task 6 of
# `.ai-factory/plans/fix-audit-remediation.md`, recorded in
# `docs/kernel-status/05-security-findings-7-to-9.md`) and the fix did not
# survive: measured 2026-10-24, `((ISSUES++))` was back at four sites (so
# `set -e` killed the script after its first section, exit 1, 25 lines of
# output) and the secrets pattern was the generic one again (20 benign
# `$token = $_SESSION[...]` assignment lines reported as credentials).
#
# A fix that a re-install erases is not a gate. These two rules live HERE, in
# tracked code, and CI runs this script on every push:
#
#   1. `.env` may never be tracked. `.env.example` may — it is the template.
#   2. A secret-shaped assignment is a finding only when the value is a QUOTED
#      LITERAL of 8+ characters. `$token = $_SESSION[self::SESSION_KEY]`,
#      `'password' => $row['password']` and `token: {{ csrf }}` are code; a
#      real `sk_live_…` key beside a credential name is not.
#
# Deliberately NOT here: the vendored script's JS-shaped checks (`npm audit`,
# `console.log` in `src/`, `error.message`). This tree's `src/` is PHP, its
# frontend lives in `Frontend/packages` with its own build gate, and porting
# checks that cannot fire would make the output look thorough and mean less.
#
# Usage:
#   bin/build/security-audit.sh [--root <dir>]
#
#     --root <dir>  Audit that git work tree instead of this checkout. It
#                   exists so the gate can be proven on a synthetic repository
#                   (one real leak, one harmless assignment) rather than only
#                   observed to pass here.
#
# Exit: 0 clean, 1 findings, 2 usage or environment.
# Dependencies: bash, git, grep, sed, coreutils — the same set `bin/cms` and
# `extract-kernel.sh` keep, so the gate runs where `vendor/` may not exist.
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

usage() {
    awk 'NR>1 && /^set -euo pipefail$/{exit} NR>1{print}' "$0" | sed 's/^# \?//'
}

fail() {
    echo "security-audit: $*" >&2
    exit 2
}

while [ $# -gt 0 ]; do
    case "$1" in
        --root)
            [ $# -ge 2 ] || fail "--root needs a directory"
            ROOT="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            fail "unknown argument: $1 (see --help)"
            ;;
    esac
done

ROOT="$(cd "$ROOT" 2>/dev/null && pwd)" || fail "cannot enter --root directory"
git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1 || fail "$ROOT is not a git work tree"

ISSUES=0

# Tracked files only. A secret in a file nobody commits is not this gate's
# subject — the repository is, and `git ls-files` is the same set a clone gets.
tracked() {
    git -C "$ROOT" ls-files
}

report() {
    ISSUES=$((ISSUES + 1))
    printf 'FAIL  %s\n' "$1"
    if [ -n "${2:-}" ]; then
        printf '%s\n' "$2" | sed 's/^/      /'
    fi
}

echo "== 1. .env is never tracked =="
tracked_env="$(tracked | grep -E '(^|/)\.env($|\.)' | grep -vE '\.env\.example$' || true)"

if [ -n "$tracked_env" ]; then
    report "a .env file is tracked (it carries real values; the template is .env.example)" "$tracked_env"
else
    echo "ok    no .env file is tracked"
fi

echo
echo "== 2. no secret-shaped literals in tracked code =="

# Details, each one measured on a synthetic repository before the line was
# trusted:
#   `-i`                     — `$apiKey` is the spelling this tree uses, and a
#                              case-sensitive pattern reported the leak clean.
#   `[\"']?` before the `=>`  — a PHP array key is quoted, so the shape is
#                              `"client_secret" => "…"` and a pattern expecting
#                              the operator straight after the name misses it.
#   `(=>|[:=])` not `[:=]`   — with only `=`, `=>` still matches the `=` but the
#                              value never follows it directly.
#   `[^\"'$|]` first, and `|` excluded from the run, — the value cannot start
#                              with `$` (that is `"$(token)"`, a call, not a
#                              literal) and cannot contain `|` (that is
#                              `'required|min:12|confirmed'`, a RULE, not a
#                              credential). Both were findings on this tree until
#                              the classes said so.
SECRET_RE="(password|passwd|passphrase|secret|api[_-]?key|apikey|token|private[_-]?key|access[_-]?key|client[_-]?secret)[\"']?[[:space:]]*(=>|[:=])[[:space:]]*[\"'][^\"'$|][^\"'|]{7,}[\"']"
FILE_RE='\.(php|phtml|js|mjs|cjs|ts|tsx|jsx|json|ya?ml|sh|ini|conf)$'
# Tests and fixtures name credentials on purpose; docs quote them; the article
# corpus is prose. A hit in any of those is noise that trains a reader to
# ignore the gate. `(^|/)(tests|fixtures)/` and not `^tests/`: the catalogue's
# own tests live under `modules/<Name>/tests/`, and the first run of this gate
# proved the difference — three findings, all three in a test fixture.
#
# `^\.ai-factory/extensions/` is vendored, like `.agents/` and `vendor/`: that
# tree ships an upstream extension beside the project config (782 files, its own
# `validate.yml`, versioned with the project rather than re-fetched per machine)
# and its fixtures name synthetic credentials under several naming conventions
# at once — `scripts/*.test.mjs`, `scripts/*-smoke.mjs`,
# `rtk-ai-tester-ab/scenarios-evidence.mjs`. Measured, not guessed: the run that
# vendored that tree reported 18 findings, every one a value like
# 'must-not-pass-through' or 'AB-SYNTH-TOKEN-7f3a91c2', and annotating them
# would be the kind of fix a re-vendor erases. Skipped as a tree, so no rule
# here has to follow a vendor's file naming. The rest of `.ai-factory/` — the
# project config above all — stays in scope.
SKIP_RE='(^|/)(tests|fixtures)/|^docs/|^storage/blog/|^\.agents/|^\.ai-factory/extensions/|^vendor/|node_modules/'

hits=""
scanned=0

while IFS= read -r file; do
    [ -f "$ROOT/$file" ] || continue
    scanned=$((scanned + 1))
    found="$(grep -nEi -- "$SECRET_RE" "$ROOT/$file" 2>/dev/null | grep -v 'audit-ok' || true)"
    [ -n "$found" ] || continue
    while IFS= read -r line; do
        hits+="$file:$line"$'\n'
    done <<< "$found"
done < <(tracked | grep -E "$FILE_RE" | grep -vE "$SKIP_RE" || true)

if [ -n "$hits" ]; then
    count="$(printf '%s' "$hits" | grep -c . || true)"
    report "$count secret-shaped literal(s) — a quoted value of 8+ characters next to a credential name" \
        "$(printf '%s' "$hits" | sed -n '1,20p')"
    echo "      (one line per finding, first 20 shown; annotate a deliberate example with 'audit-ok')"
else
    echo "ok    no secret-shaped literal in $scanned tracked files"
fi

echo
if [ "$ISSUES" -gt 0 ]; then
    echo "security-audit: $ISSUES check(s) failed"
    exit 1
fi

echo "security-audit: clean"
