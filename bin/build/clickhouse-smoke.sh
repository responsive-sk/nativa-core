#!/usr/bin/env bash
# clickhouse-smoke.sh — the bridge against a REAL ClickHouse server, as a gate.
#
# Why it exists: every command test drives `ClickHouseTransport`, so nothing in
# `composer test` proves that a server accepts the DDL, that `SHOW CREATE
# DATABASE` reads back the path the plan wrote, or that the engine refuses a
# file outside its `user_files`. Those are facts about ClickHouse, not about
# this tree — measured once in Boot 46, and this is that measurement made
# re-runnable so a change that breaks the DDL fails a command instead of a
# reviewer's memory.
#
# What it walks, in order: the dry run (no server contacted), attach, a read
# through the inferred table, a read while a live connection holds an
# uncheckpointed WAL, `--checkpoint` folding that WAL in and truncating it, a
# write through ClickHouse during that same WAL, a write from ClickHouse while
# a kernel transaction holds the write lock (refused by the engine, and the
# kernel's own row still commits afterwards), the status read-back, the
# `user_files` refusal, the `--typed` table and its warning, and the write gate
# (refused without `--write`, accepted with it, and the row is really there
# afterwards).
#
# It never touches `storage/data/`: the fixtures are a temp directory, every
# `DB_*` the run needs points into it, and the server is a throwaway one in
# `mktemp -d` unless `CLICKHOUSE_URL` names an existing server.
#
# Usage:
#   bin/build/clickhouse-smoke.sh                    # starts CLICKHOUSE_BIN or `clickhouse`
#   CLICKHOUSE_BIN=/opt/clickhouse bin/build/clickhouse-smoke.sh
#   CLICKHOUSE_URL=http://127.0.0.1:8123 \
#     CLICKHOUSE_USER_FILES=/var/lib/clickhouse/user_files \
#     bin/build/clickhouse-smoke.sh                  # an existing server
#
# With `CLICKHOUSE_URL` set, `CLICKHOUSE_USER_FILES` must name a directory on
# that server's filesystem this shell can write to (a mount), because the
# fixture has to be inside `user_files` — the engine answers PATH_ACCESS_DENIED
# for anything else, which is itself one of the checks below.
#
# Needs the Clickhouse module installed: `php bin/cms module:add Clickhouse`.
set -euo pipefail

cd "$(dirname "$0")/../.."

PORT="${SMOKE_CLICKHOUSE_PORT:-18123}"
TMP="$(mktemp -d)"
FAILED=0
CHECKS_OK=0
CHECKS_FAILED=0
SERVER_PID=''
SERVER_VERSION=''
EXTERNAL=0

ok() {
    CHECKS_OK=$((CHECKS_OK + 1))
    printf 'ok    %s\n' "$1"
}
bad() {
    CHECKS_FAILED=$((CHECKS_FAILED + 1))
    printf '::error::%s\n' "$1"
    FAILED=1
}

# The last command's output and status, so a refusal can be asserted as a
# refusal instead of `set -e` aborting the run on it.
LAST_OUT=''
LAST_CODE=0
run() {
    set +e
    LAST_OUT=$("$@" 2>&1)
    LAST_CODE=$?
    set -e
}

short() { printf '%s' "$1" | head -c 400; }

expect_code() {
    local label="$1" want="$2"
    if [ "$LAST_CODE" = "$want" ]; then
        ok "$label"
    else
        bad "$label — expected exit $want, got $LAST_CODE: $(short "$LAST_OUT")"
    fi
}

expect_contains() {
    local label="$1" needle="$2"
    case "$LAST_OUT" in
        *"$needle"*) ok "$label" ;;
        *) bad "$label — output lacks '$needle': $(short "$LAST_OUT")" ;;
    esac
}

expect_not_contains() {
    local label="$1" needle="$2"
    case "$LAST_OUT" in
        *"$needle"*) bad "$label — output still holds '$needle': $(short "$LAST_OUT")" ;;
        *) ok "$label" ;;
    esac
}

# A `name=<number>` marker printed by the PHP blocks that keep a connection
# open: the PHP side cannot assert, so it reports and the shell decides.
marker_value() {
    printf '%s\n' "$LAST_OUT" | sed -n "s/^$1=\([0-9][0-9]*\)$/\1/p" | head -1
}

