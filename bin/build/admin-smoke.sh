#!/usr/bin/env bash
# admin-smoke.sh — the admin flows over real HTTP, as a GATE.
#
# Why it exists: every guard the security rounds added lives behind an admin
# session — the CSRF origin check, the throttles, the `/mark` access control, the
# bulk-refusal security events — and the suite proves them a class at a time, not
# through a booted server with a real session and a real token. Boot 27 measured
# these flows by hand; this is that measurement made enforceable, so a change
# that 500s the panel or drops a security event fails a job instead of a
# reviewer's memory.
#
# What it walks, in order: the guest surfaces, the admin account surfaces (users,
# roles, bulk refusal), the content editors (an article and a page, with the FILE
# as the assertion), the public comment form (honeypot, moderation, publication)
# and the two queues (the outbox drain that delivers the events this run records,
# and the Queue module's worker over a job nobody can route).
#
# It is deliberately NOT a substitute for the suite: it asserts the statuses,
# the rows and the recorded events, and it seeds its own accounts through the
# modules' own repositories because the Users migrator seeds no roles.
#
# Every DB_* points at a temp directory and the server is started and stopped
# here, so the working tree's storage/data/ is never opened (Boot 27's rule).
#
# CONTENT is the one exception, and it is stated rather than hidden: articles
# and pages live under `storage/` with no root override the way the databases
# have one, so the content section writes there — and then fails the run if the
# tree does not come back exactly as it found it (tree_files, compared before
# and after). On a workstation that means a `smoke-*` post exists for the
# duration of the run; in CI the checkout is disposable anyway.
#
# Usage: bin/build/admin-smoke.sh          (needs the catalogue installed)
#        SMOKE_PORT=8399 bin/build/admin-smoke.sh
set -euo pipefail

cd "$(dirname "$0")/../.."

PORT="${SMOKE_PORT:-8399}"
BASE="http://127.0.0.1:${PORT}"
TMP="$(mktemp -d)"
FAILED=0

export APP_ENV=production
# Fixture credentials for accounts this script creates in temp databases and
# deletes again; `security-audit` would otherwise read them as leaks. No real
# account ever holds one — `make admin-smoke` never touches a real database.
export SMOKE_ADMIN_EMAIL='smoke-admin@example.test'
export SMOKE_ADMIN_PASSWORD='Smoke-Admin-2026!' # audit-ok: smoke fixture
export SMOKE_AUTHOR_EMAIL='smoke-author@example.test'
export SMOKE_AUTHOR_PASSWORD='Smoke-Author-2026!' # audit-ok: smoke fixture
export SMOKE_USER_EMAIL='smoke-user@example.test'
export SMOKE_USER_PASSWORD='Smoke-User-2026!' # audit-ok: smoke fixture

for db in users outbox config audit blog comments forms locales media questions queue; do
    export "DB_$(printf '%s' "$db" | tr '[:lower:]' '[:upper:]')=$TMP/$db.db"
done

ok() { printf 'ok    %s\n' "$1"; }
bad() { printf '::error::%s\n' "$1"; FAILED=1; }

status() { curl -s -o "$TMP/last.out" -w '%{http_code}' --max-time 15 "$@"; }
token() { sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$TMP/last.out" | head -1; }
# Read one value back out of a module database (the temp ones above).
# shellcheck disable=SC2016 # PHP, not shell: the single quotes are php -r's own, $argv is PHP's
#
# The shellcheck directive is not a gate being silenced — one quoted PHP program
# is exactly what is wanted here, and the alternative (a variable holding the
# code) is a shell-injection shape for no gain.
dbq() { php -r 'echo (new PDO("sqlite:" . getenv($argv[1])))->query($argv[2])->fetchColumn();' "$1" "$2"; }

# The ONE exception to this script writing nothing to a database directly:
# everything else here is created through the product, because the point of the
# gate is that the product creates it. Retention cannot be reached that way —
# ageing a row by 400 days through the product would take 400 days — so the
# retention section backdates `failed_at` itself. It is the only INSERT in the
# file and it says so, because an unexplained one would be the first thing
# somebody deletes.
# shellcheck disable=SC2016 # the same reason as dbq above: php -r's own quoting
# The PDO call is the whole program: its return value (the affected row count) is
# not fed to PHP's exec(), which would run that integer as a shell command and
# print `sh: line 1: 1: command not found` into the gate's output.
db_exec() { php -r '(new PDO("sqlite:" . getenv($argv[1])))->exec($argv[2]);' "$1" "$2"; }

# One assertion, one report line: `want <label> <expected> <actual>`.
want() {
    if [ "$3" = "$2" ]; then
        ok "$1 → $3"
    else
        bad "$1 expected $2, got $3"
    fi
}

# The same, for a count that must be at least <expected>.
want_at_least() {
    if [ "$3" -ge "$2" ]; then
        ok "$1 → $3"
    else
        bad "$1 expected ≥ $2, got $3"
    fi
}

# Every content file the checkout carries, minus the volatile trees. Content has
# no root override the way the databases do (AppPaths::storage() is the checkout),
# so the content section below WRITES into storage/ and this is how it proves it
# left the tree exactly as it found it — a leftover fails the run instead of
# quietly surviving it.
tree_files() {
    find storage -type f \
        -not -path 'storage/data/*' \
        -not -path 'storage/logs/*' \
        -not -path 'storage/cache/*' \
        -not -path 'storage/sessions/*' \
        | sort
}

# One assertion on the BODY `status` just wrote, for the pages where a 200 is
# not the claim. `APP_ENV=production` sets display_errors=0, so a template
# reading a key the row does not have renders an EMPTY table and still answers
# 200 — the failure mode a status check cannot see, and the reason the module
# list is asserted on a module name rather than on its status alone.
body_has() {
    local label="$1" needle="$2"
    if grep -qF -- "$needle" "$TMP/last.out"; then
        ok "$label"
    else
        bad "$label — '$needle' is not in the page"
    fi
}

check() {
    local label="$1" path="$2" want="$3"
    shift 3
    local got
    got="$(status "$@" "$BASE$path")"
    if [ "$got" = "$want" ]; then
        ok "$label → $got"
    else
        bad "$label expected $want, got $got"
    fi
}

# Invoked by the trap below; shellcheck cannot see through `trap`.
# shellcheck disable=SC2329
cleanup() {
    if [ -n "${SERVER_PID:-}" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
    fi
    rm -rf "$TMP"
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Preconditions — this gate runs against a tree with the catalogue installed
# ---------------------------------------------------------------------------
# The modules every section below needs. Deliberately not "whatever is
# installed": a tree missing one of these would silently skip checks instead of
# failing, and a gate that skips is the thing this job replaced.
# Membership comes from the CLI, not from `src/Modules/$module`: a module may be
# filed under a group (`src/Modules/Themes/<Name>`), and a path check would
# report it missing while the tree is complete.
installed_names=" $(php bin/cms module:list | sed -n 's/^Installed ([0-9]*): //p' | tr ',' ' ') "
for module in Audit Cardboard Comments Forms Outbox Queue Scheduler Security Users; do
    case "$installed_names" in
        *" $module "*) ;;
        *)
            echo "::error::$module is not installed — run bin/build/install-catalogue.sh first"
            exit 1
            ;;
    esac
done

# ---------------------------------------------------------------------------
# A temp tree: migrate, then the accounts (the migrator seeds no roles, so the
# admin role and the five permissions are built through the module's own
# repositories — Permissions::all() stays the one owner of that vocabulary)
# ---------------------------------------------------------------------------
php bin/cms migrate > "$TMP/migrate.log" 2>&1 || { cat "$TMP/migrate.log"; bad "bin/cms migrate failed"; exit 1; }
grep -q 'Migrated:' "$TMP/migrate.log" || { cat "$TMP/migrate.log"; bad "no migrators ran"; exit 1; }

php <<'PHP'
<?php
require 'src/init.php';

use Modules\Core\Domain\Permissions;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Users\Infrastructure\Persistence\Repositories\RoleRepository;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;

$pdo = DatabaseConnectionManager::instance()->connection('users');
$roles = new RoleRepository($pdo);
$users = new UserRepository($pdo);
$hasher = new PasswordHasher();

$roles->createRole('smoke00000000000000000000000001', 'admin', 'Smoke admin');
$roles->createRole('smoke00000000000000000000000002', 'author', 'Smoke author');

// One owner for the vocabulary: the permission names come from the class the
// migrator and the admin UI both read.
foreach (Permissions::all() as $i => $name) {
    $id = sprintf('smokeperm%022d', $i);

    $pdo->prepare('INSERT OR IGNORE INTO permissions (id, name, description, group_name) VALUES (?, ?, ?, ?)')
        ->execute([$id, $name, 'Smoke: ' . $name, 'general']);

    // admin: every permission. author: exactly what a content editor holds.
    $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)')
        ->execute(['smoke00000000000000000000000001', $id]);

    if (in_array($name, [Permissions::MARK_ACCESS, Permissions::CONTENT], true)) {
        $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)')
            ->execute(['smoke00000000000000000000000002', $id]);
    }
}

