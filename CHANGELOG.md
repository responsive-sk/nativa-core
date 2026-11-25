# Changelog

Notable changes to this tree, newest first. A round that leaves something open
says so here too, because a changelog that only lists what worked is a brochure.

## Unreleased

### The hero title's two lines stop touching

The landing's `.site-hero__title` is a flex column of two rows, and its
`line-height: .94` left less leading than the first row's descenders need, so
the second line sat in the first one's tails. It is `1.04` now — the gap the
font actually needs, not the tightest value that still looked deliberate at one
window width. `SiteHomepageTest` renders the page in a built theme and does not
measure type, so nothing in the suite could see it; the check is the page.

The first build that carried the fix did not serve it: `build-dist.sh` assembles
the artifact from the **installed** slice (`src/Modules/Site`), which is
gitignored operator state, and editing `modules/Site` does not touch it. The
artifact kept `.94` while the tree was clean and the version carried no `-dirty`
marker, which is exactly how a stale copy hides. Reinstalling the slice
(`php bin/cms module:remove Site --force`, `php bin/cms module:add Site`) and
rebuilding put `1.04` in `dist/src/Modules/Site/…` and in the served page.
Anyone editing a landing surface should reinstall before believing an artifact.

### The diagram asks mermaid 12 for what it still has

`pnpm typecheck` failed on `flowchart: { defaultRenderer: 'dagre-wrapper' }`:
mermaid 12 dropped that key from its types, and the key was redundant anyway,
since one renderer remains. It is gone; `layout: 'dagre'` and
`securityLevel: 'strict'` stay. The rebuilt bundle contains no
`defaultRenderer`, and the landing still renders its diagram — the artifact
smoke checks for the figure, not for the bundle's internals.

### The SQLite default is migrated once, for every job

Module tests pin SQLite and read the databases other modules own, while the
PostgreSQL and MariaDB jobs point `DB_DSN` at the real server: their
`php bin/cms migrate` built that schema and left `storage/data/*.db` untouched,
so the first render inside a test died on `no such table: questions` — and
`settings`. One script, `bin/build/migrate-sqlite-defaults.sh`, now drops
`DB_DSN` and `DB_DRIVER` explicitly, migrates, and asserts the thirteen files
the catalogue writes: audit, blog, comments, config, forms, locales, media,
notifications, outbox, questions, queue, restapi, users. The Modules job runs
the same script.

The file list is the gate, not the migrator's log line: the first version
asserted nine databases and would have passed a migrate that wrote only part of
the catalogue. The step is also one `make migrate-defaults` target, registered
in `toolchain.json`. It is source-only — `bin/export/core/prune.txt` classifies
the script as a source tool, because the export prunes Forms, Comments and
Questions, so three of the thirteen files can never exist there, and the
exported registry (20 gates) does not carry it.

### The landing asserts on a theme that has a build

`SiteHomepageTest` had two gates for one question: a hand-checked
`public/dist/<theme>/manifest.json` and a second, Boson-specific check in the
diagram claim that could only pass where the first already had. Both are one
`requireBuiltTheme()` now: it reads the theme from the markup the page loads and
asks `AssetManager::isAvailable()`, so the test spells neither a theme nor a
theme module — a `use Modules\Themes\Boson\…` would make the catalogue check
read Site → Boson.

The Modules job had also seeded `theme=mono`, so the landing's copy and diagram
claims had no Boson build to read at all; it seeds `boson`. The two skips that
remain in a catalogue-installed tree are named in the no-skip gate —
`Tests\Boot\KernelOnlyBootTest::unroutedAdminPathIsNeverForbidden` and
`Tests\Discovery\HomepageSurfaceRegistryTest::nothingInstalledAnswersNull` —
and the comment above it lists both instead of one.

### `APP_ENV` is set where it is read

A local `.env` fills the `Env` cache, `$_ENV` and `$_SERVER` at boot, so a test
that only called `putenv()` kept reading the deployment's `APP_ENV` and four
mailer assertions failed on a developer machine while CI, which has no `.env`,
stayed green. Those tests now set or clear the value on all three layers the
reader consults.

### The module is `RestApi`

