#!/bin/bash
#
# =============================================================================
# build-dist.sh — a deployable tree in dist/, with no vendor/ at all.
# =============================================================================
# Same job as upstream's `bin/build/build-dist.sh` (an artifact you can upload,
# not a git checkout you have to prune on the server), with three differences
# that are the kernel's, not a style choice:
#
#   1. `bin/cms` STAYS. Upstream deletes its console from the dist because the
#      console extends symfony/console, a dev dependency. Here it is the
#      production tool — `module:add/remove`, `migrate` — and it is plain PHP by
#      design, so a dist that drops it cannot manage its own modules.
#   2. The frontend is NOT rebuilt here. The pnpm workspace is committed and
#      `make assets` is the one build path, so this script delegates to it
#      rather than keeping a second copy of the pnpm invocation. `--skip-assets`
#      uses whatever is already in `public/dist/` and refuses to guess.
#   3. Databases are MIGRATED, never seeded or copied. The kernel ships no demo
#      data — it has no seeders at all — and the developer's own `storage/data/`
#      is local state that must not leak into an artifact. `php bin/cms migrate`
#      runs INSIDE the dist, so the artifact leaves here with a real schema and
#      with proof that it boots without `vendor/`. What a migrated artifact does
#      NOT have is the SITE: measured 2026-10-17, a rebuilt responsive.sk dist
#      came up on `theme=kernel` / `site_name=Nativa PHP Core`, and its copy
#      looked deleted. `config/deploy.php` is the missing half — a committed
#      profile applied with `bin/cms settings:profile` right after `migrate`, so
#      a build cannot silently rebrand a deployment. `--no-profile` ships the
#      kernel defaults on purpose, and a deployment changes anything afterwards
#      in /mark → Settings. A KERNEL-ONLY checkout has the profile file but no
#      Settings module to apply it with; that build keeps the kernel defaults and
#      says so, rather than failing a documented shape of this tree.
#
# Usage:
#   bin/build/build-dist.sh                    # assets if missing → assemble → migrate → smoke
#   bin/build/build-dist.sh --skip-assets      # use the existing public/dist
#   bin/build/build-dist.sh --skip-migrate     # ship the storage skeleton, no databases
#   bin/build/build-dist.sh --no-profile       # keep the kernel defaults (no config/deploy.php applied)
#   bin/build/build-dist.sh --skip-smoke       # skip the HTTP smoke test
#   bin/build/build-dist.sh --with-env         # copy .env.example to dist/.env
#   bin/build/build-dist.sh --with-catalogue  # also ship modules/ (module:add works in the artifact)
#   bin/build/build-dist.sh --to <dir>         # write somewhere else (default: dist/)
#
# On `modules/` — and why the artifact carries the name "Modules" ONCE.
#
#   modules/<Name>/         the CATALOGUE: slices waiting to be installed
#   src/Modules/<Name>/     the INSTALLED copies: what actually runs (a
#                           grouped module lives at src/Modules/<Group>/<Name>/)
#
# Both live in the repo; a deployment needs only the second one. Measured in
# this tree: 12 of the 14 installed slices are BYTE-IDENTICAL to their catalogue
# counterpart — 815 KiB, shipped twice, in a 5.3 MB artifact. The first look at
# a dist built that way asks the same question it asked here ("modules twice?"),
# and the honest answer was "yes, on purpose" the moment the catalogue was
# wanted for `module:add` on the target. It is not wanted by default: a
# deployment runs what is installed, and installing happens in the repo (or
# through `--with-catalogue`, which restores the ability and reports the
# duplication it brings — identical / drifted / not installed, per slice).
#
# No dependencies beyond bash + coreutils + tar: like `bin/cms` and
# `extract-kernel.sh`, what builds the kernel must run where the kernel's own
# `vendor/` may not exist.
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DIST_DIR="$ROOT/dist"
SMOKE_PORT="${SMOKE_PORT:-8137}"
SMOKE_HOST="127.0.0.1"
BUILD_TIME="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
VERSION="$(git -C "$ROOT" describe --tags --always --dirty 2>/dev/null || echo 'dev')"
COMMIT="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo 'unknown')"