$admin = $users->insert([
    'id'       => 'smoke00000000000000000000000003',
    'name'     => 'Smoke Admin',
    'email'    => (string) getenv('SMOKE_ADMIN_EMAIL'),
    'password' => $hasher->hash((string) getenv('SMOKE_ADMIN_PASSWORD')),
]);
$users->assignRole($admin->id(), 'smoke00000000000000000000000001');

$author = $users->insert([
    'id'       => 'smoke00000000000000000000000004',
    'name'     => 'Smoke Author',
    'email'    => (string) getenv('SMOKE_AUTHOR_EMAIL'),
    'password' => $hasher->hash((string) getenv('SMOKE_AUTHOR_PASSWORD')),
]);
$users->assignRole($author->id(), 'smoke00000000000000000000000002');

echo "seeded admin + author\n";
PHP

# ---------------------------------------------------------------------------
# The server. opcache off: a long-lived `php -S` serving code that no longer
# exists is measured in Boot 18, and this script must observe what it just
# migrated.
# ---------------------------------------------------------------------------
php -d opcache.enable_cli=0 -S "127.0.0.1:$PORT" -t public/ > "$TMP/server.log" 2>&1 &
SERVER_PID=$!

ready=0
for _ in $(seq 1 30); do
    if curl -fsS -o /dev/null --max-time 5 "$BASE/" 2>/dev/null; then
        ready=1
        break
    fi
    sleep 1
done

if [ "$ready" -ne 1 ]; then
    cat "$TMP/server.log"
    bad "the server never came up on $BASE"
    exit 1
fi
ok "server up on $BASE (temp DBs in $(basename "$TMP"))"

# ---------------------------------------------------------------------------
# A guest: the public surfaces answer, the admin does not (302 to the login)
# ---------------------------------------------------------------------------
check 'guest GET /' '/' 200
check 'guest GET /login' '/login' 200
check 'guest GET /docs' '/docs' 200
check 'guest GET /nope' '/nope' 404
check 'guest GET /mark' '/mark' 302
check 'guest GET /mark/users' '/mark/users' 302

# ---------------------------------------------------------------------------
# The admin
# ---------------------------------------------------------------------------
# The token is scraped from the FORM the browser would fill in — status() writes
# the body to last.out, which is what token() reads.
ADMIN_JAR="$TMP/admin.jar"
status -c "$ADMIN_JAR" "$BASE/login" > /dev/null
ADMIN_TOKEN="$(token)"

got="$(status -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/login" \
    --data-urlencode "email=$SMOKE_ADMIN_EMAIL" \
    --data-urlencode "password=$SMOKE_ADMIN_PASSWORD" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /login' 302 "$got"

check 'admin GET /mark' '/mark' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
check 'admin GET /mark/users' '/mark/users' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
check 'admin GET /mark/roles' '/mark/roles' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"