expect_marker_positive() {
    local label="$1" marker="$2" value
    value="$(marker_value "$marker")"
    if [ -n "$value" ] && [ "$value" -gt 0 ]; then
        ok "$label ($marker=$value)"
    else
        bad "$label — $marker=${value:-missing}: $(short "$LAST_OUT")"
    fi
}

expect_marker_zero() {
    local label="$1" marker="$2" value
    value="$(marker_value "$marker")"
    if [ "$value" = 0 ]; then
        ok "$label"
    else
        bad "$label — $marker=${value:-missing}: $(short "$LAST_OUT")"
    fi
}

cleanup() {
    if [ "$EXTERNAL" = 1 ]; then
        # Only what this run created, and DDL still needs the flag: the gate is
        # about statements that change the file, not about the flag being there.
        php bin/cms clickhouse:query 'DROP TABLE IF EXISTS nativa_smoke__article_views' --write >/dev/null 2>&1 || true
        php bin/cms clickhouse:query 'DROP DATABASE IF EXISTS nativa_smoke' --write >/dev/null 2>&1 || true
        php bin/cms clickhouse:query 'DROP DATABASE IF EXISTS nativa_wal' --write >/dev/null 2>&1 || true
        rm -f "$FIXTURES/smoke.db" "$FIXTURES/wal.db" "$FIXTURES/wal.db-wal" "$FIXTURES/wal.db-shm"
    fi

    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" >/dev/null 2>&1 || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi

    rm -rf "$TMP"
}
trap cleanup EXIT

if [ ! -d src/Modules/Clickhouse ]; then
    echo "clickhouse-smoke: the Clickhouse module is not installed — run 'php bin/cms module:add Clickhouse'." >&2
    exit 1
fi

if [ -n "${CLICKHOUSE_URL:-}" ]; then
    if [ -z "${CLICKHOUSE_USER_FILES:-}" ]; then
        echo "clickhouse-smoke: CLICKHOUSE_URL is set, so the fixture must go inside that server's user_files — set CLICKHOUSE_USER_FILES to a directory on its filesystem this shell can write to." >&2
        exit 1
    fi

    EXTERNAL=1
    FIXTURES="$CLICKHOUSE_USER_FILES"
else
    FIXTURES="$TMP/user_files"
fi

mkdir -p "$FIXTURES"

# The schema is chosen for the two paths the bridge distinguishes: an INTEGER
# column (inferred Int64) and a DATETIME declaration (explicit DateTime), so
# `--typed` has something to plan and the plain attach has something to read.
# shellcheck disable=SC2016 # PHP, not shell: the single quotes are php -r's own
php -r '
$pdo = new PDO("sqlite:" . $argv[1]);
$pdo->exec("CREATE TABLE article_views (id INTEGER PRIMARY KEY, viewed_at DATETIME)");
$insert = $pdo->prepare("INSERT INTO article_views (id, viewed_at) VALUES (?, ?)");
$insert->execute([1, "2026-01-01 10:00:00"]);
$insert->execute([2, "2026-01-02 11:30:00"]);
' "$FIXTURES/smoke.db"

# A second file for the WAL read: one checkpointed row, written in rollback
# mode and closed, so what is on disk before the read is a file with no WAL.
# shellcheck disable=SC2016 # the same reason as above: php -r's own quoting
php -r '
$pdo = new PDO("sqlite:" . $argv[1]);
$pdo->exec("CREATE TABLE article_views (id INTEGER PRIMARY KEY, viewed_at DATETIME)");
$insert = $pdo->prepare("INSERT INTO article_views (id, viewed_at) VALUES (?, ?)");
$insert->execute([1, "2026-01-01 10:00:00"]);
' "$FIXTURES/wal.db"

# Outside the server's `user_files` on purpose: the engine has to refuse it.
# A server of our own sees this temp file and refuses it; an external server
# only sees paths on its own filesystem, so the refusal check runs there only
# when the run is self-contained (see the check itself).
OUTSIDE="$TMP/outside.db"
# shellcheck disable=SC2016 # the same reason as above: php -r's own quoting
php -r '
$pdo = new PDO("sqlite:" . $argv[1]);
$pdo->exec("CREATE TABLE t (n INTEGER)");
' "$OUTSIDE"

