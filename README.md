# Nativa Core

**This tree is generated.** Every file here is exported from
[`responsive-sk/nativa-php-core`](https://github.com/responsive-sk/nativa-php-core)
at a recorded commit; `.nativa-core-export.json` names it. A hand edit here is
overwritten by the next export. Change the source repository instead.

## What it is

The kernel and the Nativa theme, with no site attached:

- `src/Modules/Core` and `src/Modules/Database`: routing, the request/response
  pair, middleware discovery, the migrator runner, the SQLite connection
  manager, the asset resolver, markdown rendering and the error pages.
- `src/Modules/Tooling`: the module catalogue and lifecycle that `bin/cms`
  drives.
- `src/Modules/KernelTheme` and `src/Themes/kernel`: the default presentation
  that needs no build.
- `modules/`: the catalogue — `Themes/Nativa`, `Settings` (the theme vote),
  `ContentLocale` (the locale mapping every theme asks for), `RestPanel` (the
  REST panel under `/mark/rest`) and the admin stack its closure needs:
  `Cardboard`, `Users`, `Auth`, `Locales`, `Navigation`, `Notifications`,
  `Outbox`, `PageCache`, `Pagination`, `Security`, `Uploads` and `Validation`.
  `Blog`, `Audit`, `Markdown`, `Media` and `Queue` carry `/blog` and the
  articles admin at `/mark/articles`. This is a catalogue, not a running set.
  `src/Modules/<Name>` (the installed
  copy) is gitignored. Docs is a module in the source repository and is pruned
  here: this tree carries no guides, and `/docs` answers 404.
- `Frontend/packages/`: the pnpm workspace that builds the Nativa bundle.
  `public/dist/nativa` is a committed frozen build; `make assets` rebuilds it.

The boundary rule is one sentence: a kernel file may not import a module, and a
module may import the kernel. `make mago-guard` fails on the other direction.

### Why this catalogue

The catalogue is a measurement, not a starting point. The source repository
built this tree without a catalogue and added back only what a surface proved
it needed. Every row is a module that could be added, with its measured cost:

| Surface | Modules | Cost | Without it |
|---|---|---|---|
| Nativa on `/` | `Settings`, `ContentLocale` | +16 files | `/` renders `KernelTheme` |
| Admin at `/mark` | `RestPanel`, `Cardboard`, `Users` + their closure | +311 files | 404 |
| Blog and `/mark/articles` | `Blog`, `Audit`, `Markdown`, `Media`, `Queue` + their closure | +156 files | 404 |
| `/cookies` | `Pages` | +18 files | 404 |

`Settings` is kept because the theme vote is a kernel port with no kernel
implementation. `ContentLocale` is kept because every theme asks it for the
locale-to-URL-prefix rule; it has no routes, tables or admin screen. The admin
stack is kept because `RestPanel` is a product surface this tree ships, and it
cannot install without `Cardboard` and `Users`. `Blog` is kept because
`/mark/articles` is the content admin surface, and its closure ships with it.
`Pages` remains a product surface the catalogue does not carry, so `/cookies`
answers 404. A module comes back only with a row here and a measurement behind
it.

## Run it

```sh
php -S 127.0.0.1:8000 -t public
```

`vendor/` is not needed. The runtime has zero Composer packages: `src/init.php`
installs a PSR-4 fallback loader, and `composer install` is development tooling
only. The first request, or `php bin/cms migrate`, builds the databases empty.
The panel is catalogued but not installed, so `/mark` answers 404 until you run
`make modules-install`; after that it redirects to `/login`, and
`php bin/cms user:operator` creates an administrator. `RestPanel` answers under
`/mark/rest`.

## Database engines

SQLite is the default: one file per module under `storage/data/`, and `DB_<NAME>`
(a module name in upper case) is that module's file path. `DB_DRIVER` in `.env`
switches the whole tree to a server; nothing else reads the driver.

The same choice can live in `storage/config/database.json`, which the Settings
panel writes (Settings → Database):

```json
{
  "driver": "pgsql",
  "dsn": "host=127.0.0.1;port=5432;dbname=nativa;user=nativa;password=secret",
  "prefix": "nativa_"
}
```

Precedence is the process environment, then `.env`, then the file: a deployment
that exports `DB_DRIVER` or `DB_DSN` always wins, so the file is the default for
the installation rather than an override. `driver` takes `sqlite`, `pgsql` or
`mariadb` (`mysql` means `mariadb`); an unknown key fails the boot instead of
being ignored, and `dsn` is refused for SQLite, which reads files. The file is
written atomically with mode `0600` because it holds a password, and it is
gitignored with the rest of `storage/`. A save applies on the next request —
the request that saved it already holds its connections — and
`php bin/cms migrate` builds the new target's tables (the panel refuses an
empty server only until the save is explicitly confirmed).

PostgreSQL uses one database for every module. Each module gets a schema named
after it, created and selected by the manager on first connection, so table
names and migrations stay unchanged:

```env
DB_DRIVER=pgsql
DB_DSN=host=127.0.0.1;port=5432;dbname=nativa;user=nativa;password=secret
```

MariaDB has no schema layer inside a database, so the isolation boundary is one
database per module. `DB_DSN` is the server DSN (no `dbname`); the manager
creates `<DB_DATABASE_PREFIX><module>` (default `nativa_`) on first connection.
Sessions are pinned to `time_zone = '+00:00'` and SERIALIZABLE transactions:

```env
DB_DRIVER=mariadb
DB_DSN=host=127.0.0.1;port=3306;user=nativa;password=secret
DB_DATABASE_PREFIX=nativa_
```