# EVERY admin page that renders without a parameter, opened the way a session
# opens it. The list is DERIVED from the route attributes, not spelled here: a
# second copy of a path is the duplication this tree refuses, and the scan is
# the router's own, so a page added tomorrow is opened tomorrow.
#
# The reason is one defect and it is not hypothetical. `/mark/questions`
# rendered a `static fn` that reached for `$this->url()`, which PHP only
# refuses when the line runs: the panel answered 500 to the first browser while
# every static gate and the whole suite were green, because a closure body is
# not a type and a page that renders is not a page a gate opened (Boot 47).
ADMIN_PAGES="$(php -r '
require "src/init.php";
$pages = [];
foreach ((new Modules\Core\Interfaces\HTTP\Router([APP_BASE_PATH . "/src/Modules"]))->toEntries() as $entry) {
    if ("GET" === $entry["method"] && str_starts_with($entry["path"], "/mark") && !str_contains($entry["path"], "{")) {
        $pages[] = $entry["path"];
    }
}
sort($pages);
echo implode(" ", $pages);' 2>/dev/null)"

if [ -z "$ADMIN_PAGES" ]; then
    bad 'the route scan found no admin page to open — the router refused to load'
fi

for page in $ADMIN_PAGES; do
    check "admin GET $page" "$page" 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
done

# The module list answers 200 through a BOOTED server, and that is the whole
# reason this line exists. The page was written with its #[Route] attributes on
# `handle()` instead of on the class: `Router::discover()` reads
# `$reflection->getAttributes(Route::class)` — the class's own, one level up —
# and a method's attributes are never looked at. Every static gate passed: it
# compiles, the `use` resolves, phpstan infers the attribute, cs-fixer has
# nothing to say, and the suite never dispatches a route. `/mark/settings/modules`
# answered 404 with eight CI jobs green (Boot 44).
#
# `check` is the only assertion in this file that can catch that class of
# defect, so a new admin page that does not answer 200 fails here rather than in
# a browser. The status is the assertion — the page rendering at all is the
# thing being proven, and `last.out` is the body if it does not.
check 'admin GET /mark/settings/modules' '/mark/settings/modules' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
body_has 'the module list names an installed module' 'Cardboard'
body_has 'the module list has a drifted-files column' 'Drifted files'
body_has 'an installed row offers uninstall' 'Uninstall'
body_has 'an absent row offers install' '/mark/settings/modules/Scratch/install'

ADMIN_TOKEN="$(token)"

# Install and uninstall through the panel, on the lifecycle fixture: `Scratch`
# is catalogued and `install-catalogue.sh` deliberately leaves it out, so a
# slice that exists afterwards was copied by THIS request. The directory is the
# assertion, not the status — 302 is also the answer for a refused step.
if [ -d src/Modules/Scratch ]; then
    bad 'Scratch was already installed before the panel install (a leaked fixture)'
fi

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/settings/modules/Scratch/install" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/settings/modules/{name}/install' 302 "$got"
if [ -d src/Modules/Scratch ]; then
    ok 'the panel install copied the slice'
else
    bad 'the panel install left no slice behind'
fi

# The same step as an htmx request: no redirect, the panel fragment back — the
# button swaps `#modules-panel` in place, and the fragment must therefore be the
# panel and not a whole document.
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/settings/modules/Scratch/uninstall" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE" -H 'HX-Request: true')"
want 'htmx POST /mark/settings/modules/{name}/uninstall' 200 "$got"
if grep -q 'id="modules-panel"' "$TMP/last.out" && ! grep -q '<html' "$TMP/last.out"; then
    ok 'the htmx response is the panel fragment, not a page'
else
    bad 'the htmx response is not the panel fragment'
fi
if [ -d src/Modules/Scratch ]; then
    bad 'the panel uninstall left the slice behind'
else
    ok 'the panel uninstall removed the slice'
fi

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/settings/modules/Scratch/install" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/settings/modules/{name}/install (rerun)' 302 "$got"
if [ -d src/Modules/Scratch ]; then
    ok 'the non-htmx install still copies the slice'
else
    bad 'the non-htmx install left no slice behind'
fi

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/settings/modules/Scratch/uninstall" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/settings/modules/{name}/uninstall' 302 "$got"
if [ -d src/Modules/Scratch ]; then
    bad 'the panel uninstall left the slice behind'
else
    ok 'the panel uninstall removed the slice'
fi

# An unknown name is refused before any path is built from it.
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/settings/modules/__nope__/install" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/settings/modules/{name}/install for an unknown name' 302 "$got"
if [ -d src/Modules/__nope__ ]; then
    bad 'an unknown module name created a directory'
else
    ok 'an unknown module name created nothing'
fi

# A user is created and deleted through the panel: the row is the assertion, not
# the status — 302 is also the answer for an id that does not exist (Boot 27).
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/users/create" \
    --data-urlencode 'name=Smoke User' \
    --data-urlencode "email=$SMOKE_USER_EMAIL" \
    --data-urlencode "password=$SMOKE_USER_PASSWORD" \
    --data-urlencode 'role_id=smoke00000000000000000000000002' \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/users/create' 302 "$got"

created="$(dbq DB_USERS "SELECT COUNT(*) FROM users WHERE email = '$SMOKE_USER_EMAIL'")"
want 'the created user row exists' 1 "$created"

USER_ID="$(dbq DB_USERS "SELECT id FROM users WHERE email = '$SMOKE_USER_EMAIL'")"
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/users/$USER_ID/delete" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/users/{id}/delete' 302 "$got"

left="$(dbq DB_USERS "SELECT COUNT(*) FROM users WHERE email = '$SMOKE_USER_EMAIL'")"
want 'the deleted user row is gone' 0 "$left"

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/roles/create" \
    --data-urlencode 'name=smoke-role' --data-urlencode 'description=Smoke' \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/roles/create' 302 "$got"

ROLE_ID="$(dbq DB_USERS "SELECT id FROM roles WHERE name = 'smoke-role'")"
if [ -n "$ROLE_ID" ]; then ok 'the created role row exists'; else bad 'the created role row is missing'; fi

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/roles/$ROLE_ID/delete" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/roles/{id}/delete' 302 "$got"

gone="$(dbq DB_USERS "SELECT COUNT(*) FROM roles WHERE name = 'smoke-role'")"
want 'the deleted role row is gone' 0 "$gone"

# The mutations above are the guarded call sites: with the Outbox installed the
# events must land (Boot 26 made them optional, not absent).
events="$(dbq DB_OUTBOX "SELECT COUNT(*) FROM outbox_messages WHERE event_type = 'security.user.changed'")"
want_at_least 'security.user.changed events' 2 "$events"