SKIP_ASSETS=false
PROFILE_APPLIED=false
SKIP_MIGRATE=false
SKIP_SMOKE=false
WITH_ENV=false
WITH_CATALOGUE=false
APPLY_PROFILE=true

# Paths tar must not carry. Deliberately a deny-list with a reason per line,
# because each of these would be a bug in the artifact rather than clutter:
#
#   ./dist             the output directory itself
#   ./.git ./.github   history and CI are not deployable
#   ./tests             the harness is not the application
#   ./fixtures          the theme-gallery inputs, read by
#                       `bin/build/render-theme-pages.php` — which is itself
#                       excluded below, so nothing in the artifact reads them;
#                       `public/demo/`, when present, keeps the images a
#                       deployment serves
#   ./Frontend          theme SOURCES: the artifact ships built bundles only
#   ./vendor            the kernel runtime has zero Composer packages — this is
#                       the claim the smoke test below is about to check
#   ./bin/build         the tools that BUILT this artifact, including itself
#   dev tool configs    phpstan/phpunit/rector/cs-fixer/mago/toolchain/Makefile/composer.*
#                       say how to develop and CHECK here, and shipping them
#                       invites someone on the server to run `composer install`
#                       against a tree that does not need it. `mago.toml` is in
#                       that list for the same reason: nothing outside the dev
#                       tooling reads it, and the artifact ships how the tree
#                       RUNS, not how it is measured.
#   .env and friends    local secrets. NOTE: `.env.*` also matches the
#                       `.env.example` this artifact DOES need — it arrives via
#                       the explicit copy below, not through this tar pass.
#   ./storage/data|logs|cache|sessions|uploads
#                       runtime state (SQLite files, sessions, article views).
#                       The skeleton is recreated below; the databases are
#                       rebuilt by migrate inside the dist.
#   ./.lekalo ./.freebuff  local tool caches
#   ./.agents ./.ai-factory ./.ai-factory.json ./openspec
#                       agent tooling and the plan/spec tree: plans,
#                       references, requirements and the factory config are how
#                       this tree is BUILT, not how it runs. The factory runtime
#                       and the skills are gitignored, `openspec/specs/` is
#                       tracked, and both are excluded here on the `mago.toml`
#                       argument — nothing in the artifact reads them
#   ./lekalo* ./.gitignore  the Lekalo IR model and the repo's ignore rules are
#                       how this tree is DEVELOPED, not how it runs
EXCLUDES=(
    "./dist"
    "./.git"
    "./.github"
    "./tests"
    "./fixtures"
    "./Frontend"
    "./vendor"
    "./bin/build"
    "./.env"
    "./.env.*"
    "./.lekalo"
    "./.freebuff"
    "./.agents"
    "./.ai-factory"
    "./.ai-factory.json"
    "./openspec"
    "./lekalo"
    "./lekalo.lock"
    "./.gitignore"
    "./.php-cs-fixer.cache"
    "./.php-cs-fixer.dist.php"
    "./mago.toml"
    "./toolchain.json"
    "./.phpunit.result.cache"
    "./.rector.cache"
    "./Makefile"
    "./composer.json"
    "./composer.lock"
    "./phpstan.neon"
    "./phpunit.xml"
    "./rector.php"
    "./storage/data"
    "./storage/logs"
    "./storage/cache"
    "./storage/sessions"
    "./storage/uploads"
)

# The runtime skeleton a fresh deployment needs (the same five directories
# `extract-kernel.sh` calls a structural part of the tree).
STORAGE_DIRS=(
    "data"
    "logs"
    "cache"
    "sessions"
    "uploads"
)

# Content directories that are CONTENT, not state: an article written in the
# studio has to arrive on the server, and a cover image its front matter points
# at has to resolve. Copied wholesale when present, like upstream does.
CONTENT_DIRS=(
    "blog"
    "blog-images"
    "pages"
)

usage() {
    awk 'NR>1 && /^set -euo pipefail$/{exit} NR>1{print}' "$0" | sed 's/^# \?//'
}

fail() {
    # $1 is the message and $2 the exit code — `$*` would print both, which
    # shipped a stray " 2" at the end of every refusal until it was read back.
    echo "build-dist: $1" >&2
    exit "${2:-1}"
}

