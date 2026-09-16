# Spec baseline — RuntimeEnvBundle

**Package**: `nowo-tech/runtime-env-bundle`  
**Last audited**: 2026-09-16  
**Inventory**: [`code-inventory.md`](code-inventory.md)

## Goal

Allow selected application environment variables to be stored in the database (encrypted at rest with DoctrineEncryptBundle), edited via admin CRUD, and read at runtime without restarting FrankenPHP workers.

## Out of scope (v1)

- Replacing bootstrap `.env` (`DATABASE_URL`, encryptor keys, etc.)
- Mutating `$_ENV` / `putenv`
- Compile-time `%env(...)%` container parameters from DB values

## User Stories

### US-01 — Operator edits a runtime variable

**As an** administrator, **I want** to create, update, and delete encrypted environment variables in the admin panel **so that** integrations can change without redeploying.

**Acceptance**

1. CRUD create/update/delete uses Symfony Forms with CSRF (`RuntimeEnvVariableType`, `RuntimeEnvDeleteType`).
2. Panel access is gated by `security.access_roles` (REQ-UI-002) unless `allow_unauthenticated` is enabled for demos.
3. List UI masks values; decrypted values are not logged.

### US-02 — Application reads worker-safe values

**As a** developer, **I want** `RuntimeEnvBag` and Twig `runtime_env()` **so that** request handlers read DB-backed values without mutating process env.

**Acceptance**

1. `RuntimeEnvBag` memoizes enabled variables and implements `ResetInterface` / `kernel.reset`.
2. After write, the next load (or post-reset) returns the updated value.
3. PHPStan FrankenPHP worker ruleset is clean for `src/`.

### US-03 — Integrator configures encryption and table prefix

**As an** integrator, **I want** configurable `table_prefix` and DoctrineEncrypt `#[Encrypted]` values **so that** multi-tenant schemas and key management stay host-owned.

## Functional requirements

| ID | Requirement |
| --- | --- |
| FR-BUNDLE-001 | Bundle boots via `NowoRuntimeEnvBundle` and Flex recipe defaults. |
| FR-CFG-001 | Configuration tree exposes enabled, table_prefix, panel, security, web_ui, templates. |
| FR-CFG-002 | Extension registers parameters, services, and Doctrine mapping. |
| FR-DI-001 | `services.yaml` wires form types and tagged services. |
| FR-DI-002 | `TwigPathsPass` registers Twig namespace `NowoRuntimeEnvBundle`. |
| FR-UI-001 | Admin CRUD controller at configurable `panel.path_prefix`. |
| FR-UI-002 | Create/edit form maps to `RuntimeEnvVariable`. |
| FR-UI-003 | Twig templates use UiKit-friendly markup and form loops (REQ-TWIG-003/005). |
| FR-UI-004 | Delete uses CSRF-only `RuntimeEnvDeleteType`. |
| FR-ORM-001 | Metadata listener applies `table_prefix` to the variables table. |
| FR-ORM-002 | Entity value column uses `#[Encrypted]`. |
| FR-REP-001 | Repository interface for find/save/remove. |
| FR-REP-002 | Doctrine ORM repository implementation. |
| FR-RT-001 | Route type `nowo_runtime_env` loads panel routes when enabled. |
| FR-RT-002 | `RuntimeEnvBag` read API + reset. |
| FR-RT-003 | `RuntimeEnvWriter` persists and invalidates the bag. |
| FR-SEC-001 | Access subscriber denies unauthorized panel requests. |
| FR-SEC-002 | Pluggable `RuntimeEnvAccessCheckerInterface`. |
| FR-SEC-003 | Allow-all checker for demos. |
| FR-SEC-004 | Role-based checker (REQ-UI-002). |
| FR-TWIG-001 | Twig function `runtime_env()`. |
| FR-I18N-001 | Translations for en, es, it, fr, pt, de, nl. |

## Acceptance (v1)

1. CRUD create/update/delete with CSRF and REQ-UI-002 roles
2. Values encrypted via `#[Encrypted]`
3. `RuntimeEnvBag` returns updated values after write on next load; `reset()` clears memoization
4. PHPStan FrankenPHP worker ruleset clean
