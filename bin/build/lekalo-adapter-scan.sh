#!/usr/bin/env bash
# =============================================================================
# The scan rows this kernel owns.
#
# `lekalo adapter test` proves the declared surface is coherent, but its suite
# has no scan row for a 0.3.2 target: a scan that answered with nothing, or
# with the wrong entries, would still report `verdict pass`. This step closes
# that hole. It drives a real exchange — the adapter process, a request
# envelope on stdin, a project view on disk — and asserts the projection of
# entries exactly, plus the skips a `partial` scan has to show, plus the typed
# refusal the closed wire requires when a path cannot be represented, plus
# determinism over identical bytes.
#
# Rows are named and printed like the conformance suite's, so a failure here
# reads as a battery row rather than a second vocabulary.
#
# Usage: bin/build/lekalo-adapter-scan.sh      (PHP=<binary>, ROOT=<tree> to
#                                              prove the rows on a copy)
# Exit: 0 every row passed, 1 a row failed, 2 environment unusable.
# =============================================================================
set -uo pipefail

ROOT="${ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
ADAPTER="$ROOT/adapters/php-nativa/adapter.php"
PHP="${PHP:-php}"

if [ ! -f "$ADAPTER" ]; then
    echo "missing adapter: $ADAPTER" >&2
    exit 2
fi
if ! command -v "$PHP" >/dev/null 2>&1; then
    echo "missing php runtime: $PHP" >&2
    exit 2
fi

# A request id is `req-` and 64 lowercase hex characters. Deriving it keeps the
# envelope honest without pinning a literal in tracked code.
REQUEST_ID="req-$(printf '%s' 'nativa adapter scan suite' | sha256sum | cut -d' ' -f1)"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
failures=0

row_pass() { printf 'pass  %s\n' "$1"; }
row_fail() {
    printf 'fail  %s\n' "$1"
    printf '        %s\n' "$2"
    failures=$((failures + 1))
}