step() {
    echo "==> $*"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --skip-assets)  SKIP_ASSETS=true ;;
        --skip-migrate) SKIP_MIGRATE=true ;;
        --skip-smoke)   SKIP_SMOKE=true ;;
        --with-env)     WITH_ENV=true ;;
        --with-catalogue) WITH_CATALOGUE=true ;;
        --no-profile)   APPLY_PROFILE=false ;;
        --to)           DIST_DIR="${2:-}"; [ -n "$DIST_DIR" ] || fail "--to needs a directory"; shift ;;
        --help|-h)      usage; exit 0 ;;
        *) fail "unknown argument: $1 (see --help)" ;;
    esac
    shift
done

# --- Assets ------------------------------------------------------------------
# `make assets` is the only build path (pnpm workspace under Frontend/packages).
# Its output is gitignored by design, so `--skip-assets` on a tree that has
# never built is not "use the previous build", it is a dist with no stylesheet
# — reported rather than produced.
if [ "$SKIP_ASSETS" = true ]; then
    # ANY built bundle is the signal, not one named bundle: the guard used to
    # look at moonshine's manifest alone, so a tree that had built only the
    # two frozen public bundles (mono, griffe) was refused while a tree with
    # nothing but the admin bundle passed. What the refusal protects is
    # "never built" — no manifest anywhere.
    ls "$ROOT"/public/dist/*/manifest.json >/dev/null 2>&1 \
        || fail "--skip-assets needs a built public/dist (run: make assets)" 2
    step "Using the existing public/dist (--skip-assets)"
else
    step "Building the theme bundles (make assets)"
    command -v pnpm >/dev/null 2>&1 \
        || fail "pnpm not found — install it, or build elsewhere and use --skip-assets" 2
    (cd "$ROOT" && make assets)
fi

# --- Refuse to write into a foreign non-empty directory ----------------------
# `dist/` itself is a build output and is replaced wholesale; any OTHER target
# must be empty, because deleting someone's directory is not this script's job.
if [ "$DIST_DIR" != "$ROOT/dist" ] && [ -e "$DIST_DIR" ] && [ -n "$(ls -A "$DIST_DIR" 2>/dev/null)" ]; then
    fail "refusing to write into non-empty dir: $DIST_DIR (only ./dist is replaced automatically)" 2
fi

step "Cleaning $DIST_DIR"
rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

# --- Assemble ----------------------------------------------------------------
# tar rather than rsync: it is already required by nothing else here, and its
# --exclude semantics are the ones the list above is written against. Paths are
# relative to the repo root so the exclude patterns read like the tree.
step "Copying the tree (without: history, tests, Frontend sources, vendor, dev configs)"
tar_args=( -C "$ROOT" )
for path in "${EXCLUDES[@]}"; do
    tar_args+=( --exclude="$path" )
done
[ "$WITH_CATALOGUE" = false ] && tar_args+=( --exclude="./modules" )

( cd "$ROOT" && tar "${tar_args[@]}" -cf - . ) | ( cd "$DIST_DIR" && tar -xf - )

# --- The two Modules trees ---------------------------------------------------
# Reported, not assumed: how much of the catalogue is a second copy of what is
# already running, and how many slices are installed but no longer match it.
if [ "$WITH_CATALOGUE" = true ]; then
    step "Catalogue in the artifact (module:add works here)"
    identical=0
    drifted=0
    uninstalled=0
    # Names come from the artifact's own CLI, not a `modules/*/` glob: the
    # catalogue groups themes under `modules/Themes/`, and a glob would count
    # `Themes` as a slice that is "not installed" while skipping its four
    # members. `find -maxdepth 2` locates either shape without naming a group.
    while IFS= read -r name; do
        [ -n "$name" ] || continue
        slice="$(find "$DIST_DIR/modules" -mindepth 1 -maxdepth 2 -type d -name "$name" -print -quit)"
        installed="$(find "$DIST_DIR/src/Modules" -mindepth 1 -maxdepth 3 -type d -name "$name" -print -quit)"
        if [ -z "$installed" ] || [ ! -d "$installed" ]; then
            uninstalled=$((uninstalled + 1))
        elif [ -n "$slice" ] && diff -rq "$slice" "$installed" >/dev/null 2>&1; then
            identical=$((identical + 1))
        else
            drifted=$((drifted + 1))
            echo "    note: $name — installed copy DIFFERS from the catalogue"
        fi
    done < <(php "$DIST_DIR/bin/cms" module:list | sed -n 's/^Catalogued ([0-9]*): //p' | tr ',' '\n' | sed 's/^ *//;s/ *$//')
    echo "    $identical slice(s) byte-identical to the installed copy (a second copy of what runs)"
    echo "    $drifted drifted, $uninstalled not installed"
else
    step "Catalogue left out (the default): the artifact carries Modules once — src/Modules/"
    echo "    module:add is unavailable here by design; rebuild --with-catalogue to ship it"
fi

# --- Storage skeleton + content ---------------------------------------------
step "Creating the storage skeleton"
for dir in "${STORAGE_DIRS[@]}"; do
    mkdir -p "$DIST_DIR/storage/$dir"
done

for dir in "${CONTENT_DIRS[@]}"; do
    if [ -d "$ROOT/storage/$dir" ]; then
        mkdir -p "$DIST_DIR/storage/$dir"
        cp -R "$ROOT/storage/$dir/." "$DIST_DIR/storage/$dir/"
        count="$(find "$ROOT/storage/$dir" -type f | wc -l | tr -d ' ')"
        step "  storage/$dir/: $count content file(s) copied"
    fi
done

# --- Environment -------------------------------------------------------------
step "Writing the environment template"
cp "$ROOT/.env.example" "$DIST_DIR/.env.example"
if [ "$WITH_ENV" = true ]; then
    cp "$ROOT/.env.example" "$DIST_DIR/.env"
    echo "    .env written from .env.example — set APP_KEY and the database paths there"
fi

# --- Migrate inside the dist -------------------------------------------------
if [ "$SKIP_MIGRATE" = true ]; then
    step "Skipping migrate (--skip-migrate): the dist arrives with an empty schema"
else
    step "Migrating the dist (php bin/cms migrate — outside the source tree, on purpose)"
    # The binary is selected BY PATH, not by cd: APP_BASE_PATH is
    # `dirname(__DIR__)` from src/init.php, so the databases this creates belong
    # to the artifact and never to the developer's storage/data/, whatever the
    # working directory happens to be.
    if ! php "$DIST_DIR/bin/cms" migrate; then
        fail "migrate failed inside the dist — the artifact would ship without a schema" 1
    fi
fi

# --- Deployment identity ------------------------------------------------------
# Migrate seeds the KERNEL defaults (theme=kernel, site_name=Nativa PHP Core) and
# nothing copies the developer's config.db, so without this step every artifact
# is born as the kernel's own site. The profile names what it starts as; the
# settings it writes are still the operator's to change in /mark → Settings.
if [ "$SKIP_MIGRATE" = true ]; then
    step "Skipping the deployment profile (--skip-migrate): there is no settings table to write"
elif [ "$APPLY_PROFILE" = false ]; then
    step "Skipping the deployment profile (--no-profile): the artifact keeps the kernel defaults"
elif [ -f "$DIST_DIR/config/deploy.php" ] && [ ! -d "$DIST_DIR/src/Modules/Settings" ]; then
    # A kernel-only checkout is a supported build shape and `config/deploy.php`
    # is committed, so the file is there while the module that writes settings
    # is not. There is nothing to apply the profile TO, and failing here would
    # make `git clone && bin/build/build-dist.sh` impossible while changing
    # nothing about the artifact — it would still boot as the kernel's own site.
    # The step says which identity it ships, so the skip is never silent.
    step "Skipping the deployment profile: the Settings module is not installed — the artifact keeps the kernel defaults"
elif [ -f "$DIST_DIR/config/deploy.php" ]; then
    step "Applying the deployment profile (config/deploy.php)"
    # The path is absolute and the binary is selected BY PATH, so this writes the
    # ARTIFACT's config.db whatever the working directory is — the same rule the
    # migrate step above keeps. A refusal here is fatal: a build that prints the
    # site's name while shipping the kernel theme is worse than a failed build.
    if ! php "$DIST_DIR/bin/cms" settings:profile --file="$DIST_DIR/config/deploy.php"; then
        fail "the deployment profile could not be applied — the artifact would ship with the kernel defaults" 1
    fi

    PROFILE_APPLIED=true
else
    step "No config/deploy.php — the artifact keeps the kernel defaults"
fi

# --- Build metadata ----------------------------------------------------------
step "Writing build metadata"
{
    echo "BUILD_TIME=$BUILD_TIME"
    echo "VERSION=$VERSION"
    echo "COMMIT=$COMMIT"
    echo "VENDOR=absent"
} > "$DIST_DIR/.build-info"

# --- Smoke test --------------------------------------------------------------
# Boots the ARTIFACT (not the source tree) with `php -S` and checks the kernel
# surfaces over HTTP. opcache is disabled on purpose: measured on a long-lived
# CLI server, it serves code that no longer exists.
if [ "$SKIP_SMOKE" = true ]; then
    step "Skipping the smoke test (--skip-smoke)"
else
    step "Smoke-testing the artifact (php -S on $SMOKE_HOST:$SMOKE_PORT)"

    if [ -d "$DIST_DIR/vendor" ]; then
        fail "vendor/ exists in the dist — the zero-dependency claim is untested here" 1
    fi
    echo "    no vendor/ in the artifact"

    # -t points the docroot at the ARTIFACT's public/, and the app root follows
    # from public/index.php's own location, so no cd is needed and the PID below
    # is the server itself rather than a subshell around it.
    php -d opcache.enable_cli=0 -S "$SMOKE_HOST:$SMOKE_PORT" -t "$DIST_DIR/public" > /tmp/build-dist-server.log 2>&1 &
    server_pid=$!
    trap 'kill "${server_pid:-0}" 2>/dev/null || true' EXIT

    ready=false
    for _ in $(seq 1 40); do
        if curl -fsS -o /dev/null "http://$SMOKE_HOST:$SMOKE_PORT/" 2>/dev/null; then
            ready=true
            break
        fi
        sleep 0.25
    done

    if [ "$ready" != true ]; then
        echo "---- server log ----" >&2
        tail -20 /tmp/build-dist-server.log >&2 || true
        fail "the artifact never answered on http://$SMOKE_HOST:$SMOKE_PORT/" 1
    fi

    check() {
        local got
        got="$(curl -s -o /dev/null -w '%{http_code}' "http://$SMOKE_HOST:$SMOKE_PORT$1")"
        if [ "$got" != "$2" ]; then
            echo "::error::GET $1 expected $2, got $got" >&2
            return 1
        fi
        echo "    ok  $1 → $got"
    }

    # The kernel's own surfaces. `/mark` is NOT asserted: the dist carries the
    # modules that are installed in this tree, and whether the admin answers
    # 404 or redirects depends on which of them are — printed, not claimed.
    check /            200
    check /sitemap.xml 200
    check /robots.txt  200
    check /docs        200
    check /nope        404

    # Static art a page DEPENDS on, not just the routes. `favicon.svg` is linked
    # by every shipped layout, so a build that dropped public/ icons would still
    # answer 200 on every route above and render a broken tab. The demo frames
    # are checked only when the tree carries them: they are Mono's home-slider
    # photography, and a kernel-only export does not ship that theme.
    check /favicon.svg 200

    if [ -d "$DIST_DIR/public/demo" ]; then
        for frame in hero-1 hero-2 hero-3; do
            check "/demo/$frame.jpg" 200
        done
    fi

    mark_status="$(curl -s -o /dev/null -w '%{http_code}' "http://$SMOKE_HOST:$SMOKE_PORT/mark")"
    echo "    --  /mark → $mark_status (depends on the installed modules; not asserted)"

    # /health is asserted only when the artifact carries the Health module, for
    # the same reason /mark is not: a clean clone builds with the kernel's own
    # slices only. When it IS there, the STATUS CODE is not the assertion — the
    # BODY is, because an empty registry and a degraded tree both answer 200 by
    # design (nothing registered is not "broken", and an unfinished tree is not
    # an outage), so 200 alone cannot tell "every probe passed" from "every
    # probe silently disappeared". The body must therefore say `healthy` at the
    # TOP level, list at least one check, and carry a healthy entry for every
    # probe the module owns — a substring match would also accept the nested
    # `"status":"healthy"` of one passing probe under a degraded top level.
    #
    # This is also the only place the three probes meet the ARTIFACT's own
    # storage: they open its migrated databases, write into its storage/cache
    # and parse its public/dist manifests. A build that shipped the slice but
    # dropped `public/dist` or failed to migrate answers 200 with a DEGRADED
    # body — the state that would otherwise have reached a server unstyled —
    # and this assertion turns exactly that into a failed build.
    if [ -d "$DIST_DIR/src/Modules/Health" ]; then
        health_response="$(curl -s -w '\n%{http_code}' "http://$SMOKE_HOST:$SMOKE_PORT/health")"
        health_status="${health_response##*$'\n'}"
        health_body="${health_response%$'\n'*}"

        if [ "$health_status" != "200" ]; then
            echo "$health_body" >&2
            fail "GET /health expected 200, got $health_status"
        fi

        if ! printf '%s' "$health_body" | php -r '
            $payload = json_decode(stream_get_contents(STDIN), true);

            if (!is_array($payload)) {
                fwrite(STDERR, "body is not JSON\n");
                exit(1);
            }

            if (($payload["status"] ?? null) !== "healthy") {
                fwrite(STDERR, "top-level status is " . ($payload["status"] ?? "(missing)") . "\n");
                exit(1);
            }

            $checks = $payload["checks"] ?? [];

            if (!is_array($checks) || [] === $checks) {
                fwrite(STDERR, "checks is empty — no probe ran\n");
                exit(1);
            }

            $names = [];

            foreach ($checks as $check) {
                if (($check["status"] ?? null) !== "healthy") {
                    fwrite(STDERR, ($check["name"] ?? "?") . " is " . ($check["status"] ?? "(missing)") . "\n");
                    exit(1);
                }

                $names[] = $check["name"] ?? "";
            }

            $missing = array_diff(["database", "filesystem", "dist"], $names);

            if ([] !== $missing) {
                fwrite(STDERR, "missing probe(s): " . implode(", ", $missing) . "\n");
                exit(1);
            }
        '; then
            echo "$health_body" >&2
            fail "GET /health answered 200 but its body does not prove every probe passed"
        fi

        echo "    ok  /health → $health_status, every probe healthy"
    else
        echo "    --  /health → not installed (the artifact carries no Health slice; not asserted)"
    fi

    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
    trap - EXIT

    if grep -qE 'PHP Fatal error|\[KERNEL\] Error' "$DIST_DIR/storage/logs/php-error.log" 2>/dev/null; then
        cat "$DIST_DIR/storage/logs/php-error.log" >&2
        fail "the smoke test wrote a fatal error to storage/logs/php-error.log" 1
    fi
    echo "    error log is clean"
fi

# --- Runtime state -----------------------------------------------------------
# The artifact ships a storage SKELETON: the five directories, and the databases
# migrate built inside them. Everything else under storage/ was produced by the
# steps above, on THIS machine, and would be inherited by a host that has its
# own answer. Two of them are not cosmetic:
#
#   storage/cache/.migrations-applied — the bootstrapper's "the migrators have
#     already run" marker. It is a claim about the DATABASES, and it was written
#     against the ones this script just migrated. A deployment that opens OTHER
#     databases — `DB_*` in the host's .env pointing at a volume, a storage/data
#     the deploy does not populate — then finds the marker, SKIPS the migration
#     that would create its schema, and the first request dies on the first
#     query: `PDOException: no such table: settings`, 500 on `GET /`. Measured
#     both ways: the artifact with DB_* on an empty directory is a 500, and the
#     very same artifact with the marker removed is a 200 (the first request
#     re-runs migrate and writes the marker where it belongs).
#   storage/cache/pages/ + storage/logs/ — HTML the smoke rendered here and the
#     build's own log lines. Runtime state a host should not inherit, and
#     neither is a schema the host needs.
step "Purging runtime state (the databases stay; caches, sessions and logs do not)"
for dir in cache logs sessions; do
    find "$DIST_DIR/storage/$dir" -mindepth 1 -delete
    echo "    storage/$dir/: emptied"
done

# --- Permissions -------------------------------------------------------------
step "Setting permissions"
find "$DIST_DIR" -type d -exec chmod 755 {} \;
find "$DIST_DIR" -type f -exec chmod 644 {} \;
# bin/ is the executable half of the artifact and the blanket 644 above just
# stripped it: bin/cms manages modules on the server, bin/git-hooks/* are
# hooks a checkout installs. Restoring one file (the old shape) left the hooks
# non-executable — every file under bin/ is a program by definition.
find "$DIST_DIR/bin" -type f -exec chmod 755 {} \;

# storage/ must be WRITABLE BY THE WEB SERVER, and the blanket 755 above is
# what made the deployed artifact 500: a deploy runs as one user (rsync as
# root, FTP, a CI account) while php-fpm/www-data serves as another, and 755
# gives the SAPI no write access. Measured both ways on this artifact:
#
#   storage/ unwritable  → `PDOException: [HY000] [14] unable to open database
#     file` on the first query — a 500 that reads like a broken build.
#   storage/cache/ alone unwritable → the DDL succeeds, the migration marker
#     does not, and the whole ten-database migration re-runs on EVERY request.
#     Per-request DDL is what a 500 becomes a 502/503 once php-fpm's request
#     timeout is in play.
#
# 775 matches `AppPaths::ensureDir()`, which has always created directories at
# 0o775 — this step was silently overriding the kernel's own convention. It
# still does not know the deploy's group, so the deploy line below carries the
# chgrp; the group bit is what makes that a one-liner instead of a chown -R.
find "$DIST_DIR/storage" -type d -exec chmod 775 {} \;
find "$DIST_DIR/storage" -type f -exec chmod 664 {} \;