`RestPanel` named the slice after the shell it renders into. The module, its
namespace, catalogue key, database (`restapi.db`), snapshot directory
(`storage/data/restapi/`), audit events (`restapi.*`) and its MCP entry point
(`bin/restapi-mcp`) are `RestApi`; `Cardboard` keeps the panel. `/mark/rest`,
the `mark.rest*` route names, `settings.manage` and the fifteen MCP tools are
unchanged.

No aliases: `RESTADMIN_*` and `DB_RESTPANEL` are no longer read, and
`bin/cardboard-rest-mcp` is gone rather than kept as a stub. A deployment
renames its keys (`RESTAPI_ALLOWED_HOSTS`, `RESTAPI_SPEC_DIR`,
`RESTAPI_<SERVICE>_TOKEN`, `DB_RESTAPI`), reinstalls the slice
(`php bin/cms module:remove RestPanel --force && php bin/cms module:add RestApi`)
and runs `bin/cms migrate`, which adopts the rows of `restpanel.db` — or
`restadmin.db`, when the nearer file is absent — beside the live database and
retires it as `restapi-legacy.db`.

### MariaDB is a third engine

`DB_DRIVER=mariadb` reads `DB_DSN` as a server DSN and gives every module its
own database under `DB_DATABASE_PREFIX` (default `nativa_`). The dialect learned
MariaDB's DDL, `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE`, `CONCAT`, `DATE_ADD`,
`SIGNAL` triggers and `FOR UPDATE SKIP LOCKED`; the connection pins UTC and
SERIALIZABLE, and foreign-key errors 1451/1452 are translated. The
`mariadb:11.4` CI job runs the suite with `--fail-on-skipped`, migrates the full
catalogue twice and walks the login/dashboard/outbox path.

### The runtime SQL follows the dialect too

The DDL that `ViewCounter` and `RateLimiter` create on first use, the Scheduler
table probe, the conflict clauses in `MediaFolderRepository`, `UserRepository`
and `RoleRepository`, and the queue and outbox ordering and interval arithmetic
now go through `SqlDialect`. SQLite output and ordering are unchanged.

A Scheduler check briefly called `driverName()` on a raw `\PDO`, which has no
such method; the timetable lost `queue:work` and its test caught it. Boolean
predicates and the metric datetime windows are still SQLite-only and are listed
in `docs/kernel-status/34-runtime-sql.md`.

### The claim and the drain know their engine

Step 5 of the driver plan. The queue opens its reservation through the dialect
(`BEGIN IMMEDIATE` on SQLite, `BEGIN` plus `FOR UPDATE SKIP LOCKED` on
PostgreSQL) and keeps the guarded update that refuses a held row. The outbox
drain runs as one serialisable transaction and retries a conflict up to three
times. SQLite behaviour is unchanged and its suites pass untouched.

The `postgres:16` job is still not added: it cannot be green until the runtime
DDL, repository `INSERT OR IGNORE`, boolean predicates and timestamp shape
listed in `docs/kernel-status/32-migrations.md` are addressed.

### The migrations speak their driver

Step 3 of the driver plan. Thirteen migrators, not the eight the plan counted,
now build their schema through `SqlDialect`: timestamp types, boolean defaults,
column probes, conflict clauses and append-only statements. The SQLite output
is unchanged; PostgreSQL gets `TIMESTAMPTZ`, `TRUE`/`FALSE`, an
`information_schema` probe and a plpgsql trigger function.

A measurement corrected one assumption: SQLite 3.54 rejects `ON CONFLICT DO
NOTHING` after `INSERT ... SELECT` (syntax error at `DO`), and three backfills
have that shape, so SQLite keeps its own `INSERT OR IGNORE` prefix. Runtime
DDL outside the migrators, repository `INSERT OR IGNORE`, boolean predicates
and timestamp text shapes are still open, and no PostgreSQL server was
measured against.

### Constraint failures have names

Step 4 of the driver plan. A unique violation and a foreign key violation now
arrive as `UniqueViolationException` and `ForeignKeyViolationException`
instead of an anonymous `\PDOException`, translated in both the direct execute
path and every prepared statement. SQLite reports both as `23000` with driver
code `19`, so the message decides there; PostgreSQL uses `23505` and `23503`.
The exceptions extend `\PDOException`, so existing catches and `errorInfo`
readers keep working; `40001` remains a transaction conflict.