events="$(dbq DB_OUTBOX "SELECT COUNT(*) FROM outbox_messages WHERE event_type = 'security.role.changed'")"
want_at_least 'security.role.changed events' 2 "$events"

# ---------------------------------------------------------------------------
# CSRF: no token and a cross-site Origin are both refused
# ---------------------------------------------------------------------------
check 'POST /mark/users/create with no token' '/mark/users/create' 403 \
    -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST \
    --data-urlencode 'name=X' --data-urlencode 'email=no-token@example.test' \
    --data-urlencode 'password=Smoke-User-2026!'

check 'POST with a valid token and a cross-site Origin' '/mark/users/create' 403 \
    -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST \
    --data-urlencode "name=X" --data-urlencode "email=$SMOKE_USER_EMAIL" \
    --data-urlencode "password=$SMOKE_USER_PASSWORD" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H 'Origin: http://evil.example'

# ---------------------------------------------------------------------------
# The refusal branch: an author may not post a bulk operation for `users`, and
# the refusal rides the Outbox (the call site Boot 26 re-pointed).
# ---------------------------------------------------------------------------
AUTHOR_JAR="$TMP/author.jar"
status -c "$AUTHOR_JAR" "$BASE/login" > /dev/null
AUTHOR_TOKEN="$(token)"

got="$(status -c "$AUTHOR_JAR" -b "$AUTHOR_JAR" -X POST "$BASE/login" \
    --data-urlencode "email=$SMOKE_AUTHOR_EMAIL" \
    --data-urlencode "password=$SMOKE_AUTHOR_PASSWORD" \
    --data-urlencode "_token=$AUTHOR_TOKEN" -H "Origin: $BASE")"
want 'author POST /login' 302 "$got"

check 'author GET /mark' '/mark' 200 -b "$AUTHOR_JAR" -c "$AUTHOR_JAR"
AUTHOR_TOKEN="$(token)"

got="$(status -b "$AUTHOR_JAR" -c "$AUTHOR_JAR" -X POST "$BASE/mark/bulk-publish" \
    --data-urlencode 'entity=users' --data-urlencode 'ids[]=1' --data-urlencode 'published=1' \
    --data-urlencode "_token=$AUTHOR_TOKEN" -H "Origin: $BASE")"
want 'author bulk-publish entity=users' 403 "$got"

if grep -q 'not permitted' "$TMP/last.out"; then ok 'the refusal names the reason'; else bad 'the refusal body carries no reason'; fi

denied="$(dbq DB_OUTBOX "SELECT COUNT(*) FROM outbox_messages WHERE event_type = 'security.authz.denied'")"
want_at_least 'security.authz.denied events' 1 "$denied"

# The MIDDLEWARE refusal branch — a different call site from the bulk gate, and
# the one that used to be lost. Measured 2026-10-16: the middleware wrote the bare
# `authz.denied`, a type no handler in `SecurityEvents::all()` claims, so every
# refused admin path became a message the drain failed with "No handler registered
# for event type" and NO audit row was ever written. The author holds `mark.access`
# and `content.manage` and nothing else, so `/mark/users` is that 403 through a
# booted server; its delivery is asserted in the worker section below, where the
# drain has provably run.
check 'author GET /mark/users' '/mark/users' 403 -b "$AUTHOR_JAR" -c "$AUTHOR_JAR"

# `json_extract`, not a LIKE: the payload is `json_encode`d, which escapes the
# slashes — the stored path is `\/mark\/users`, so a LIKE '%/mark/users%' matches
# nothing and the check reports a refusal that is right there. (Measured: that
# version of this line failed the run while the audit row below it passed.)
refused="$(dbq DB_OUTBOX "SELECT COUNT(*) FROM outbox_messages WHERE event_type = 'security.authz.denied' AND json_extract(event_payload, '\$.path') = '/mark/users'")"
want_at_least 'the middleware refusal recorded' 1 "$refused"

# ---------------------------------------------------------------------------
# Content: an ARTICLE is written, served and deleted through the panel
# ---------------------------------------------------------------------------
tree_files > "$TMP/tree-before.txt"
RAND="$RANDOM$RANDOM"
ARTICLE="smoke-article-$RAND"

check 'admin GET /mark/articles' '/mark/articles' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
ADMIN_TOKEN="$(token)"

# `date` is required alongside title and body (ArticlesCreateAction::validate),
# and its absence is a 400 with the form re-rendered — measured.
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/articles/create?locale=sk" \
    --data-urlencode "title=Smoke article $RAND" \
    --data-urlencode "slug=$ARTICLE" \
    --data-urlencode "date=$(date +%Y-%m-%d)" \
    --data-urlencode 'body=Written by the admin smoke gate.' \
    --data-urlencode 'published=1' --data-urlencode 'locale=sk_SK' \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/articles/create' 302 "$got"

if find storage -name "$ARTICLE.md" | grep -q .; then ok 'the article file exists'; else bad 'no article file was written'; fi
check 'the published article is served' "/blog/$ARTICLE" 200
check 'the article list is served' '/blog' 200

# ---------------------------------------------------------------------------
# THE QUEUED COVER — the tree's first real queue producer, end to end.
#
# Writing the article above queued a `blog.cover` job carrying only its slug and
# locale. This is the last point in the script where the article exists: the
# handler renders from the markdown file, so a cover job worked after the delete
# below would be asserting on a slug that is gone and would fail for a reason
# that has nothing to do with the producer.
#
# The FILE is the assertion, for the reason the article's own check above is the
# file and not the 302: a status cannot tell a rendered cover from a failed
# render that still redirected.
# ---------------------------------------------------------------------------
want 'writing the article queued exactly one cover job' 1 \
    "$(dbq DB_QUEUE "SELECT COUNT(*) FROM jobs WHERE handler = 'blog.cover'")"

if php bin/cms queue:work > "$TMP/cover-work.log" 2>&1; then
    ok 'bin/cms queue:work exits 0 having rendered the cover'
else
    cat "$TMP/cover-work.log"
    bad 'bin/cms queue:work failed on the queued cover'
fi

