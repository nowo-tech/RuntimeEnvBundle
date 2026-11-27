# Feature specification — DBAL query providers

**Package:** `nowo-tech/runtime-env-bundle`  
**Feature:** `002-dbal-query-provider`  
**Target release:** 1.10.0 (minor, no BC break)  
**Requires:** nowo-tech/runtime-setting-contract ^1.1 (`RuntimeValueProviderInterface`, spec `002-value-providers` there)  
**Status:** Implemented (unreleased)  
**Last updated:** 2026-11-27  
**Origin:** owner request 2026-11-27 — environment variables moved to the database come from pluggable providers that
define which query fetches each variable and its value, with caching; a ready-made DBAL query provider configured in
YAML with parameterised, read-only SQL validated at container compile, never editable at runtime.

## User scenarios

### US-01 — Host reads a setting from its own table

- **Given** `nowo_runtime_env.providers.tenant_db: { connection: default, sql: 'SELECT url AS value FROM host_tenant_setting WHERE tenant = :tenant AND name = :key', parameters: { tenant: acme } }`
- **And** `nowo_runtime_setting.setting_providers: { TENANT_URL: tenant_db }`
- **Then** the resolver returns the row's value, validated by the bundle that registered `TENANT_URL` (FR-PRV-002)

### US-02 — Unsafe SQL never reaches production

- **Given** an `sql` with `;`, a comment, a string literal, `?`, `::`, `%`, `%env()%`, `FOR UPDATE`, `INTO`, `SLEEP`, an unknown or unused parameter
- **Then** the container build fails naming the provider and the rule, not the SQL (FR-PRV-001)

### US-03 — Ambiguous or failing query

- **Given** the query returns two rows, a missing column, an oversized value, a bad expiry, or the driver fails
- **Then** `RuntimeSettingSourceFailure` names the provider and the rule only (no SQL, parameter, value or chained driver exception); the setting's `on_error` applies (FR-PRV-003)

### US-04 — Read-only

- **Given** a provider-backed setting
- **When** the commands or the manage UI try to change it
- **Then** `RuntimeSettingNotWritable`, nothing written, description included (FR-PRV-005)

### US-05 — Rotation and caching

- **Given** `previous_column` / `previous_expires_column` and `cache_ttl: 60`
- **Then** verifiers get current and previous until the expiry; workers share non-secret values through the pool for one 60-second bucket (FR-PRV-004, contract FR-PROV-006)

## Functional requirements

| ID | Requirement |
|---|---|
| FR-PRV-001 | `ReadOnlyQuery` validates at container build: 1..4096 bytes, UTF-8 without control characters, starts with `SELECT`, no `;` `--` `#` `/*` `*/` `'` `$` `\` `?` `::` `%`, quoted identifiers `[A-Za-z0-9_ ]` only, none of `FORBIDDEN_KEYWORDS` outside quoted identifiers, every `:` starts a named parameter, at least one parameter, parameters are `:id` / `:namespace` / `:key` or configured constants (snake case, scalar, all used); column names are identifiers; previous and expiry columns together; `max_length` 1..65536. The extension also refuses `%env()%` in `connection`, `sql`, columns and time zone, an invalid provider name, connection name or time zone. |
| FR-PRV-002 | `DbalQueryValueProvider::read()` binds the used parameters by name with their DBAL types (the setting id is never interpolated), runs the query on the configured connection and reads at most two rows (then frees the result). No row or SQL NULL: no value. |
| FR-PRV-003 | More than one row, a missing column (exact, else one case-insensitive match), a non-scalar column, a value over `max_length` (LOB streams read with that bound), any driver exception: `RuntimeSettingSourceFailure` with the provider name and the rule only, never chained. |
| FR-PRV-004 | Optional rotation columns: previous value + expiry (Unix timestamp, or `YYYY-MM-DD[ T]HH:MM:SS[.ffffff][Z|±HH[:MM]]` in `expires_timezone`, default UTC); a previous value without expiry is ignored; a malformed expiry is a failure. |
| FR-PRV-005 | `RuntimeEnvAdminSettings` refuses create / update of a provider-backed setting with `RuntimeSettingNotWritable` before anything is written; `manages()` keeps such settings off the free-form path. |
| FR-PRV-006 | One service `nowo_runtime_env.provider.<name>` per entry, tagged `nowo_runtime_setting.provider` (`name`, `cache_ttl` 0..3600); `DbalQueryProvidersPass` fails the build when the connection is not declared. |
| FR-PRV-007 | Stateless (worker mode): no state kept between reads. |
| FR-PRV-008 | Audit ZDA-L1: function families refused by pattern (`ReadOnlyQuery::FORBIDDEN_FUNCTION_FAMILIES` + `NEXT VALUE FOR`); a quoted segment (`"…"`, backticks, `[…]`, incl. `U&"…"` and schema-qualified) followed by `(` is refused on the raw SQL (ZDA-v-L1); the validation is documented as best effort, grants (PostgreSQL `REVOKE ... FROM PUBLIC`) as required. |
| FR-PRV-009 | Audit ZDA-L2: each provider tag carries `cache_scope` = `dbal:<connection>|sha256(sql)|<name>=<sha16(text)>[:<sha16(env value)>...]` (constants sorted; `%env()%` values hashed at runtime by `CacheScopeEnvVarProcessor`, prefix `nowo_runtime_env_scope`); fixed length known at build, over 4096 bytes fails the build; hashed again by the contract into the cache token. |
| FR-PRV-010 | Audit ZDA-L3: with an open transaction on the connection the read runs inside savepoint `nowo_runtime_value_provider`, released on success; on failure rolled back to it, the connection closed when that rollback fails, the original sanitised failure rethrown. |

## Success criteria

- **SC-001**: 100% line coverage; PHPStan, PHP-CS-Fixer, Rector, Igor clean.
- **SC-002**: provider tests on SQLite (unit) and on MySQL 8.4, MariaDB 11.4, PostgreSQL 16 (`--group database`, CI "Real databases", DBAL 4 and DBAL 3).
- **SC-003**: kept-alive kernel test: shared pool for one TTL bucket across workers, then current + previous; `nowo:runtime-setting:set` refused.

## Assumptions

- The provider connection uses a database user that can only `SELECT` the tables it reads, with a statement timeout (documented; the bundle cannot enforce it portably).
- Providers whose parameters depend on the request keep `cache_ttl: 0` (the contract's shared cache is keyed by setting id).

## Explicit non-goals

- Writing through providers; request-scoped parameters (implement `RuntimeValueProviderInterface` in the host instead).
- Enforcing a statement timeout or a read-only transaction per query.
