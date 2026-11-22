# Concurrency

How the bundle stays correct when many requests, workers and commands write at once (nowo-tech REQ-RUNTIME-003).
This page lists the write paths, the conflict answers and codes, the host options and the residual risks.

## Table of contents

- [Rules the bundle follows](#rules-the-bundle-follows)
- [Write paths](#write-paths)
- [Row versions of the edit page](#row-versions-of-the-edit-page)
- [Conflict answers and codes](#conflict-answers-and-codes)
- [What is not a conflict](#what-is-not-a-conflict)
- [Host options](#host-options)
- [Replays](#replays)
- [Tests and CI](#tests-and-ci)
- [Residual risks](#residual-risks)

## Rules the bundle follows

- **One transaction per write unit.** The runtime-setting store runs each write through nowo-tech/transaction-doctrine's
  runner (`DoctrineTransactionRunner::forConnection()`, nowo-tech/transaction-contract); the manage pages and the
  `RuntimeEnvWriter` flush once on `database.entity_manager`. Inside a transaction of yours the store refuses to run
  (`RuntimeSettingNotWritable`), so a rollback of yours can never publish a setting.
- **Your entity manager is never touched.** The bundle writes through `database.entity_manager`. When that is not your
  default manager the bundle owns it (reset when closed, cleared at request / message / command boundaries); it never
  clears, closes or resets a manager it does not own.
- **Side effects after the real commit.** `RuntimeEnvVariableChangedEvent` waits for the outermost commit of the
  bundle's connection (`RuntimeEnvOutbox`) and is dropped with a rollback.
- **FrankenPHP worker mode with kernel reset off.** Every service is stateless per request. The commit ledger used by
  the conflict answer is a monotonic counter of the process, read by difference only.

## Write paths

| Write path | Transaction | Control | Conflict answer |
| --- | --- | --- | --- |
| Manage pages: create, delete (`RuntimeEnvManageController` → `RuntimeEnvWriter`) | One ORM flush on `database.entity_manager`; with `runtime_setting.enabled` the version bump is in the same flush | Unique key `uniq_runtime_env_name`; a pre-check gives the friendly error; the version row is ensured with `INSERT … ON CONFLICT DO NOTHING` / `ON DUPLICATE KEY` | Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002`. Duplicate name: 409 `RUNTIME_ENV_NAME_TAKEN` (domain) |
| Manage page: edit of a plain variable (1.9) | One unit of transaction-doctrine's runner (`RuntimeEnvRowVersions::saveEdit()`): the row version's compare-and-set, then the writer's flush (a savepoint inside the unit) | Optimistic row version: `UPDATE … SET row_version = row_version + 1 WHERE id = ? AND row_version = ?` takes the row lock first ([below](#row-versions-of-the-edit-page)) | Stale page: 409 `RUNTIME_ENV_STALE_EDIT` (domain), nothing written. Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002` |
| Manage page: edit of a registered runtime setting (1.9, `runtime_setting.enabled`) | The claim (`RuntimeEnvRowVersions::claim()`, one unit), then the store's units below (the store refuses a transaction of the caller) | The same compare-and-set, committed before the store writes; every store write advances the version again | Stale page: 409 `RUNTIME_ENV_STALE_EDIT`, nothing written. A lock conflict after the claim: 409 `CONFLICT_002` (the claim committed) |
| `RuntimeEnvWriter` called by your code | The same flush, or yours with `database.transaction: host` | The same | Your host's error handling |
| Runtime-setting store: `set`, `rotate`, `clear`, `enable` / `disable` (`runtime_setting.enabled`) | One unit of the runner, own connection transaction | `SELECT … FOR UPDATE` of the variable row (outside SQLite), unique key for the first insert, atomic version upsert, only the written row is purged; 1.9: the row version advances in the same unit | Through the manage pages: 409 `CONFLICT_001` / `CONFLICT_002`. Through the command or your code: a `TransientStorageFailure` with a Retry-After hint |
| Runtime-setting purge (`purgeExpired()`, `nowo:runtime-setting:purge`) | One unit | Conditional `UPDATE` of the expired previous values, then the version bump | The failure (console) |
| Description edit (`updateDescription()`) | One statement | It is not a setting value and changes no `env` token; 1.9: the same statement advances the row version | The failure |
| Any other ORM update of a variable (your code through `RuntimeEnvWriter`, also with `database.transaction: host`) | Your flush | 1.9: `RowVersionListener` (`postUpdate`, inside the flush) advances the row version atomically, so edit pages opened before are stale | Your host's error handling |

## Row versions of the edit page

1.9 closes the last-write-wins gap of the edit page (REQ-RUNTIME-003 rule 4, optimistic concurrency):

- **Column.** `row_version INT NOT NULL DEFAULT 1` on the variables table, mapped on `RuntimeEnvVariable` with
  `insertable: false, updatable: false`. The ORM never writes it, so it can never go backwards; every change is one
  atomic `UPDATE … SET row_version = row_version + 1`.
- **Page.** The edit form carries the version in the hidden, unmapped field `rowVersion`. The controller reads it from
  the database (`RuntimeEnvRowVersions::current()`) before it loads the values (a write in between makes the page
  stale, audit ZJ-L1), never from the identity map, so a manager that kept a loaded row
  across requests (worker mode, kernel reset off) cannot make a stale page look current. A submitted form keeps the
  version it was sent with; a missing or malformed field reads as 0 and never matches (versions start at 1).
- **Save.** The compare-and-set and the write run in one unit of transaction-doctrine's runner. The compare-and-set
  takes the row lock, so a second save of the same page waits for the first commit and then matches no row. After a
  failure the runner rolls the connection back to the level it found, also after a MySQL / MariaDB deadlock that
  destroyed the savepoint of the flush (it closes the connection when that rollback fails; audit ZH-M1), and the
  deadlock still answers 409 `CONFLICT_001`.
- **Stale answer.** The form is shown again with status 409, `X-Error-Code: RUNTIME_ENV_STALE_EDIT` and the flash
  `runtime_env.flash.stale_edit` (every shipped locale). It is a domain answer: retrying the same request never
  succeeds, so there is no `X-Conflict-Code` and no `Retry-After`. The managed entity is never touched (the form edits a
  detached copy), so no later flush of the manager can write the outdated page.
- **Other writers.** The ORM listener, the store and the description edit advance the version too, so any change of
  the row since the page was opened makes the page stale. The global purge of expired previous values does not: it
  changes no field of the form.

## Conflict answers and codes

On the routes of the bundle (`StorageFailureListener`, controllers of the bundle only; your own routes keep your error
handling):

| Code | When | Status | `Retry-After` | Client should |
| --- | --- | --- | --- | --- |
| `CONFLICT_001` | Deadlock, lock wait timeout or serialization failure (a `TransientStorageFailure` of the store's runner, or a DBAL error anywhere in the chain) and no transaction of the bundle's connection committed since the request started | 409 (`conflict_response.status`) | Yes: the runner's hint, else `conflict_response.retry_after`, else 1 s | Retry |
| `CONFLICT_002` | The same, after a transaction of the bundle's connection committed earlier in the same request (the `CommitLedger` moved), a `ConcurrencyConflict` that committed, or a lost savepoint with no deadlock behind it (outcome unknown) | 409 (`conflict_response.status`) | No | Reload, do not repeat blindly |
| `RUNTIME_ENV_STALE_EDIT` (`X-Error-Code`, 1.9) | The edit page was opened before another save of the same variable (the row version moved). Nothing was written; the form is shown again with a flash. A domain answer, never a `CONFLICT_*` code | 409 | No | Reload the page and apply the change again |
| `RUNTIME_ENV_NAME_TAKEN` (`X-Error-Code`) | A unique key refused the write of a concurrent request (two admins creating or renaming to the same name). A domain answer, never a `CONFLICT_*` code | 409 | No | Reload and choose another name |

- Pages get an `HttpException` with a fixed, client-safe message, rendered by your error page.
- Lock conflict answers carry their code in the `X-Conflict-Code` header, which only ever holds `CONFLICT_001` or `CONFLICT_002`. The duplicate-name and stale-edit answers have no `X-Conflict-Code`; they carry `X-Error-Code: RUNTIME_ENV_NAME_TAKEN` / `RUNTIME_ENV_STALE_EDIT`.
- The `CONFLICT_*` codes are `Nowo\TransactionDoctrine\ConflictCode` values.
- No SQL, parameter or value reaches the client; the storage error stays the previous exception for your logs.
- The bundle has no JSON endpoints: its answers are pages, so no JSON shape changed.

## What is not a conflict

- **Unique constraint violations** are not lock conflicts: retrying the same request cannot succeed. The bundle checks
  the name before it writes (friendly form error) and, when two requests still race on the unique key, answers the
  domain 409 `RUNTIME_ENV_NAME_TAKEN`. Inside the store the unique key only guards the first insert of a variable, after
  `FOR UPDATE` and the version upsert have serialised the writers; a violation there is a real error (500).
- **Lost connections** are never mapped: the outcome of the write is unknown and nothing tells it was a lock.
- Validation errors stay form errors.

## Host options

```yaml
nowo_runtime_env:
    conflict_response:
        status: null        # 409 (default) or 503
        retry_after: null   # seconds for CONFLICT_001 without a hint
```

- Unset (null), the bundle follows `nowo_transaction_doctrine.conflict_response` when nowo-tech/transaction-doctrine's
  Symfony bundle is registered, so the whole application answers the same; otherwise 409 with `Retry-After: 1`.
- `status: 503` keeps the 1.6 to 1.7 status; the codes and the `Retry-After` rule stay the same.
- Database settings: READ COMMITTED and a short `innodb_lock_wait_timeout` / `lock_timeout` (a few seconds) for HTTP;
  the wait is bounded by the database, not by the bundle.

## Replays

- HTTP writes make **one attempt**: the client retries after a `CONFLICT_001`.
- The store's runner makes one attempt too (`retryAfterSeconds` is only the hint). Replaying a unit belongs to the
  caller; a replay loads the row again under `FOR UPDATE`, so it never applies a stale read.

## Tests and CI

- `tests/Integration/Database/RuntimeEnvSettingStoreDatabaseTest.php` plays two connections against real servers:
  writes of different variables never wait for each other, the version row never conflicts, a lock wait of a second
  writer answers 409 `CONFLICT_001` with `Retry-After` (data unchanged, connection usable, the 503 host option), a lock
  wait after a committed unit of the request answers `CONFLICT_002` without `Retry-After` (commit counted by the real
  transaction observer), and a duplicate name answers the domain 409.
- `tests/Unit/Transaction/StorageFailureListenerTest.php` maps every case: `CONFLICT_001`, `CONFLICT_002` (ledger,
  `ConcurrencyConflict::$committed`, lost savepoint), 503, the unique violation, lost connections left alone.
- `tests/Integration/Database/RowVersionDatabaseTest.php` (1.9) plays two admins on two connections: the migration is
  additive (`ALTER TABLE … ADD row_version INT DEFAULT 1 NOT NULL`, existing rows get 1); two saves of the same page,
  one wins and the other is stale with nothing written; a save waiting on an open save answers 409 `CONFLICT_001` and
  its retry is stale; writes of the ORM and the store make an open page stale; and on MySQL / MariaDB a real deadlock
  inside the edit's flush, against a second PHP process (`tests/Support/deadlock-peer.php`), answers 409
  `CONFLICT_001`, leaves the connection at nesting level 0, and the next save works.
- `tests/Unit/Concurrency/RuntimeEnvRowVersionsTest.php`, `tests/Unit/Form/RuntimeEnvVariableTypeTest.php` and
  `tests/Unit/Controller/RuntimeEnvManageControllerRuntimeSettingTest.php` cover the same on SQLite: stale, current,
  missing and malformed versions on both edit paths, and a refused setting value that keeps the claimed version.
- The CI job **Real databases** runs the `database` group on DBAL 4, then again after `composer update
  "doctrine/dbal:^3.8.2" -W` on DBAL 3 (1.9). The deadlock test asserts a real deadlock (1213) in the failure chain,
  never a lock wait timeout.
- The CI job **Real databases (MySQL 8.4, MariaDB 11.4, PostgreSQL 16)** runs the `database` group with
  `--fail-on-skipped` and is a required check of `main`.

```bash
RE_MYSQL_URL='mysql://root@127.0.0.1:3306/reb?serverVersion=8.4.0&charset=utf8mb4' \
RE_MARIADB_URL='mysql://root@127.0.0.1:3307/reb?serverVersion=11.4.0-MariaDB&charset=utf8mb4' \
RE_POSTGRES_URL='pgsql://postgres@127.0.0.1:5432/reb?serverVersion=16' \
  vendor/bin/phpunit --group database --no-coverage --fail-on-skipped
```

(or `make test-with-db`).

## Residual risks

- **Registered runtime settings: the claim commits before the store writes** (the store refuses a transaction of the
  caller). A page opened after the claim and saved before the store's first write (a window of milliseconds, while
  that page would still show the old values) is not refused. Plain variables have no such window.
- **A custom edit template that does not render `rowVersion`** makes every save stale (it fails closed). Render it
  with `form_rest()` or `form_row(form.rowVersion)`.
- **Commit tracking covers the bundle's connection.** `CONFLICT_002` is answered when a transaction of that
  connection committed earlier in the request. A write of yours on another connection, or in autocommit before the
  bundle's controller fails, is not counted.
- **Under REPEATABLE READ** (MySQL default) locking reads take next-key locks: writes of unrelated variables can queue
  behind each other. READ COMMITTED is recommended.
- **Lock waits are bounded by the database**, not by the bundle.
- **Runtime settings read inside a write transaction of yours** can see a value that is rolled back later; the store
  refuses to write inside such a transaction for that reason.