if [ -s "storage/blog-images/$ARTICLE.svg" ]; then
    ok 'the worker rendered the cover the article queued'
else
    bad "no cover at storage/blog-images/$ARTICLE.svg"
fi

want 'the queue is empty again — the job is deleted on success' 0 \
    "$(dbq DB_QUEUE "SELECT COUNT(*) FROM jobs WHERE handler = 'blog.cover'")"

# The gate does NOT tidy the cover up here any more, and that is the change.
# It used to: `rm -f "storage/blog-images/$ARTICLE.svg"`, with a KNOWN GAP
# comment explaining that `BlogCrudService::delete()` was a single `unlink()`
# and therefore orphaned every cover it should have removed. The product now
# removes the cover itself, so the assertion belongs BELOW, next to the delete
# that causes it — and a gate that cleans up after the code it is gating can
# only ever prove that the SCRIPT is tidy.
#
# The gate assertion for it is at the article delete, further down. Leaving the
# `rm` in place would have hidden the fix as well as the regression.

# ---------------------------------------------------------------------------
# The PUBLIC COMMENT FORM — the one mutation a stranger can make, and the
# moderation gate in front of it. The article from the section above is the
# host, so this runs before it is deleted.
# ---------------------------------------------------------------------------
GUEST_JAR="$TMP/guest.jar"
status -c "$GUEST_JAR" -b "$GUEST_JAR" "$BASE/blog/$ARTICLE" > /dev/null
GUEST_TOKEN="$(token)"
COMMENT_BODY="Smoke gate comment $RAND"

# The honeypot first: the field a bot fills in because it completes every field
# it finds. The answer is the very redirect a real submission gets — the
# assertion is the row that never appears, because the status cannot tell the
# two apart by design.
got="$(status -b "$GUEST_JAR" -c "$GUEST_JAR" -X POST "$BASE/blog/$ARTICLE/comments" \
    --data-urlencode 'guest_name=Spam Bot' \
    --data-urlencode 'guest_email=bot@example.test' \
    --data-urlencode "body=$COMMENT_BODY, honeypot" \
    --data-urlencode 'comment_website=https://spam.example' \
    --data-urlencode "_token=$GUEST_TOKEN" -H "Origin: $BASE")"
want 'honeypot POST /blog/{slug}/comments' 302 "$got"

trapped="$(dbq DB_COMMENTS "SELECT COUNT(*) FROM comments WHERE article_slug = '$ARTICLE'")"
want 'a honeypot submission stores no row' 0 "$trapped"

# A real guest comment: 302 back to the article, one row — and 'pending', which
# is what makes the next two checks mean something.
got="$(status -b "$GUEST_JAR" -c "$GUEST_JAR" -X POST "$BASE/blog/$ARTICLE/comments" \
    --data-urlencode 'guest_name=Smoke Guest' \
    --data-urlencode 'guest_email=guest@example.test' \
    --data-urlencode "body=$COMMENT_BODY" \
    --data-urlencode "_token=$GUEST_TOKEN" -H "Origin: $BASE")"
want 'guest POST /blog/{slug}/comments' 302 "$got"

state="$(dbq DB_COMMENTS "SELECT status FROM comments WHERE article_slug = '$ARTICLE'")"
want 'the submitted comment starts pending' 'pending' "$state"
COMMENT_ID="$(dbq DB_COMMENTS "SELECT id FROM comments WHERE article_slug = '$ARTICLE'")"

# The article page is the assertion: the row exists and the reader cannot see
# it. Nothing in the status distinguishes this from a comment that was never
# submitted at all, which is exactly the point.
status "$BASE/blog/$ARTICLE" > /dev/null
if grep -q "$COMMENT_BODY" "$TMP/last.out"; then
    bad 'a pending comment is visible on the article'
else
    ok 'a pending comment stays out of the article'
fi

# The moderation queue lists it; approving it publishes it.
check 'admin GET /mark/comments' '/mark/comments' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
ADMIN_TOKEN="$(token)"

if grep -q "$COMMENT_BODY" "$TMP/last.out"; then
    ok 'the moderation queue lists the submitted comment'
else
    bad 'the moderation queue does not list the submitted comment'
fi

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/comments/$COMMENT_ID/approve" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/comments/{id}/approve' 302 "$got"

state="$(dbq DB_COMMENTS "SELECT status FROM comments WHERE id = '$COMMENT_ID'")"
want 'the moderated comment is approved' 'approved' "$state"

status "$BASE/blog/$ARTICLE" > /dev/null
if grep -q "$COMMENT_BODY" "$TMP/last.out"; then
    ok 'the approved comment is served on the article'
else
    bad 'the approved comment is not served on the article'
fi

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/comments/$COMMENT_ID/delete" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/comments/{id}/delete' 302 "$got"

gone="$(dbq DB_COMMENTS "SELECT COUNT(*) FROM comments WHERE id = '$COMMENT_ID'")"
want 'the deleted comment row is gone' 0 "$gone"

status "$BASE/blog/$ARTICLE" > /dev/null
if grep -q "$COMMENT_BODY" "$TMP/last.out"; then
    bad 'the deleted comment is still served'
else
    ok 'the deleted comment is no longer served'
fi
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/articles/$ARTICLE/delete" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/articles/{slug}/delete' 302 "$got"
if find storage -name "$ARTICLE.md" | grep -q .; then bad 'the article file survived the delete'; else ok 'the article file is gone'; fi

# The cover the queue worker rendered for this article goes WITH it. This is
# the check that used to be an `rm -f` two hundred lines up, because
# `BlogCrudService::delete()` was a single `unlink()` of the markdown and left
# the SVG behind — still on disk, still served by `ServeBlogImageAction` under
# `max-age=31536000`, at a URL nothing links to any more.
#
# The file is the assertion for the same reason the article's own is: the 302
# cannot tell a cover that was removed from a cover that was never there.
if [ -s "storage/blog-images/$ARTICLE.svg" ]; then
    bad 'the cover outlived the article it belongs to'
else
    ok 'the generated cover is removed with the article'
fi

check 'the deleted article is 404' "/blog/$ARTICLE" 404

