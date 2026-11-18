# Concurrency

How the bundle stays correct when many requests, workers and commands write at once (nowo-tech REQ-RUNTIME-003).
This page lists the write paths, the conflict answers and codes, the host options and the residual risks.

## Table of contents

- [Rules the bundle follows](#rules-the-bundle-follows)
- [Write paths](#write-paths)
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
| Manage pages: create, update, rename, delete (`RuntimeEnvManageController` → `RuntimeEnvWriter`) | One ORM flush on `database.entity_manager`; with `runtime_setting.enabled` the version bump is in the same flush | Unique key `uniq_runtime_env_name`; a pre-check gives the friendly error; the version row is ensured with `INSERT … ON CONFLICT DO NOTHING` / `ON DUPLICATE KEY` | Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002`. Duplicate name: 409 `RUNTIME_ENV_NAME_TAKEN` (domain) |
| `RuntimeEnvWriter` called by your code | The same flush, or yours with `database.transaction: host` | The same | Your host's error handling |
| Runtime-setting store: `set`, `rotate`, `clear`, `enable` / `disable` (`runtime_setting.enabled`) | One unit of the runner, own connection transaction | `SELECT … FOR UPDATE` of the variable row (outside SQLite), unique key for the first insert, atomic version upsert, only the written row is purged | Through the manage pages: 409 `CONFLICT_001` / `CONFLICT_002`. Through the command or your code: a `TransientStorageFailure` with a Retry-After hint |
| Runtime-setting purge (`purgeExpired()`, `nowo:runtime-setting:purge`) | One unit | Conditional `UPDATE` of the expired previous values, then the version bump | The failure (console) |
| Description edit (`updateDescription()`) | One statement | None needed: it is not a setting value and changes no version | The failure |

## Conflict answers and codes

On the routes of the bundle (`StorageFailureListener`, controllers of the bundle only; your own routes keep your error
handling):

| Code | When | Status | `Retry-After` | Client should |
| --- | --- | --- | --- | --- |
| `CONFLICT_001` | Deadlock, lock wait timeout or serialization failure (a `TransientStorageFailure` of the store's runner, or a DBAL error anywhere in the chain) and no transaction of the bundle's connection committed since the request started | 409 (`conflict_response.status`) | Yes: the runner's hint, else `conflict_response.retry_after`, else 1 s | Retry |
| `CONFLICT_002` | The same, after a transaction of the bundle's connection committed earlier in the same request (the `CommitLedger` moved), a `ConcurrencyConflict` that committed, or a lost savepoint with no deadlock behind it (outcome unknown) | 409 (`conflict_response.status`) | No | Reload, do not repeat blindly |
| `RUNTIME_ENV_NAME_TAKEN` | A unique key refused the write of a concurrent request (two admins creating or renaming to the same name). A domain answer, never a `CONFLICT_*` code | 409 | No | Reload and choose another name |

- Pages get an `HttpException` with a fixed, client-safe message, rendered by your error page.
- Every answer carries the code in the `X-Conflict-Code` header.
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

- **Two admins editing the same variable in the manage pages: last write wins.** The page reads the row, shows the form
  and flushes the changed fields; there is no version column and no `FOR UPDATE` on that path, so the second save
  replaces the first without a warning (the version token still changes, so workers see the final value). Two
  admins creating the same name are caught by the unique key (`RUNTIME_ENV_NAME_TAKEN`).
- **Commit tracking covers the bundle's connection.** `CONFLICT_002` is answered when a transaction of that
  connection committed earlier in the request. A write of yours on another connection, or in autocommit before the
  bundle's controller fails, is not counted.
- **Under REPEATABLE READ** (MySQL default) locking reads take next-key locks: writes of unrelated variables can queue
  behind each other. READ COMMITTED is recommended.
- **Lock waits are bounded by the database**, not by the bundle.
- **Runtime settings read inside a write transaction of yours** can see a value that is rolled back later; the store
  refuses to write inside such a transaction for that reason.