Either role needs `CREATE` (on the PostgreSQL database, or on the MariaDB
server), and `php bin/cms migrate` builds every schema or database. Switching
back is `DB_DRIVER=sqlite`, or removing the lines.

| Engine | What the role needs | `DB_DSN` | `db:export` / `db:import` |
| --- | --- | --- | --- |
| SQLite (default) | nothing — a file per module under `storage/data/`, `DB_<NAME>` overrides one path | not read | one entry per module, as a file each; import into a target with rows needs `--force` |
| PostgreSQL | `CREATE` on the shared database, and `dbname=` must already exist | `host=…;port=5432;dbname=nativa;user=…;password=…` | one entry per module, each connection seeing only its schema; the import runs the migrators first and needs `--force` to replace rows |
| MariaDB | `CREATE` on the server — the manager creates `<DB_DATABASE_PREFIX><module>` | server DSN, no `dbname` | one entry per module, same as PostgreSQL; the import leaves an empty append-only `audit_log` alone and needs `--force` to replace rows |

`make test` runs SQLite only: `tests/Postgres` and `tests/MariaDb` describe a
real server and are excluded from that suite. Their one-command run carries the
driver and DSN itself, migrates first, and treats a skip as a failure — a
missing server is a red run, not a green no-op. The MariaDB run also refuses a
target whose module databases already hold tables the migrators do not create,
so the prefix cannot quietly point at another application's namespace:

```sh
make test-postgres                                        # PG_DSN=… to point elsewhere
make test-mariadb MARIADB_DSN="unix_socket=/run/mysqld/mysqld.sock;user=root;password="
```

## Install a module

```sh
php bin/cms module:list                       # what the catalogue carries
php bin/cms module:add Settings               # copies the closure into src/Modules/
php bin/cms module:remove Settings --force    # removes it again
```

The catalogue carries the theme, the modules the theme needs and the admin
stack. A module that is not in `modules/` cannot be installed, and adding one
back is a decision with a measured cost — the register in `docs/` of the source
repository explains what each surface would cost. `make modules-install`
installs the whole catalogue in closure order.

`modules/Themes/<Name>` holds the theme modules. The installed copy keeps the
same grouped shape, `src/Modules/Themes/<Name>`, and the theme name the settings
store is the lower-case one.

## Build the themes

```sh
make assets     # pnpm workspace → public/dist/nativa
```

`make assets` needs pnpm and network access for the first install. A deployment
does not: the frozen bundle is committed.

## Update a deployment

An update replaces the CODE. It never replaces `storage/`, because that is where
the site is: `storage/pages/`, `storage/blog/`, `storage/blog-images/`,
`storage/uploads/` and one database file per module under `storage/data/`. The
artifact ships a migrated `storage/` skeleton for a first install, so copying it
over a running site deletes the content and leaves a database-shaped shell. That
is not hypothetical: it is what made a rebuilt site look deleted on 2026-10-17.

```sh
# 1. A release is a directory with a provenance stamp.
bin/build/build-dist.sh --skip-assets --to releases/2026-11-19
cat releases/2026-11-19/.build-info     # VERSION, COMMIT, VENDOR=absent

# 2. Snapshot first, on the server, before anything moves.
php bin/cms db:export storage/backups/2026-11-19
tar czf storage/backups/2026-11-19-files.tgz \
    storage/pages storage/blog storage/blog-images storage/uploads

# 3. Optionally take the site out of service (/mark → Settings → maintenance_mode).

# 4. Code only — rsync with an exclude, never `cp -R`.
rsync -a --delete --exclude 'storage/' --exclude '.build-info' \
    releases/2026-11-19/ /var/www/site/
install -m 644 releases/2026-11-19/.build-info /var/www/site/.build-info

# 5. Schema on the target (idempotent; the first request would run it anyway).
php bin/cms migrate
```

Then hand `storage/` to the user the web server runs as (`chgrp -R www-data
storage`, or `nginx`, `_www`): an rsync user that owns it and a SAPI user that
cannot write it is a 500 on every request, or a 503 once the re-migration it
triggers hits the timeout.

Verify the update, not just the upload: `/health` answers 200 with every probe
healthy, `/` answers 200, and the file counts under `storage/pages` and
`storage/uploads` are the same as before the rsync. A count that dropped is a
copy that included `storage/`.

**`settings:profile` is not part of an update.** `config/deploy.php` describes
what a BUILD starts as; run against a running site it would write the build's
`site_name`, `theme`, `site_description` and `timezone` over what the operator
set in `/mark` → Settings. It belongs to `bin/build/build-dist.sh`, which runs it
inside the artifact before the site exists.

Rollback is the previous release directory plus the snapshot: rsync the older
`releases/<date>/` back the same way, then
`php bin/cms db:import storage/backups/<date> --force` if the schema moved. Keep
release directories (they are gitignored, like `dist/`) rather than rebuilding
one on demand.

## Gates

```sh
make gates                # every pin, gate and CI job, from toolchain.json
make lint                 # every PHP file parses
composer phpstan          # types over the kernel scope
make test                 # PHPUnit: tests/ plus modules/*/tests/
make mago-guard           # the kernel-to-module dependency rule
make mago-lint            # curated lint rules, ceiling zero
make modules-install      # every catalogued module installs in closure order
make dist-check           # a clean clone builds a green artifact
make dist-modules-check  # clean clone + modules-install + module-set artifact
```

`make core-export` and `make lekalo-check` do not exist
here. They guard the export itself and the source repository's own
architectural model; the export carries neither.

The suite carries no test whose subject is absent. Tests of the source
repository's approval records, its Lekalo model, its article corpus or a module
outside the catalogue are not copied here at all; the source's
`bin/export/core/phpunit.exclude` names each one with its reason.

## License

MIT. See `LICENSE`.