# ---------------------------------------------------------------------------
# Content: a PAGE, the same way — and then the tree is put back
# ---------------------------------------------------------------------------
PAGE="smoke-page-$RAND"
check 'admin GET /mark/pages' '/mark/pages' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
ADMIN_TOKEN="$(token)"

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/pages/create?locale=sk" \
    --data-urlencode "title=Smoke page $RAND" \
    --data-urlencode "slug=$PAGE" \
    --data-urlencode 'body=A page written by the admin smoke gate.' \
    --data-urlencode 'published=1' --data-urlencode 'locale=sk_SK' \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/pages/create' 302 "$got"

if find storage -name "$PAGE.md" | grep -q .; then ok 'the page file exists'; else bad 'no page file was written'; fi
check 'the published page is served' "/$PAGE" 200
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/pages/$PAGE/delete" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/pages/{slug}/delete' 302 "$got"
if find storage -name "$PAGE.md" | grep -q .; then bad 'the page file survived the delete'; else ok 'the page file is gone'; fi
check 'the deleted page is 404' "/$PAGE" 404

# ---------------------------------------------------------------------------
# Forms: a definition created in the panel, embedded in a page, submitted
# through the public endpoint, read back in the inbox, then removed again.
# ---------------------------------------------------------------------------
FORM="smoke-form-$RAND"
FPAGE="smoke-form-page-$RAND"

check 'admin GET /mark/forms' '/mark/forms' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
ADMIN_TOKEN="$(token)"

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/forms/create?locale=sk" \
    --data-urlencode "title=Smoke form $RAND" \
    --data-urlencode "slug=$FORM" \
    --data-urlencode 'intro=Written by the admin smoke gate.' \
    --data-urlencode 'published=1' \
    --data-urlencode 'locale=sk_SK' \
    --data-urlencode 'fields[0][key]=name' \
    --data-urlencode 'fields[0][label]=Your name' \
    --data-urlencode 'fields[0][type]=text' \
    --data-urlencode 'fields[0][required]=1' \
    --data-urlencode 'fields[0][max]=120' \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/forms/create' 302 "$got"

row="$(dbq DB_FORMS "SELECT COUNT(*) FROM forms WHERE slug = '$FORM'")"
want 'the form definition row exists' 1 "$row"

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/pages/create?locale=sk" \
    --data-urlencode "title=Form page $RAND" \
    --data-urlencode "slug=$FPAGE" \
    --data-urlencode 'body=Contact us below.' \
    --data-urlencode "form=$FORM" \
    --data-urlencode 'published=1' --data-urlencode 'locale=sk_SK' \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/pages/create with a form slug' 302 "$got"

# The reader's browser: its own jar, and the token the page itself rendered.
# The jar is on the RENDER too, so the token the reader posts belongs to the
# session that received it — a page fetched without its jar carries a token
# whose session cookie is gone, and CsrfMiddleware answers 403.
check 'the page with the embedded form is served' "/$FPAGE" 200 \
    -b "$TMP/public.jar" -c "$TMP/public.jar"
PUBLIC_TOKEN="$(token)"

if grep -q -- '-form__form' "$TMP/last.out"; then
    ok 'the page carries the theme-prefixed form markup'
else
    bad 'the form markup is missing from the page'
fi

if grep -q "Smoke form $RAND" "$TMP/last.out"; then
    ok 'the page carries the definition title'
else
    bad 'the definition title is missing from the page'
fi

got="$(status -c "$TMP/public.jar" -b "$TMP/public.jar" -X POST "$BASE/forms/$FORM" \
    --data-urlencode 'name=Smoke reader' \
    --data-urlencode "form_return=/$FPAGE" \
    --data-urlencode "_token=$PUBLIC_TOKEN" -H "Origin: $BASE")"
want 'public POST /forms/{slug}' 302 "$got"

stored="$(dbq DB_FORMS "SELECT COUNT(*) FROM form_submissions WHERE form_slug = '$FORM'")"
want 'the submission row is stored' 1 "$stored"

check 'the page with the confirmation is served' "/$FPAGE?form-sent=1" 200
if grep -q 'Thank you' "$TMP/last.out"; then
    ok 'the confirmation is painted after the redirect'
else
    bad 'the confirmation is missing after the redirect'
fi

check 'admin GET /mark/forms/{slug}/submissions' "/mark/forms/$FORM/submissions?locale=sk" 200 \
    -b "$ADMIN_JAR" -c "$ADMIN_JAR"
if grep -q 'Smoke reader' "$TMP/last.out"; then
    ok 'the inbox lists the submission'
else
    bad 'the submission is missing from the inbox'
fi

ADMIN_TOKEN="$(token)"
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/forms/$FORM/delete?locale=sk" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/forms/{slug}/delete' 302 "$got"

gone="$(dbq DB_FORMS "SELECT COUNT(*) FROM forms WHERE slug = '$FORM'")"
want 'the deleted definition row is gone' 0 "$gone"
left="$(dbq DB_FORMS "SELECT COUNT(*) FROM form_submissions WHERE form_slug = '$FORM'")"
want 'the deleted definition took its submissions' 0 "$left"

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/pages/$FPAGE/delete" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/pages/{slug}/delete (form page)' 302 "$got"
if find storage -name "$FPAGE.md" | grep -q .; then bad 'the form page file survived the delete'; else ok 'the form page file is gone'; fi

tree_files > "$TMP/tree-after.txt"
if diff -q "$TMP/tree-before.txt" "$TMP/tree-after.txt" > /dev/null; then
    ok 'the checkout storage came back exactly as it was'
else
    diff "$TMP/tree-before.txt" "$TMP/tree-after.txt" | head -10
    bad 'the content flows left files behind in the checkout storage'
fi

# ---------------------------------------------------------------------------
# The WORKER side. Two queues are exercised here: the outbox, whose drain runs
# at the end of every request above, and the Queue module's own worker over a
# job nobody can route.
# ---------------------------------------------------------------------------
# Delivery is the assertion, not the row count. Every security event recorded
# above has to have been consumed by the end-of-request drain, and the audit row
# is the proof: the handler that consumes exactly those events IS the audit
# writer (registered in the HTTP composition root, `src/http.php`).
pending="$(dbq DB_OUTBOX "SELECT COUNT(*) FROM outbox_messages WHERE status = 'pending'")"
want 'outbox messages left pending' 0 "$pending"