if [ "$EXTERNAL" = 0 ]; then
    BIN="${CLICKHOUSE_BIN:-$(command -v clickhouse || true)}"

    if [ -z "$BIN" ] || [ ! -x "$BIN" ]; then
        echo "clickhouse-smoke: no local ClickHouse server and no CLICKHOUSE_URL." >&2
        echo "  Install the static binary and point CLICKHOUSE_BIN at it:" >&2
        echo "    curl -L -o /tmp/clickhouse https://builds.clickhouse.com/master/amd64/clickhouse" >&2
        echo "    chmod +x /tmp/clickhouse && CLICKHOUSE_BIN=/tmp/clickhouse $0" >&2
        echo "  or use a server you already run:" >&2
        echo "    CLICKHOUSE_URL=http://127.0.0.1:8123 CLICKHOUSE_USER_FILES=/var/lib/clickhouse/user_files $0" >&2
        exit 1
    fi

    mkdir -p "$TMP/data" "$TMP/tmp" "$TMP/format" "$TMP/access"

    # A throwaway config: this server knows no other database, listens only on
    # loopback, and its `user_files` is the fixture directory — so the attach
    # paths are inside it and the outside fixture is inside nothing.
    cat >"$TMP/config.xml" <<XML
<clickhouse>
    <logger>
        <level>warning</level>
        <console>0</console>
        <log>$TMP/server.log</log>
        <errorlog>$TMP/server.err.log</errorlog>
    </logger>
    <http_port>$PORT</http_port>
    <tcp_port>$((PORT + 1000))</tcp_port>
    <listen_host>127.0.0.1</listen_host>
    <path>$TMP/data/</path>
    <tmp_path>$TMP/tmp/</tmp_path>
    <user_files_path>$FIXTURES/</user_files_path>
    <format_schema_path>$TMP/format/</format_schema_path>
    <access_control_path>$TMP/access/</access_control_path>
    <!-- No max_server_memory_usage: the static binary alone maps ~1.8 GiB of
         RSS, so any cap below that refuses the first query. The throwaway
         server holds one fixture file and is stopped with the script. -->
    <mark_cache_size>134217728</mark_cache_size>
    <users>
        <default>
            <password></password>
            <networks>
                <ip>127.0.0.1</ip>
            </networks>
            <profile>default</profile>
            <quota>default</quota>
            <access_management>0</access_management>
        </default>
    </users>
    <profiles>
        <default/>
    </profiles>
    <quotas>
        <default/>
    </quotas>
</clickhouse>
XML

    "$BIN" server --config-file="$TMP/config.xml" >"$TMP/server.stdout" 2>&1 &
    SERVER_PID=$!

    READY=0
    for _ in $(seq 1 60); do
        if ! kill -0 "$SERVER_PID" 2>/dev/null; then
            break
        fi

        if curl -fsS -m 2 "http://127.0.0.1:$PORT/ping" 2>/dev/null | grep -q '^Ok\.$'; then
            READY=1
            break
        fi

        sleep 1
    done

    if [ "$READY" != 1 ]; then
        echo "clickhouse-smoke: the server did not answer on 127.0.0.1:$PORT/ping." >&2
        tail -40 "$TMP/server.stdout" "$TMP/server.log" "$TMP/server.err.log" 2>/dev/null >&2 || true
        exit 1
    fi

    export CLICKHOUSE_URL="http://127.0.0.1:$PORT"
fi

export CLICKHOUSE_USER="${CLICKHOUSE_USER:-default}"
export CLICKHOUSE_PASSWORD="${CLICKHOUSE_PASSWORD:-}"
export CLICKHOUSE_CONNECT_TIMEOUT=2
export CLICKHOUSE_TIMEOUT=20
export CLICKHOUSE_DATABASE_PREFIX=nativa_
export DB_SMOKE="$FIXTURES/smoke.db"
export DB_WAL="$FIXTURES/wal.db"

if [ "$EXTERNAL" = 0 ]; then
    export DB_OUTSIDE="$OUTSIDE"
fi

FIXTURES_REAL="$(cd "$FIXTURES" && pwd -P)"

