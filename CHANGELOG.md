# Changelog

Notable changes to this tree, newest first. A round that leaves something open
says so here too, because a changelog that only lists what worked is a brochure.

## Unreleased

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