queued_failed="$(dbq DB_OUTBOX "SELECT COUNT(*) FROM outbox_messages WHERE status = 'failed'")"
want 'outbox messages failed' 0 "$queued_failed"

delivered="$(dbq DB_OUTBOX "SELECT COUNT(*) FROM outbox_messages WHERE status = 'processed' AND processed_at IS NOT NULL")"
want_at_least 'outbox messages delivered' 4 "$delivered"

audited="$(dbq DB_AUDIT "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'security.%'")"
want_at_least 'security events that became audit rows' 4 "$audited"

# The MIDDLEWARE refusal's delivery, asserted by name. The suite makes this claim
# against the classes; this is the same claim against a booted server, and the row
# has to name the path the author was refused.
middleware_row="$(dbq DB_AUDIT "SELECT COUNT(*) FROM audit_log WHERE action = 'security.authz.denied' AND target = '/mark/users'")"
want_at_least 'the refused /mark/users path became an audit row' 1 "$middleware_row"

# And nothing may sit under the bare name that no handler claims — the precise
# shape of the defect this section keeps closed. (Other event types are legitimate
# here: the content section publishes an article, so `blog.article.published`
# belongs in this table.)
unclaimed="$(dbq DB_OUTBOX "SELECT COUNT(*) FROM outbox_messages WHERE event_type = 'authz.denied'")"
want 'no event parked under a type no handler claims' 0 "$unclaimed"

# The operator's own worker command. The shipped timetable is stateless cron
# (`*/5` matches, at most, one minute in five), so what is asserted is that it
# runs clean against this queue and reports its outcome — the delivery itself is
# asserted above, where it is deterministic instead of minute-dependent.
#
# ORDERING IS LOAD-BEARING, and this is the line that has to say so. Since
# Boot 31 the timetable also carries `queue:work`
# (`SchedulerServices::withQueueWork`), so `schedule:run` DRAINS THE QUEUE. It
# is green below because nothing has been pushed yet — the first push is the
# unroutable job below, and it is worked explicitly so its exit code can be
# asserted separately. Move a push above this block and `schedule:run` starts
# exiting 1 on a poison job, and the gate fails here rather than where the push
# actually belongs.
if php bin/cms schedule:run > "$TMP/schedule.log" 2>&1; then
    ok 'bin/cms schedule:run exits 0'
else
    cat "$TMP/schedule.log"
    bad 'bin/cms schedule:run failed'
fi

if grep -q 'scheduled task' "$TMP/schedule.log"; then
    ok 'schedule:run reported its outcome'
else
    cat "$TMP/schedule.log"
    bad 'schedule:run printed no outcome line'
fi

# A handler that throws while attempts remain is a RETRY, not a failure. The
# worker must say so: the row goes back with a BACKOFF, so it drops out of the
# batch, and the run stays green. A worker that reddened every minute for a
# retry would be a worker whose "1" means nothing — and with the release writing
# `available_at = now` (no backoff) that was not hypothetical: one `queue:work`
# call burned all three attempts and parked the row.
RETRY_JOB="$(php <<'PHP'
<?php
require 'src/init.php';

echo \Modules\Queue\Interfaces\HTTP\QueueServices::queue()->push(
    'smoke.retryable',
    ['from' => 'admin-smoke'],
    maxAttempts: 3,
);
PHP
)"

if php bin/cms queue:work > "$TMP/queue-retry.log" 2>&1; then
    ok 'bin/cms queue:work exits 0 on a job it will retry'
else
    cat "$TMP/queue-retry.log"
    bad 'bin/cms queue:work reddened the run for a retry'
fi

retry_state="$(dbq DB_QUEUE "SELECT attempts || '/' || (reserved_at IS NULL) FROM jobs WHERE id = '$RETRY_JOB'")"
want 'the retried job is queued again, released, one attempt spent' '1/1' "$retry_state"

retry_future="$(dbq DB_QUEUE "SELECT (available_at > datetime('now')) FROM jobs WHERE id = '$RETRY_JOB'")"
want 'its next attempt is in the future' 1 "$retry_future"

# The queue's own operator command, on the path a cron takes: an empty queue
# must exit 0 and report zeroes. A worker that reddened every tick because it
# had nothing to do is a worker whose "1" means nothing, and this is the line
# that says so.
if php bin/cms queue:work > "$TMP/queue-empty.log" 2>&1; then
    ok 'bin/cms queue:work exits 0 on an empty queue'
else
    cat "$TMP/queue-empty.log"
    bad 'bin/cms queue:work failed on an empty queue'
fi

if grep -q 'processed=0 failed=0' "$TMP/queue-empty.log"; then
    ok 'bin/cms queue:work reports its counts'
else
    cat "$TMP/queue-empty.log"
    bad 'bin/cms queue:work printed no counts'
fi

# The Queue module's worker. This tree ships no producer (measured: `push()` has
# no call site outside the module's own tests), so the job is pushed through the
# module's own API — the way the seed block above builds its accounts — and then
# worked by the OPERATOR'S COMMAND, not by an inline heredoc: a gate that proves
# the worker by calling the module's API proves a worker nobody can put in
# crontab. The handler is deliberately unregistered, so the job travels the real
# failure path: UnknownHandlerException → `failed_jobs` with the error, where
# the panel retries it.
QUEUE_JOB="$(php <<'PHP'
<?php
require 'src/init.php';

echo \Modules\Queue\Interfaces\HTTP\QueueServices::queue()->push(
    'smoke.unregistered',
    ['from' => 'admin-smoke'],
    maxAttempts: 1,
);
PHP
)"

if php bin/cms queue:work > "$TMP/queue-work.log" 2>&1; then
    cat "$TMP/queue-work.log"
    bad 'bin/cms queue:work exited 0 with an unroutable job in the queue'
else
    ok 'bin/cms queue:work exits 1 on a parked job'
fi

if grep -q 'failed=1' "$TMP/queue-work.log"; then
    ok 'bin/cms queue:work counts the parked job'
else
    cat "$TMP/queue-work.log"
    bad 'bin/cms queue:work reported no failure for a job it could not route'
fi