echo "clickhouse-smoke: $CLICKHOUSE_URL, fixtures in $FIXTURES_REAL"

# 1 — the dry run. No client is resolved, so this must work even against a
# server that would refuse; the assertion is the statement's shape and path.
run php bin/cms clickhouse:attach --db smoke --dry-run
expect_code 'attach --dry-run exits 0' 0
expect_contains 'the dry run writes the SQLite engine at the real path' \
    "CREATE DATABASE IF NOT EXISTS nativa_smoke ENGINE = SQLite('$FIXTURES_REAL/smoke.db')"
expect_contains 'the dry run says it changed nothing' 'Dry run: 1 database(s), nothing changed.'

# 2 — attach. `IF NOT EXISTS` makes the second run a no-op, which is the
# idempotency the spec claims; assert it here rather than trusting the string.
run php bin/cms clickhouse:attach --db smoke
expect_code 'attach exits 0' 0
expect_contains 'attach names the database it attached' 'Attached 1 database(s).'

run php bin/cms clickhouse:attach --db smoke
expect_code 'attach is idempotent' 0

# 3 — a read through the inferred table. INTEGER becomes Int64 and the row
# count is the fixture's, so a wrong path or a missing WAL read shows up here.
run php bin/cms clickhouse:query 'SELECT count() AS n FROM nativa_smoke.article_views'
expect_code 'a SELECT through the attached database exits 0' 0
expect_contains 'the server reads both fixture rows' '{"n":2}'

# 3b — the WAL half, all in one process on purpose: a read of a row that is
# only in the WAL, `--checkpoint` folding it into the file, and a write through
# ClickHouse while the kernel's own connection is still open. Reopening the
# connection between the steps would lose the subject every time — closing the
# last connection checkpoints the file, which is the state these checks test
# against. So PHP holds the handle, shells out to `bin/cms`, and prints plain
# `name=value` markers; the shell asserts them.
# shellcheck disable=SC2016 # php -r's own quoting; $argv and the markers are PHP's
run php -r '
$path = $argv[1];
$wal = $path . "-wal";
$walBytes = static function () use ($wal): int {
    clearstatcache();
    return is_file($wal) ? (int) filesize($wal) : 0;
};
$command = static function (string $arguments): array {
    $lines = [];
    $status = 0;
    exec("php bin/cms " . $arguments . " 2>&1", $lines, $status);
    return [$status, implode("\n", $lines)];
};
$readCount = "clickhouse:query " . escapeshellarg("SELECT count() AS n FROM nativa_wal.article_views");

$pdo = new PDO("sqlite:" . $path);
$pdo->exec("PRAGMA journal_mode=WAL");
$pdo->exec("PRAGMA wal_autocheckpoint=0");
$insert = $pdo->prepare("INSERT INTO article_views (id, viewed_at) VALUES (?, ?)");
$insert->execute([2, "2026-01-02 11:30:00"]);
printf("wal-before=%d\n", $walBytes());

[$status, $out] = $command("clickhouse:attach --db wal");
echo "attach-exit=", $status, "\n", $out, "\n";

[$status, $out] = $command($readCount);
echo "read-before-exit=", $status, "\n", $out, "\n";

[$status, $out] = $command("clickhouse:attach --db wal --checkpoint");
echo "checkpoint-exit=", $status, "\n", $out, "\n";
printf("wal-after-checkpoint=%d\n", $walBytes());

[$status, $out] = $command($readCount);
echo "read-after-checkpoint-exit=", $status, "\n", $out, "\n";

// ClickHouse reads double quotes as identifiers, so the literal needs real
// single quotes — built with chr(39) because this PHP program itself sits in
// shell single quotes.
$insertSql = "INSERT INTO nativa_wal.article_views (id, viewed_at) VALUES (3, "
    . chr(39) . "2026-01-03 09:00:00" . chr(39) . ")";
[$status, $out] = $command("clickhouse:query " . escapeshellarg($insertSql) . " --write");
echo "write-exit=", $status, "\n", $out, "\n";

[$status, $out] = $command($readCount);
echo "read-after-write-exit=", $status, "\n", $out, "\n";

echo "kernel-count-after-write=", (int) $pdo->query("SELECT count(*) FROM article_views")->fetchColumn(), "\n";
printf("wal-after-write=%d\n", $walBytes());