cat > "$work/assert.php" <<'PHP'
<?php
// assert_entries <response.json> <expected.txt>
[, $path, $expectedPath] = $argv;
$response = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$status = $response['status'] ?? null;
if ('ok' !== $status) {
    $code = $response['error']['code'] ?? '-';
    fwrite(STDERR, "status=$status code=$code");
    exit(1);
}
$expected = file($expectedPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$actual = [];
foreach ($response['result']['entries'] as $entry) {
    $actual[] = $entry['kind'] . '|' . ($entry['detail'] ?? '') . '|' . $entry['path'];
}
if ($actual !== $expected) {
    fwrite(STDERR, "entries differ\n  expected:\n    " . implode("\n    ", $expected)
        . "\n  actual:\n    " . implode("\n    ", $actual));
    exit(1);
}
if (false !== ($response['result']['truncated'] ?? null)) {
    fwrite(STDERR, 'truncated is not false');
    exit(1);
}
exit(0);
PHP

run_scan() { # run_scan <view-dir> <response-file>
    ( cd "$1" && printf '{"protocol":"lekalo.target/v1","protocol_version":"0.3.2","operation":"scan","request_id":"%s","project_root":"."}' "$REQUEST_ID" \
        | "$PHP" "$ADAPTER" ) > "$2" 2> "$2.stderr"
}

# --- the view the scan must project exactly --------------------------------
mkdir -p "$work/view/src/sub"
cat > "$work/view/src/widget.php" <<'PHP'
<?php
declare(strict_types=1);

namespace App\Demo;

interface Renderable { public function render(): string; }
trait Labelled { public function label(): string { return 'label'; } }
enum Kind: string { case Widget = 'widget'; }

final class Widget implements Renderable
{
    use Labelled;

    public const DEFAULTS = ['size' => 1];

    public function render(): string { return 'widget'; }
}

function makeWidget(): Widget { return new Widget(); }
PHP
cat > "$work/view/src/sub/util.php" <<'PHP'
<?php
namespace App\Demo\Sub;

final class Helper { public function help(): void {} }
PHP
cat > "$work/expected.txt" <<'EXPECTED'
class|App\Demo\Sub\Helper|src/sub/util.php
method|App\Demo\Sub\Helper::help|src/sub/util.php
enum|App\Demo\Kind|src/widget.php
trait|App\Demo\Labelled|src/widget.php
method|App\Demo\Labelled::label|src/widget.php
interface|App\Demo\Renderable|src/widget.php
method|App\Demo\Renderable::render|src/widget.php
class|App\Demo\Widget|src/widget.php
class-constant|App\Demo\Widget::DEFAULTS|src/widget.php
method|App\Demo\Widget::render|src/widget.php
function|App\Demo\makeWidget|src/widget.php
EXPECTED

echo "adapter scan: adapters/php-nativa/adapter.php"
run_scan "$work/view" "$work/first.json"
if detail="$("$PHP" "$work/assert.php" "$work/first.json" "$work/expected.txt" 2>&1)"; then
    row_pass "scan.entries"
else
    row_fail "scan.entries" "$detail"
fi

# --- a path the closed wire cannot carry must refuse, not truncate ---------
mkdir -p "$work/view-upper/src/Upper"
cp "$work/view/src/widget.php" "$work/view-upper/src/Upper/Widget.php"
run_scan "$work/view-upper" "$work/upper.json"
upper="$("$PHP" -r '
    $response = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    printf("%s %s", $response["status"] ?? "-", $response["error"]["code"] ?? "-");
' "$work/upper.json" 2>&1)"
if [ "$upper" = "error nativa.scan-path-grammar" ]; then
    row_pass "scan.refusal-path-grammar"
else
    row_fail "scan.refusal-path-grammar" "expected 'error nativa.scan-path-grammar', saw '$upper'"
fi

# --- a partial claim must show the files it did not read -------------------
mkdir -p "$work/view-partial/src/vendor"
cp "$work/view/src/widget.php" "$work/view-partial/src/widget.php"
cp "$work/view/src/widget.php" "$work/view-partial/src/vendor/lib.php"
cat > "$work/outside.php" <<'PHP'
<?php
namespace App\Outside;

final class Outside { public function o(): void {} }
PHP
ln -s "$work/outside.php" "$work/view-partial/src/linked.php"
cat > "$work/expected-partial.txt" <<'EXPECTED'
skipped|nativa.scan-symlink|src/linked.php
skipped|nativa.scan-vendor|src/vendor/lib.php
enum|App\Demo\Kind|src/widget.php
trait|App\Demo\Labelled|src/widget.php
method|App\Demo\Labelled::label|src/widget.php
interface|App\Demo\Renderable|src/widget.php
method|App\Demo\Renderable::render|src/widget.php
class|App\Demo\Widget|src/widget.php
class-constant|App\Demo\Widget::DEFAULTS|src/widget.php
method|App\Demo\Widget::render|src/widget.php
function|App\Demo\makeWidget|src/widget.php
EXPECTED

run_scan "$work/view-partial" "$work/partial.json"
if detail="$("$PHP" "$work/assert.php" "$work/partial.json" "$work/expected-partial.txt" 2>&1)"; then
    row_pass "scan.partial-skipped"
else
    row_fail "scan.partial-skipped" "$detail"
fi

# --- a path the grammar cannot carry is named in the refusal ---------------
mkdir -p "$work/view-grammar/src/Upper"
cp "$work/view/src/widget.php" "$work/view-grammar/src/Upper/Widget.php"
cp "$work/view/src/sub/util.php" "$work/view-grammar/src/Upper/Helper.php"
run_scan "$work/view-grammar" "$work/grammar.json"
grammar="$("$PHP" -r '
    $response = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    printf("%s\n%s", $response["error"]["code"] ?? "-", $response["error"]["message"] ?? "-");
' "$work/grammar.json" 2>&1)"
case "$grammar" in
    "nativa.scan-path-grammar"*"2 project path(s)"*"src/Upper/Helper.php"*)
        row_pass "scan.partial-refusal-names-file" ;;
    *)
        row_fail "scan.partial-refusal-names-file" \
            "the refusal must count the paths it could not name and name the first, saw: $(printf '%s' "$grammar" | tr '\n' ' ')" ;;
esac

# --- identical bytes must produce identical bytes --------------------------
run_scan "$work/view" "$work/second.json"
if cmp -s "$work/first.json" "$work/second.json"; then
    row_pass "scan.determinism"
else
    row_fail "scan.determinism" "two runs over the same view produced different responses"
fi

if [ "$failures" -ne 0 ]; then
    printf 'verdict fail (%d row(s) failed)\n' "$failures"
    exit 1
fi
printf 'verdict pass (5 scan rows)\n'
exit 0