### The database seam knows its driver

Steps 1 and 2 of the plan in `docs/kernel-status/27-postgres-driver-plan.md`:
`DB_DRIVER` selects `sqlite` (default) or `pgsql`, `DB_<NAME>` holds a file
path or a DSN body accordingly, and the connection contract now answers
`driverName()`, `quoteIdentifier()` and `supportsReturning()`. The dialect
object expresses the seven measured SQL differences; the SQLite session
settings moved into one class without changing a statement. Migrations, error
mapping and concurrency are still steps 3 to 5, and no PostgreSQL server was
measured against.

### One authored tree

The four repositories became one. The six theme modules were imported from
`evan70/nativa-themes` after a byte comparison (`identical=23 diverged=0`) and
now live at `modules/Themes/<Name>/`; the static gallery moved to
`themes/gallery/`. `bin/build/sync-themes.sh`, its `themes-sync` target and its
CI job are gone, because with one checkout there is no second side to compare.
`evan70/nativa-themes` was archived read-only on 2026-11-18 and unarchived on
2026-11-22 — see "One gallery, one publisher" below. The retired `extract-check`
is recorded in `docs/kernel-status/28-retiring-the-extract-check.md`, the theme
import in part 29.

**Changed for an operator**

- Theme modules are edited here, not in another repository; reinstall a slice
  with `php bin/cms module:remove <Name> --force` and `module:add <Name>`.
- `modules/Themes` is linted like every other module; the exclusion that hid 7
  findings is gone.
- The gallery has one publisher: `evan70/nativa-themes`, at
  `https://evan70.github.io/nativa-themes/`. The copy in this tree no longer
  deploys.

### One gallery, one publisher

The gallery is published from `evan70/nativa-themes` again, at
`https://evan70.github.io/nativa-themes/`. The repository was unarchived on
2026-11-22 and caught up with this tree: the eight module files that had changed
here since 2026-11-17 (the orb in Boson's CSS and script, the footer's locale
prefix, the rebuilt Roboto Condensed face the CSS references, three budget
literals), that rebuilt face, and 56 demo pages re-rendered from the themes'
own PHP. Its `pages.yml` deploys and `pages-check.yml` re-checks the served
pages; the publishing run reported 77/77 pages served.

The renderer had to be repaired first, and it had been broken in two ways that
kept the demos stale without saying so. It still imported the retired
`Modules\Core\Interfaces\HTTP\ContentServices`, which moved to
`modules/ContentLocale` — running it was a fatal error, so nobody did. And its
surface map matched `/` and `/en/` but not `/en`, so the nav's Home link on
every English demo pointed at a path the gallery does not publish: 42 broken
internal links once the pages were actually rendered. Fixed, and
`--check` now exits 0 over the 56 pages.

The copy in this tree stopped deploying the same day. `gallery-pages.yml` and
`gallery-pages-check.yml` are disabled, and the
`https://responsive-sk.github.io/nativa-php-core/` Pages site was removed: two
publishers of one gallery had already diverged, with `themes/gallery/` 42
generated pages behind, and a second URL that answers is a second thing to keep
true. `themes/gallery/` stays as a local copy that this tree can still build and
check.

### The vocabulary reaches configuration and data

The file names were one surface. Five more carried the word as a contract: the
environment variable that moves the panel, the super-user role, the command that
creates it, the nav location, and the translation group. A rename alone would
have locked out every operator — the role is what grants the bypass — so the
migration ships with the name.

**Changed**

- `ADMIN_PATH` is `MARK_PATH`. The old name is still read as a fallback, so a
  deployment that sets it keeps its prefix; `.env.example` documents the new
  name and `MarkPathPrefixTest` covers the fallback.
- The super-user role is `operator`: the bypass in `User::hasPermission()`, the
  role expansion in `UsersAuthProvider`, the blog's ownership shortcut, the
  last-operator guard in `BulkDeleteAction` and the protected role in
  `RolesAction` all read the new name. `EnsureOperatorCommand` creates it.
- `bin/cms user:admin` is `bin/cms user:operator`. The old spelling stays as an
  alias that prints the new name and runs the same command, so a deployment
  script keeps working.

**Migration**