// A kernel write that has NOT committed: BEGIN IMMEDIATE takes the write lock
// and the row sits in the WAL while the transaction stays open across the
// ClickHouse INSERT below. This is the one state a filesystem copy cannot
// reproduce and a second writer must not be allowed to interleave with.
$pdo->exec("BEGIN IMMEDIATE");
$insert->execute([4, "2026-01-04 10:00:00"]);
printf("wal-in-transaction=%d\n", $walBytes());

$busySql = "INSERT INTO nativa_wal.article_views (id, viewed_at) VALUES (5, "
    . chr(39) . "2026-01-05 09:00:00" . chr(39) . ")";
[$status, $out] = $command("clickhouse:query " . escapeshellarg($busySql) . " --write");
echo "concurrent-exit=", $status, "\n", $out, "\n";

$pdo->commit();

echo "kernel-count-after-commit=", (int) $pdo->query("SELECT count(*) FROM article_views")->fetchColumn(), "\n";

[$status, $out] = $command($readCount);
echo "read-after-commit-exit=", $status, "\n", $out, "\n";
' "$DB_WAL"
expect_code 'the WAL scenario exits 0' 0
expect_marker_positive 'the WAL was non-empty before the read' wal-before
expect_contains 'the attach without --checkpoint exits 0' 'attach-exit=0'
expect_contains 'a non-empty WAL is reported before anything is folded' 'bytes in the WAL'
expect_contains 'the read before the checkpoint exits 0' 'read-before-exit=0'
expect_contains 'the server reads the row that exists only in the WAL' '{"n":2}'
expect_contains '--checkpoint reports what it folded in' 'Checkpointed '
expect_contains 'the checkpointed attach exits 0' 'checkpoint-exit=0'
expect_marker_zero 'the checkpoint truncated the WAL' wal-after-checkpoint
expect_contains 'the read after the checkpoint exits 0' 'read-after-checkpoint-exit=0'
expect_contains 'the write during the WAL exits 0' 'write-exit=0'
expect_contains 'the write warning is printed for the ClickHouse write' 'WARNING: a write locks the whole SQLite file'
expect_contains 'the read after the write exits 0' 'read-after-write-exit=0'
expect_contains 'the server reads the row ClickHouse wrote' '{"n":3}'
expect_contains 'the kernel connection sees the row ClickHouse wrote' 'kernel-count-after-write=3'
expect_marker_positive 'the ClickHouse write went through the WAL' wal-after-write
expect_not_contains 'no read fell back to the checkpointed row alone' '{"n":1}'
expect_marker_positive 'a kernel write in flight is in the WAL' wal-in-transaction
expect_contains 'a concurrent ClickHouse write is refused' 'concurrent-exit=1'
expect_contains 'the refusal is the engine'"'"'s own lock' 'database is locked'
expect_contains 'the kernel transaction commits' 'kernel-count-after-commit=4'
expect_contains 'the committed row is readable through ClickHouse' '{"n":4}'
expect_not_contains 'the refused concurrent row never appears' '{"n":5}'

# 4 — status reads the path back from the server. Other kernel databases are
# deliberately not attached in this run, so the assertion is this database's
# line and the absence of a drift line for it; the exit code is not the check.
run php bin/cms clickhouse:status
if [ "$LAST_CODE" = 0 ] || [ "$LAST_CODE" = 1 ]; then
    ok 'status answers (0 healthy, 1 with drift for the unattached kernel files)'
else
    bad "status exited $LAST_CODE: $(short "$LAST_OUT")"
fi
expect_contains 'status prints the path the server reported' "nativa_smoke  $FIXTURES_REAL/smoke.db  1 tables"
expect_not_contains 'status reports no drift for the attached database' 'drift: nativa_smoke'

# 5 — the rule the doc does not state: a file outside `user_files` is refused
# by the engine itself (error 81), not by this tree. Only a server this run
# started can see `$TMP/outside.db`; with an external server the answer would be
# about reachability, not about the rule, so the check says it is skipped.
if [ "$EXTERNAL" = 0 ]; then
    run php bin/cms clickhouse:attach --db outside
    expect_code 'attach refuses a file outside user_files' 1
    expect_contains 'the refusal is the server'"'"'s PATH_ACCESS_DENIED' 'PATH_ACCESS_DENIED'