parked="$(dbq DB_QUEUE "SELECT COUNT(*) FROM failed_jobs WHERE id = '$QUEUE_JOB'")"
want 'the unroutable job is parked in failed_jobs' 1 "$parked"

check 'admin GET /mark/queue' '/mark/queue' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
ADMIN_TOKEN="$(token)"

if grep -q 'smoke.unregistered' "$TMP/last.out"; then
    ok 'the panel lists the failed job'
else
    bad 'the panel does not list the failed job'
fi

# Retry is the reversible half: the row leaves failed_jobs and is queued again
# under its own id (failed_jobs carries no max_attempts, so a retried job gets
# the default — documented in DatabaseQueue, not hidden).
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/queue/failed/$QUEUE_JOB/retry" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/queue/failed/{id}/retry' 302 "$got"

requeued="$(dbq DB_QUEUE "SELECT COUNT(*) FROM jobs WHERE id = '$QUEUE_JOB'")"
want 'the retried job is queued again' 1 "$requeued"

left_failed="$(dbq DB_QUEUE "SELECT COUNT(*) FROM failed_jobs WHERE id = '$QUEUE_JOB'")"
want 'the retried job left failed_jobs' 0 "$left_failed"

# ---------------------------------------------------------------------------
# RETENTION — `queue:prune`, the command `failed_jobs` never had.
#
# Measured 2026-10-16: four parked rows spanning two hours to a year all
# survived a worker run, and DatabaseQueue had no prune/clean/purge/expire
# method at all — the only removal was an operator clicking "drop this job",
# so the table could only ever grow.
#
# The module tests cover `DatabaseQueue`, not this script. If the dispatch case
# in bin/cms went away, every one of them would still pass, which is why the
# operator's own command is asserted HERE rather than left to the suite.
# ---------------------------------------------------------------------------
OLD_JOB="smoke-stale-$RANDOM"
FRESH_JOB="smoke-fresh-$RANDOM"

# failed_at is the retention clock, so if this went wrong the backdated rows
# would become the OLDEST rows in the table and every count below would read as
# if retention had removed far more than it did. Emptied first, deliberately.
db_exec DB_QUEUE "DELETE FROM failed_jobs"

db_exec DB_QUEUE "INSERT INTO failed_jobs (id, queue, handler, payload, attempts, failed_at, error)
    VALUES ('$OLD_JOB', 'default', 'smoke.stale', '{}', 3, datetime('now', '-400 days'), 'smoke: backdated'),
           ('$FRESH_JOB', 'default', 'smoke.fresh', '{}', 3, datetime('now', '-2 hours'), 'smoke: recent')"

want 'two jobs are parked for the retention checks' 2 \
    "$(dbq DB_QUEUE "SELECT COUNT(*) FROM failed_jobs")"

if php bin/cms queue:prune --dry-run > "$TMP/prune-dry.log" 2>&1; then
    ok 'bin/cms queue:prune --dry-run exits 0'
else
    cat "$TMP/prune-dry.log"
    bad 'bin/cms queue:prune --dry-run failed'
fi

# The preview has to be honest about WHICH rows, or it is decoration: the fresh
# row is two hours old and must not appear in a 30-day prune.
if grep -q '1 failed job(s) older than 30 days would be removed' "$TMP/prune-dry.log"; then
    ok 'the preview counts only the stale job'
else
    cat "$TMP/prune-dry.log"
    bad 'the preview did not report exactly the stale job'
fi

want 'the dry run deleted nothing' 2 \
    "$(dbq DB_QUEUE "SELECT COUNT(*) FROM failed_jobs WHERE id IN ('$OLD_JOB', '$FRESH_JOB')")"

if php bin/cms queue:prune > "$TMP/prune.log" 2>&1; then
    ok 'bin/cms queue:prune exits 0'
else
    cat "$TMP/prune.log"
    bad 'bin/cms queue:prune failed'
fi

want 'the stale job is pruned' 0 \
    "$(dbq DB_QUEUE "SELECT COUNT(*) FROM failed_jobs WHERE id = '$OLD_JOB'")"

want 'the recent job is kept' 1 \
    "$(dbq DB_QUEUE "SELECT COUNT(*) FROM failed_jobs WHERE id = '$FRESH_JOB'")"

# A destructive command that empties an operator's work queue has to refuse a
# malformed interval rather than compare against "now" and remove everything.
if php bin/cms queue:prune --interval=" " > "$TMP/prune-bad.log" 2>&1; then
    bad 'queue:prune accepted an empty interval'
else
    ok 'queue:prune refuses an empty interval'
fi

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/queue/$QUEUE_JOB/delete" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/queue/{id}/delete' 302 "$got"

dropped="$(dbq DB_QUEUE "SELECT COUNT(*) FROM jobs WHERE id = '$QUEUE_JOB'")"
want 'the dropped job row is gone' 0 "$dropped"

# Both state changes are audit rows with an actor — the panel's write path, not
# just the row it moved.
queue_audited="$(dbq DB_AUDIT "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'queue.%'")"
want_at_least 'audit rows for the queue state changes' 2 "$queue_audited"

check 'admin GET /mark/outbox' '/mark/outbox' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"

# The freshness job is what the retention checks deliberately did NOT remove.
# Emptying it here is what makes the command's own output above falsifiable —
# an age wrongly stored would have pruned it and the "kept" assertion would
# never have run.
db_exec DB_QUEUE "DELETE FROM failed_jobs"
want 'the retention checks left no failed job behind' 0 \
    "$(dbq DB_QUEUE "SELECT COUNT(*) FROM failed_jobs")"

# ---------------------------------------------------------------------------
# Nothing fatal behind it all
# ---------------------------------------------------------------------------
if grep -E 'PHP Fatal|\[KERNEL\] Error|Uncaught' "$TMP/server.log" | head -5 | grep -q .; then
    grep -E 'PHP Fatal|\[KERNEL\] Error|Uncaught' "$TMP/server.log" | head -5
    bad "the server log carries a fatal"
else
    ok "no fatal / kernel error in the server log"
fi

echo
if [ "$FAILED" -eq 0 ]; then
    echo "admin-smoke: all checks passed"
else
    echo "admin-smoke: FAILURES present"
fi

exit "$FAILED"
