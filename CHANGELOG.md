# Changelog

Notable changes to this tree, newest first. A round that leaves something open
says so here too, because a changelog that only lists what worked is a brochure.

## Unreleased

### The page shell is a port, and `src/Themes` is a module

Another kernel-slimming round: `src/Themes` (25 files, 2 085 lines) is the
`Layout` module now, and the kernel surfaces that used to render through it ask
a kernel port instead.

**Changed**

- `src/Modules/Core/Kernel/Contracts/PageShellInterface` is the new port:
  `home()` and `error()` take the values the kernel owns (site name, locale,
  copy, status) and return markup. `PlainPageShell` implements it with no
  dependency, so a boot with no modules installed still answers `/` and the
  error pages, and `PageShellRegistry` discovers `#[AsPageShell]` from the
  installed slices (`src/Modules/*/Interfaces/HTTP/*PageShell.php`, plus one
  group level) before falling back.
- `HomepageAction`, `ErrorPageRenderer` and `SitemapAction` no longer import the
  theme tree. The sitemap escapes its own values; the error renderer keeps
  `PlainPageShell::bare()` as the answer when a shell raises.
- `src/Themes` → `modules/Layout` under `Modules\Layout`, assets included
  (`Themes/assets/` → `Modules/Layout/assets/`).
  `modules/Layout/Interfaces/HTTP/PageShell` is the `#[AsPageShell]` adapter over
  `FrontendLayout`, `ThemeKernel` and `ThemeResolver`. 51 consumer files follow
  the namespace, and `composer.json`, `src/init.php`, `phpstan.neon`,
  `.php-cs-fixer.dist.php`, `rector.php` and `mago.toml` drop `Themes` from
  their scopes. The guard's permit list no longer names a theme tree, because
  the kernel no longer renders one.
- `modules/View` (4 files, 295 lines) and `modules/Markdown` (5 files, 858
  lines) came out of `src/` the same way earlier in the round: kernel imports
  rewritten, config scopes trimmed, legacy `Modules\Core\Application\Services\View\*`
  references replaced.

**Fixed on the way**

- `ThemeRegistry` discovered the fallback theme it ships beside. The class moved
  into the module as `kernel/KernelFallbackTheme`, and its `kernel` name
  overwrote `Modules\KernelTheme` on every discovered render. Registration now
  skips files under its own `__DIR__`.

**Added**

- `tests/Kernel/PageShellPortTest.php` pins the fallback the boot test cannot
  reach: with `Layout` installed, `KernelOnlyBootTest` exercises the module's
  shell, never the kernel's. The new test renders and escapes through
  `PlainPageShell` directly and asserts that the registry always answers with
  the port, without naming the module that implements it.

**Measured**

- `php vendor/bin/phpunit --no-coverage`: 262 tests, 822 assertions, 4 skipped.
- PHPStan level 9: 0 errors; Rector dry and CS Fixer dry clean (9 test files
  needed their imports re-sorted after the namespace rename). `php -l` clean;
  `make lint` and `make modules-install` exit 0 with all 6 catalogue entries
  installed in closure order. No `Modules\Layout` reference in
  `src/Modules/Core` or `src/Modules/Database`.
- `make mago-guard` did not run: the `mago` binary is absent in the environment
  where this round was measured, so the rule was checked by `grep` and by
  `KernelCouplingTest`, not by the gate.
- Sizes: Core 152 520 B, Database 59 791 B, Tooling 37 919 B, Layout 113 793 B.

**Not included**

- `Shell` and `Seo` stay in the kernel. Both are ports a module answers
  (`ShellContextProvider`, `SitemapContributor`); moving the port would remove
  the seam, not move an implementation.

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