- `UsersDatabaseMigrator` renames `roles.name = 'admin'` to `operator` in place,
  so the ids `user_roles` and `role_permissions` carry stay valid. When an
  `operator` row already exists (the new command ran before the migration), the
  pivots are merged onto it and the legacy row is dropped: `roles.name` is
  UNIQUE, and losing an account's access is worse than losing an empty role.
  `EnsureOperatorCommandTest` pins both cases.
- `SettingsDatabaseMigrator` moves `menu_items.location = 'admin'` rows to
  `mark` in place: ids, parents and sort order stay, so a deployment's own nav
  edits survive. A legacy row is dropped only when the same label and href
  already sit at `mark`, which is the one case that would render twice.
  `CardboardNavService`, the panel-nav create action and the seed read `mark`.
- `LocalesDatabaseMigrator` renames every `admin.*` translation key to `mark.*`
  and its group to `mark`, keeping the deployment's values. The UNIQUE
  `(translation_key, locale)` index decides a collision: a `mark.*` row the
  seed already wrote wins and the legacy duplicate is removed, so a re-run
  cannot fail on the constraint. `LocalesShellContextProviderTest` pins the
  in-place rename and `CardboardNavContractTest` the location move.

### The panel says Mark everywhere a reader looks

The business names were fixed; the words on the screen were not. A reader
opening the settings tab still saw "Admin panel navigation", the audit table a
column headed "Admin", and every theme an "Admin" link.

**Changed**

- Panel copy: aria labels (`Mark sidebar`, `Mark navigation`), the settings
  headings and buttons (`Mark panel navigation`, `Add nav item`), the nav
  validation message, all `settings.panel-nav*` audit descriptions, the
  dashboard card (`Mark Actions`), the audit table header (`Actor` — the column
  names a person, not the panel) and the blog ownership refusal (`Ask an
  operator`).
- Translation *values*, not keys: `nav.admin`, `home.cta_admin`, `ms.nav_admin`,
  `ms.cta_admin` and `mag.nav_admin` now render `Mark` / `Open Mark` / `Mark
  panel`. The seeded header link is `Mark` instead of `Admin`.
- Theme chrome: the header, footer, mobile and nav links in Boson, Griffe,
  Jednotka and Nativa say `Mark` / `Open Mark`; Erik's fallback copy follows.

**Migration**

- `LocalesDatabaseMigrator` retitles only rows still holding the exact old
  default, so an operator's own translation of those keys is untouched, and
  `SettingsDatabaseMigrator` retitles only the exact seeded header row
  (`header`, `/mark`, label `Admin`).

### One vocabulary: no `admin` in a path

The panel answers at `/mark`, its routes are `mark.*`, its classes are `m-` and
its design system is `mark.css`; `ADMIN_PATH` already defaults to that spelling.
The names on disk were the one place the application and its files disagreed, so
a leaked archive, an error trace or a `git ls-files` listing named the panel with
the one word the runtime never uses. This round makes the tree say what the
application says. It is a vocabulary rule, not a security control: the gate
stays fail-closed, and who may open the panel does not change because a file was
renamed.

**Changed**

- The kernel's route table is `MarkRoutePolicy` and the no-provider gate is
  `MarkUnavailableMiddleware`; `isAdminPath()` is `isMarkPath()`. Their tests
  follow: `MarkPathPrefixTest`, `MarkWithoutProviderTest`,
  `MarkClassContractTest`, `MarkRouteUrlNamesTest`,
  `MarkRoutePolicyCoverageTest`.
- Cardboard's own classes and assets: `CardboardLayout` (57 call sites),
  `CardboardNavService`, `CardboardLocale`, `mark-shell.js`,
  `mark-shell-commands.js`, `mark-theme-init.js`, and the settings table
  template `settings-nav-table.phtml`.
- Catalogue siblings: `OutboxAction`, `QueueAction`, `MarkNotifier`,
  `MarkNotificationHandler`, `EnsureOperatorCommand`; tests `MarkEscapeTest`,
  `OutboxListTest`, `QueueApiTest`, `QueueWorkTest`, `MarkAccessControlTest`,
  `MarkInboxWiringTest`, `MarkNotificationTest`, `EnsureOperatorCommandTest`.