# --- Summary -----------------------------------------------------------------
size="$(du -sh "$DIST_DIR" | cut -f1)"
files="$(find "$DIST_DIR" -type f | wc -l | tr -d ' ')"
echo
echo "============================================================================="
echo "  Nativa PHP Core — dist built"
echo "============================================================================="
echo "  Version:    $VERSION ($COMMIT)"
echo "  Build time: $BUILD_TIME"
echo "  Output:     $DIST_DIR  ($size, $files files)"
echo "  vendor/:    absent — the runtime has zero Composer packages"
if [ "$PROFILE_APPLIED" = true ]; then
    echo "  identity:   config/deploy.php applied (theme, site name — see the step above)"
elif [ "$APPLY_PROFILE" = true ] && [ -f "$DIST_DIR/config/deploy.php" ] && [ "$SKIP_MIGRATE" = false ] && [ ! -d "$DIST_DIR/src/Modules/Settings" ]; then
    echo "  identity:   kernel defaults (no Settings module to apply config/deploy.php)"
else
    echo "  identity:   kernel defaults (theme=kernel, site_name=Nativa PHP Core)"
fi
if [ "$WITH_CATALOGUE" = true ]; then
    echo "  modules/:   catalogue INCLUDED (--with-catalogue) — slices exist twice, see above"
else
    echo "  modules/:   not shipped — the artifact runs what is installed; no second copy"
fi
echo ""
echo "  Deploy with:"
echo "    rsync -az --delete $DIST_DIR/ user@host:/var/www/nativa-php-core/"
echo "    (every file is a real copy — no symlinks, so a plain FTP upload works)"
echo ""
echo "  THEN hand storage/ to the web server — the artifact ships 775 for this,"
echo "  the rsync user is not the SAPI user, and a storage/ the SAPI cannot"
echo "  write is a 500 on every request (or a 503, once the per-request"
echo "  re-migration it triggers hits the request timeout):"
echo "    chgrp -R www-data /var/www/nativa-php-core/storage"
echo "    (use 'nginx' or '_www' — whatever your php-fpm pool runs as)"
echo "============================================================================="