else
    echo 'skip  the user_files refusal — an external server cannot see this temp file'
fi

# 6 — `--typed` writes the explicit table into a normal database, because the
# attached one already exposes every table implicitly (measured).
run php bin/cms clickhouse:attach --db smoke --typed
expect_code 'attach --typed exits 0' 0
expect_contains 'the explicit table is created beside the attached database' \
    'CREATE TABLE IF NOT EXISTS nativa_smoke__article_views'
expect_contains 'the typed column is warned about by name' \
    'nativa_smoke.article_views.viewed_at as DateTime'

# 7 — the explicit table really has DateTime, and its rows parse. Both are the
# measured difference from the inferred table, where the same column is String.
run php bin/cms clickhouse:query 'DESCRIBE nativa_smoke__article_views'
expect_code 'DESCRIBE on the explicit table exits 0' 0
expect_contains 'the explicit table declares DateTime' '"type":"DateTime"'

run php bin/cms clickhouse:query 'SELECT count() AS n FROM nativa_smoke__article_views'
expect_code 'the explicit table reads' 0
expect_contains 'the explicit table returns the same two rows' '{"n":2}'

# 8 — the write gate: refused without the flag, accepted with it, and the row
# is visible afterwards. The refusal must be the gate's, not the server's.
run php bin/cms clickhouse:query "INSERT INTO nativa_smoke.article_views (id, viewed_at) VALUES (3, '2026-01-03 09:00:00')"
expect_code 'an INSERT without --write is refused' 1
expect_contains 'the refusal names the flag' 'needs --write'

run php bin/cms clickhouse:query 'SELECT count() AS n FROM nativa_smoke.article_views'
expect_contains 'the refused write changed nothing' '{"n":2}'

run php bin/cms clickhouse:query "INSERT INTO nativa_smoke.article_views (id, viewed_at) VALUES (3, '2026-01-03 09:00:00')" --write
expect_code 'an INSERT with --write exits 0' 0
expect_contains 'the write warning is printed' 'WARNING: a write locks the whole SQLite file'

run php bin/cms clickhouse:query 'SELECT count() AS n FROM nativa_smoke.article_views'
expect_contains 'the written row is readable through ClickHouse' '{"n":3}'

# The version comes from the server, not from the binary's --version: an
# external server answers for itself, and the point of the line is which engine
# the checks just met.
SERVER_VERSION="$(php bin/cms clickhouse:query 'SELECT version()' 2>/dev/null \
    | sed -n 's/.*"version()":"\([^"]*\)".*/\1/p' | head -1)"
SERVER_VERSION="${SERVER_VERSION:-unknown}"

# The job summary, when a workflow is running this: a report with the two
# numbers a reader looks for, so a red optional job is diagnosable from the run
# page instead of from 49 log lines. Local runs print the same line instead.
write_summary() {
    local status="$1"

    if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
        {
            printf '## ClickHouse smoke\n\n'
            printf '| | |\n|---|---|\n'
            printf '| Result | %s |\n' "$status"
            printf '| Checks | %d passed, %d failed |\n' "$CHECKS_OK" "$CHECKS_FAILED"
            printf '| Server | ClickHouse %s |\n' "$SERVER_VERSION"
            printf '| Target | %s |\n' "$CLICKHOUSE_URL"
            if [ "$EXTERNAL" = 1 ]; then
                printf '| Mode | an existing server |\n'
            else
                printf '| Mode | a throwaway server started by the target |\n'
            fi
            printf '\nFull output in the job log.\n'
        } >>"$GITHUB_STEP_SUMMARY"
    fi
}

echo
if [ "$FAILED" != 0 ]; then
    write_summary failed
    echo "clickhouse-smoke: FAILED — $CHECKS_OK passed, $CHECKS_FAILED failed, ClickHouse $SERVER_VERSION"
    exit 1
fi

write_summary passed
echo "clickhouse-smoke: OK — $CHECKS_OK checks against ClickHouse $SERVER_VERSION: attach, status, query, the WAL read, --checkpoint, the concurrent-writer refusal, --typed and the write gate at $CLICKHOUSE_URL"