- `RestAdmin` is `RestPanel`: the module directory, the namespace
  `Modules\RestPanel`, the catalogue entry, and the MCP entry point's help
  text. `/mark/rest` and the `mark.rest*` names are unchanged.
- `admin-smoke` references are `mark-smoke` (`SECURITY.md`, `bin/cms`,
  `KernelContractTest`, `MigratorInterface`, the module docblocks that quote
  the gate). The script itself lives in the source repository.
- The settings screen's panel-nav editor moved with the vocabulary:
  `/mark/settings/admin-nav` is `/mark/settings/panel-nav`, its route names
  are `mark.settings.panel-nav*`, the handlers are `savePanelNav`-style and
  the tab key is `panel-nav`. The REST panel's audit events are
  `restpanel.*`.
- The panel's database file is `restpanel.db`. The module key,
  `HealthTool`'s name, the snapshot directory and the tests follow; `bin/cms
  migrate` adopts the rows from `restadmin.db` once (`ATTACH` + `INSERT OR
  IGNORE` in foreign-key order) and retires the old file as
  `restpanel-legacy.db`. It runs after the tables exist and only while the new
  file has no services, so a second migrate is a no-op.

**Added**

- `tests/Architecture/FileNameVocabularyTest.php`: no tracked path segment may
  carry the word. Its ledger excused today's 130 paths and had to end **empty**;
  an entry that stopped excusing anything failed too, so an excuse cannot
  outlive its defect. A second case tokenises declarations and catches a type
  renamed without its file. 130 paths → 0. The round's plan is
  `.ai-factory/plans/feature-mark-file-vocabulary.md`.

**Measured**

- `git ls-files | grep -i admin`: 0. `composer test`: 889 tests, 3152
  assertions, 3 skipped. `make lint`, `make analyze`, `make mago-lint` and
  `make mago-guard`: exit 0. `bin/build/install-catalogue.sh`: 22 modules;
  `php bin/cms migrate`: exit 0.
- Installed slices are gitignored, so a rename reaches `src/Modules/*` only
  through `module:remove`/`module:add`. The first run after `isMarkPath()`
  failed 58 times on the stale slices — the rule `AGENTS.md` states, measured.

**Not changed, with the reason recorded**


### UTC timestamps: one clock on disk, the operator's clock on screen

The writers stamped `date()` and the panels printed the stored string back, so
both ends depended on the process timezone while the columns they were compared
with (`CURRENT_TIMESTAMP`, `datetime('now', …)`) are UTC. This round moves the
write side to one base and gives the read side exactly one seam.

**Changed**

- `Audit`, `Queue`, `Outbox`, `Notifications` and `Media` stamp their timestamps
  with `gmdate()`: `audit_log.created_at`, the queue's `available_at`, the
  outbox's `created_at`/`last_attempt_at`/`processed_at`, the inbox's
  `created_at`/`read_at` and `assets.created_at`. `AuditEntry` also hydrates
  `created_at` as a UTC `DateTimeImmutable`, so a row read back is the instant
  that was written.
- The two writers outside that set follow: `Security\RateLimiter` writes
  `login_attempts.attempted_at` in UTC, and `Locales\Translation` creates,
  hydrates and edits `created_at`/`updated_at` through one `now()` helper
  pinned to UTC.
- The read side converts once, in `AdminLayout`: `displayTimezone()` resolves
  `APP_TIMEZONE`, `storedTime()` reads a zone-less column AS UTC (an ISO 8601
  value keeps its own offset) and `formatTime()` renders it in that zone.
  `relativeTime()` takes a `DateTimeImmutable`, and its `<time datetime="…">`
  carries the display offset. The audit log, the outbox list and detail, the
  queue detail, media dates and `RestAdmin`'s `updated_at` now go through it
  instead of printing the column.
- The public author profile follows the same seam: "Member since" reads
  `users.created_at` as UTC and shows it in `APP_TIMEZONE` instead of letting
  `strtotime()` read the column in the process timezone, which put a member who
  joined just after local midnight on the previous day.

**Added**

- Eleven tests on both sides of that seam: a UTC stamp per write-side module
  asserted under `Pacific/Kiritimati` (UTC+14), a failed login attempt stamped
  in UTC, `TranslationTimestampTest` for create and edit, and
  `AdminLayoutTimeTest` for UTC parsing, offset-bearing `<time>` values and an
  empty timestamp that stays empty instead of becoming "now".
- `AuthorMemberSinceTest` (three) pins the public profile's side of the same
  seam. `memberSince()` is private, so the test reaches it by reflection and
  asserts the stored UTC stamp under `Pacific/Kiritimati`, that the process
  zone does not move it, and that an empty column stays empty instead of
  rendering a bare "Member since ".

**Measured**

- `make test`: 887 tests, 3149 assertions, 3 skipped. `make mago-lint`: exit 0,
  0 issues; `make analyze` and `make lint`: exit 0. On `localhost:8000` a queue
  probe and the audit rows were stored at `23:21` UTC while the panels showed
  `00:21` CET.

**Operational note**

- An edit to a catalogue slice stays inert until the module is reinstalled:
  `php bin/cms module:remove Blog --force && php bin/cms module:add Blog`. The
  first run of `AuthorMemberSinceTest` failed against the installed copy for
  exactly that reason. `module:remove` leaves the module's data behind
  (`storage/data/blog.db`), so the reinstall is not destructive.

**Not changed, with the reason recorded**

- `RestAdmin`'s `SpecStore` keeps `gmdate('c')`. The ISO 8601 string is the
  documented snapshot contract (`SpecStoreTest` pins the offset shape) and is
  never compared with a database column; only its presentation in the service
  list converts.

### REST admin: a panel, a specification and an MCP server

**Added**

- `RestAdmin`, a module that renders an admin panel at `/mark/rest` from a
  runtime JSON specification instead of generated PHP: services, resources,
  typed fields, an action allowlist and a pagination strategy. The panel covers
  service and resource editing, collection pages with paging, item detail, and
  opt-in create/update/delete forms. It installs beside `Cardboard`, which does
  not import it back (`make mago-guard`).
- `bin/cardboard-rest-mcp`, a stdio JSON-RPC 2.0 server with fifteen tools over
  the same specification and the same readers. `delete_item`, and an
  `update_resource` that changes actions, require `confirm: true`.
- A strict validator and one aggregate: unknown keys, paths without `{id}`,
  unknown actions or pagination words, missing relation targets and cycles are
  refused before anything is written. Rows live in `restadmin.db`; a versioned
  JSON snapshot is written atomically beside it.
- An outbound seam (`HttpRequestSpec`, `HttpResponse`, curl and fake transports,
  an SSRF allowlist) and a mapping layer (envelope detection, dotted-path
  fields, page/link/cursor pagination, typed API errors with field errors).
- Panel writes are audited through `Modules\Audit` when it is installed; the
  reference is guarded and best-effort, because the write has already committed.

**Security**

- A service stores the **name** of a token environment variable, never the
  token. `RESTADMIN_ALLOWED_HOSTS` is checked before `curl_init`; redirects are
  not followed and response bodies are capped at 2 MiB.
- Writes are opt-in per resource and checked twice (rendered button and route);
  MCP item writes reject undeclared and read-only fields.

**Known**

- No OAuth2 PKCE/refresh and no cookie/session authentication; tokens are static
  environment variables. Relations are validated and stored but not edited by
  the panel in v1. `docs/rest-admin.md` lists the limits with their reasons.
- PHP 8.5.12 segfaults while loading a class that combines eleven argument
  helpers with five schema builders, and on a `TestCase` method returning an
  anonymous class. The module splits `Schemas` out and keeps test doubles in
  plain classes; both are documented where they live.

### Failover for prepared reads, and a reset that rolls back

A comparison against Marko's `ReadWriteConnection` found two gaps in the
contract above. Both passed every gate, and both are fixed here.

**Changed**

- A prepared read returns a `FailoverStatement` instead of a statement on one
  replica. The replica is chosen at `execute()`, which is where a failure is
  visible, and the bindings a caller set with `bindValue()` are replayed on the
  retry. This mattered more than it looks: 382 call sites prepare and 52 call
  `query()`, so the failover covered a ninth of the reads.
- `ReadWriteConnection::resetSticky()` is now `reset()`, and it rolls back a
  transaction the unit of work left open before dropping the flags. A queue
  worker keeps the connection across jobs, so the old reset handed an open
  transaction to the next one.

**Added**

- `ConnectionInterface::transaction($callback, $attempts = 1, $backoff = null)`
  runs the whole transaction again when it fails with a
  `TransactionConflictException`, up to `$attempts` runs. `detect()` classifies
  the driver's failure: SQLSTATE 40001 and 40P01, MySQL 1213, and SQLite's
  `database is locked`. Only a classified conflict is retried, and a nested
  call never retries at all: the outermost transaction owns it.
- The delay between attempts is exponential with full jitter by default, a
  fixed number of milliseconds, or a closure that decides.

**Not changed, and known**

- No `afterCommit()`/`afterRollback()`, and no
  `driverName()`/`quoteIdentifier()`/`supportsReturning()` on the contract.
  They belong to the Postgres driver, whose plan is
  `docs/kernel-status/27-postgres-driver-plan.md`.

**Measured**

- `composer test`: 1199 tests, 7585 assertions, 1 skipped. PHPStan, Rector,
  CS Fixer, mago-lint and mago-guard clean; `make extract-check` reports 185
  identical files and 103 recorded.
- The kernel moved from 185 219 B to 203 318 B and from 47 to 50 files.
  Database now reads 3 interface and 11 concrete.

### The connection contract: every module repository on `ConnectionInterface`

One round per module, fourteen rounds, each one a commit that had to pass the
same gates as the others. The kernel's repositories used to be typed against
`\PDO`, which made "where does this read go?" a property of the driver. They
now take `ConnectionInterface` (and `StatementInterface` for what a query
returns), so a connection can answer that question: `query()` is a read,
`execute()` is a write, and `prepare()` reads the intent from the statement's
first keyword because at that point nobody has said.

**Changed**

- Repositories in `Audit`, `Blog`, `Cardboard`, `Comments`, `Forms`, `Locales`,
  `Media`, `Notifications`, `Outbox`, `Questions`, `Queue`, `Security`,
  `Settings` and `Users` take a `ConnectionInterface`. Constructing one from a
  module's `DatabaseConnectionManager` is `->connect($name)`, not
  `->connection($name)`.
- Reads go to `DB_<NAME>_READ` when it names replicas, and stay there until the
  request writes; every read after that write is on the primary.
  `PrimaryReadInterface::onPrimary()` pins a read that must see its own write.
  A replica that raises on connect is dropped and retried on the next request,
  and with no replica configured the manager returns the same handle as before.
- Each module's `Infrastructure/Persistence/Statement` helper moved with its
  module and is byte-identical to the other contract-stage copies apart from
  the namespace. `StatementHelperContractTest` holds that identity and the
  module list, so a helper cannot drift on its own.
- `DatabaseQueue::reserve()` keeps `BEGIN IMMEDIATE`, spelled out through
  `execute()`. `beginTransaction()` opens a deferred transaction, and the
  guarded `UPDATE ... WHERE reserved_at IS NULL` would guard nothing.
- The CI theme-pick step builds `SettingsRepository` from `connect('config')`,
  because it is a caller like any other and runs before the suite.

**Not changed, with the reason recorded**

- `Health` still names `\PDO` in one class. The check exists to observe a file
  failing, and a routed connection is a different observation.
- `Clickhouse` still opens its foreign SQLite file with `\PDO`, read-only,
  because the contract names a module database and never a path, and has no way
  to ask for a read-only open.
- Module migrators keep `\PDO`: DDL runs before any repository exists and reads
  no rows.
- Replica routing has not been measured against MySQL or Postgres. In this tree
  replicas are SQLite files, and the sticky write is a request-scoped answer to
  lag, not a clock.

**Measured**

- `composer test`: 1186 tests, 7536 assertions, 1 skipped. PHPStan, Rector,
  CS Fixer, mago-lint and mago-guard clean; `make extract-check` reports 185
  identical files and 103 recorded.
- The kernel moved from 159 059 B to 185 219 B and from 38 to 47 files, Core's
  types unchanged at 8 interface and 28 concrete.

**Reading**

- `docs/kernel-status/26-db-connection-contract.md` is the round-by-round
  record, including the two modules above and what each would still cost.
- `.ai-factory/patches/2026-11-10-connection-contract-series.md` carries the
  three failures the series produced and what prevents them.
