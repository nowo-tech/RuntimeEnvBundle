# Upgrade Guide

## Table of contents

- [Unreleased](#unreleased)
- [To 1.8.1](#to-181)
- [To 1.8.0](#to-180)
  - [Conflict answers: 409 + CONFLICT codes (REQ-RUNTIME-003)](#conflict-answers-409--conflict-codes-req-runtime-003)
- [To 1.7.1](#to-171)
  - [Asset package and CDN base URLs](#asset-package-and-cdn-base-urls)
- [To 1.7.0](#to-170)
  - [Named asset package `nowo_runtime_env`](#named-asset-package-nowo_runtime_env)
- [To 1.6.0](#to-160)
  - [Exact key collation (MySQL / MariaDB, with `runtime_setting.enabled` only)](#exact-key-collation-mysql--mariadb-with-runtime_settingenabled-only)
  - [Opt in: runtime settings (`runtime_setting.enabled`)](#opt-in-runtime-settings-runtime_settingenabled)
  - [Known issues (fixed in a 1.6.x patch)](#known-issues-fixed-in-a-16x-patch)
- [To 1.5.1](#to-151)
- [To 1.5.0](#to-150)
- [To 1.4.1](#to-141)
- [To 1.4.0](#to-140)
- [To 1.3.0](#to-130)
  - [Manage pages on the UiKit page contract](#manage-pages-on-the-uikit-page-contract)
  - [Canonical configuration keys](#canonical-configuration-keys)
  - [Template contract](#template-contract)
  - [Security hardening](#security-hardening)
- [To 1.2.1](#to-121)
- [To 1.2.0](#to-120)
  - [Manage panel requires form CSRF protection at compile time](#manage-panel-requires-form-csrf-protection-at-compile-time)
- [To 1.1.3](#to-113)
- [To 1.1.2](#to-112)
- [To 1.1.1](#to-111)
- [To 1.1.0](#to-110)
- [To 1.0.6](#to-106)
- [To 1.0.5](#to-105)
- [To 1.0.4](#to-104)
- [To 1.0.3](#to-103)
- [From 1.0.1 to 1.0.2](#from-101-to-102)
- [From 1.0.0 to 1.0.1](#from-100-to-101)
- [To 1.0.0 (initial release)](#to-100-initial-release)
  - [Requirements](#requirements)
  - [Enable and configure](#enable-and-configure)
  - [Breaking changes](#breaking-changes)
- [Future versions](#future-versions)
- [Getting help](#getting-help)

## Unreleased

## To 1.8.1

From **1.8.0**:

```bash
composer update nowo-tech/runtime-env-bundle
```

## To 1.8.0

From **1.7.1**:

```bash
composer update nowo-tech/runtime-env-bundle
```

### Conflict answers: 409 + CONFLICT codes (REQ-RUNTIME-003)

- **Status change.** A deadlock, lock wait timeout or serialization failure on the manage pages now answers **409** with `Retry-After` and `X-Conflict-Code: CONFLICT_001` (nothing committed), or 409 without `Retry-After` and `CONFLICT_002` (part of the request already committed). It was 503 with `Retry-After`. If a proxy, monitor or client treats 503 as retryable, either handle the 409 + `Retry-After` or keep the old status:

```yaml
nowo_runtime_env:
    conflict_response:
        status: 503
```

- Unset, `conflict_response` follows `nowo_transaction_doctrine.conflict_response` when that bundle is registered.
- A duplicate name keeps answering 409; it now carries `X-Error-Code: RUNTIME_ENV_NAME_TAKEN` (a domain code; `X-Conflict-Code` is only ever `CONFLICT_001` / `CONFLICT_002`).
- The lock-conflict answer is now an `HttpException` (status 409, or 503 by option). A host check for `instanceof ServiceUnavailableHttpException` or `ConflictHttpException` no longer matches it: test `HttpException::getStatusCode()` or the `X-Conflict-Code` header instead.
- `composer update nowo-tech/runtime-env-bundle` also moves `nowo-tech/transaction-doctrine` to `^1.5`.
- See [CONCURRENCY.md](CONCURRENCY.md).

## To 1.7.1

From **1.7.0**:

```bash
composer update nowo-tech/runtime-env-bundle
```

### Asset package and CDN base URLs

The `nowo_runtime_env` asset package is a local package (`base_path: /bundles/noworuntimeenv`): the host's default `framework.assets.base_urls` (CDN) and `base_path` are **not** inherited by it, so the bundle stylesheet is always served from the application's own `/bundles/noworuntimeenv/` (run `assets:install`). To serve it from a CDN, override `manage/form.html.twig` and point the stylesheet at your CDN. The package is registered only when `symfony/asset` is installed.

## To 1.7.0

From **1.6.0**:

```bash
composer update nowo-tech/runtime-env-bundle
```

### Named asset package `nowo_runtime_env`

The bundle now registers `framework.assets.packages.nowo_runtime_env` (`base_path: /bundles/noworuntimeenv`, the `assets:install` target) and its own template loads the stylesheet with `asset('css/runtime-env.css', 'nowo_runtime_env')` instead of the hard-coded `bundles/noworuntimeenv/...` path. The URL is unchanged by default; run `php bin/console assets:install` after the upgrade. A template that overrides `manage/form.html.twig` and still uses the hard-coded path keeps working, but should switch to the package (REQ-ASSETS-004).

## To 1.6.0

From **1.5.1**:

```bash
composer update nowo-tech/runtime-env-bundle
```

Target 1.6.0. Nothing changes until you opt in; RuntimeEnvBag, `runtime_env()` and the admin UI behave as in 1.5.

### Exact key collation (MySQL / MariaDB, with `runtime_setting.enabled` only)

With the feature off, `doctrine:migrations:diff` stays empty. With it on, the generated schema gives `name` a binary
NO PAD collation (MariaDB `utf8mb4_nopad_bin`, MySQL 8.0+
`utf8mb4_0900_bin`; MySQL 5.7 keeps the PAD SPACE `utf8mb4_bin` with a warning). `doctrine:migrations:diff` /
`schema:update --dump-sql` will propose it. Existing tables keep working without it (every lookup is re-checked in PHP);
apply it when convenient:

```sql
-- MySQL 8.0+
ALTER TABLE runtime_env_variables MODIFY name VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
-- MariaDB
ALTER TABLE runtime_env_variables MODIFY name VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL;
```

PostgreSQL and SQLite compare exactly already; nothing to do.

### Opt in: runtime settings (`runtime_setting.enabled`)

1. Enable it and register the contract's Symfony bundle (for the commands, the resolver and the updater):

   ```yaml
   nowo_runtime_env:
       runtime_setting:
           enabled: true
   nowo_runtime_setting:
       cache_pool: cache.app      # recommended in production
       version_ttl: 5
       command:
           enabled: false         # turn on deliberately
   ```

2. Generate and run a migration: two nullable columns on `{prefix}_variables` (`previous_value` TEXT,
   `previous_expires_at` DATETIME, UTC wall-clock time, with the index `idx_{prefix}_variables_prev_exp`), the table
   `{prefix}_versions` (`namespace` VARCHAR(64) primary key, `token` VARCHAR(128)) and, on MySQL / MariaDB, the
   collation above. Without the feature they are not mapped, so `migrations:diff` neither adds nor drops them.
   Turning the feature off again makes the next diff propose dropping them (that also deletes rotated-out secrets).
3. In the adopting bundles, set `runtime.<setting>.key` to the environment variable name (e.g. `CHAT_RETENTION_DAYS`)
   and `source: db_then_env` (or `db`).
4. Values written while the feature is off still change through the 1.5 writer; once it is on, every write changes the
   version token, so running workers pick the change up within `version_ttl`.
5. The version row is created on demand with a statement that cannot conflict (`INSERT … ON DUPLICATE KEY UPDATE` on
   MySQL / MariaDB, `INSERT … ON CONFLICT DO NOTHING` on PostgreSQL / SQLite), then updated: two first writes
   serialise, no deadlock and no duplicate key. Seeding it in your migration
   (`INSERT INTO runtime_env_versions VALUES ('env', '0')`) is still fine.
6. Schedule `nowo:runtime-setting:purge` (cron, Scheduler, a message): a write purges only its own row's expired
   previous value; the table-wide sweep is the purge command.

### Known issues (fixed in a 1.6.x patch)

- **Previous value and DoctrineEncrypt key rotation** (audit QT-L-5): `previous_value` is not an `#[Encrypted]`
  property, so `doctrine:encrypt:rotate-keys` / `encrypt:database` / `decrypt:database` do not re-encrypt it, and an
  undecryptable previous value makes the read of the setting fail until the row is purged. Rotate DoctrineEncrypt keys
  outside a rotation window, or run `nowo:runtime-setting:purge` after the window first.
- **Admin delete of a registered setting** (audit QT-L-6) goes through the 1.5 writer: the version changes and
  `RuntimeEnvVariableChangedEvent` is dispatched, but no runtime-setting audit line with the actor is written. Use
  `nowo:runtime-setting:unset` (audited) for registered settings until then.
- **Bumper deadlock with `database.transaction: host`** (Delta QT, QT-D-I-1): on MySQL / MariaDB, the version-row
  `ensure()` that the 1.5 writer's bumper runs inside your transaction may meet a concurrent store write of the same
  variable in a transient deadlock (503 on the admin routes; retry). Fixed in a 1.6.x patch.
- **Long table prefix** (Delta QT, QT-D-I-2): the index `idx_{prefix}_variables_prev_exp` may exceed MySQL's 64
  characters with a very long `table_prefix` (more than 41 characters); keep the prefix shorter.

## To 1.5.1

From **1.5.0**: patch, no configuration change.

```bash
composer update nowo-tech/runtime-env-bundle
```

- With `database.transaction: host`, the events of writes your use case has not flushed yet are no longer discarded by
  a nested boundary (a command run in-process from another command, a handler or a controller; an unknown nested
  command; a request handled inside a command; a failed sub-request or nested main request): they are dispatched after
  your flush. Only the outermost request, message or command discards events of writes that were never flushed (same
  warning as before); a failed request discards them at `kernel.terminate` (no longer at `kernel.exception`), also
  after a `kernel.exception` listener of yours threw.
- `Persistence\RuntimeEnvTransaction` is `@internal`: its boundary methods changed (new `onRequest`,
  `onFinishRequest`, `onHttpException`, `onHttpTerminate`, console and Messenger handlers). New internal
  `Worker\UnitOfWorkFrames` (not a service).

## To 1.5.0

Standard hosts (`database.entity_manager: default`, the default): **no configuration change**. The writer keeps
flushing your default manager.

- Behaviour change for kept-alive workers without the services resetter: the repository no longer resets your default
  entity manager when it is closed (the host owns it). Keep Symfony's services resetter, or move the bundle to its own
  manager.
- `database.entity_manager: <other>` now really maps the entity on that manager only. Declare it under
  `doctrine.orm.entity_managers` (no mappings needed) and keep `default_entity_manager` on your own default; create or
  migrate `{table_prefix}_variables` on its connection. An undeclared name fails the container build. If you restrict
  `nowo_doctrine_encrypt.connections`, add that connection (or leave the list empty), or the values are stored in
  plaintext.
- New, optional: `database.transaction: host`, `RuntimeEnvVariableChangedEvent`, `RuntimeEnvReaderInterface`,
  `RuntimeEnvWriterInterface`. If you construct `RuntimeEnvWriter` yourself, its new optional arguments
  (`$transaction`, `$clock`) can stay out: it then flushes as before and dispatches no events.

## To 1.4.1

From **1.4.0**:

```bash
composer update nowo-tech/runtime-env-bundle
```

`nowo-tech/form-kit-bundle` is now required at `^2.17` (was `^2.16`); update both packages together:

```bash
composer update nowo-tech/runtime-env-bundle nowo-tech/form-kit-bundle
```

No configuration or template change: the rendered forms are the same. `FormKitFieldTrait` (an internal helper of the bundle form types) is removed; it was never part of the public API.

## To 1.4.0

From **1.3.0**:

```bash
composer update nowo-tech/runtime-env-bundle
```

- **New required dependency `nowo-tech/form-kit-bundle` (`^2.16`, REQ-TWIG-005 rule 11):** register `Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true]` in `config/bundles.php` (Symfony Flex does it on `composer update`). The manage form types get their field options from FormKit; without the FormKit bundle registered they fail with an uninitialized `FormOptionsMerger`.
- **Form theme through the FormKit profile:** field defaults of the manage forms come from `nowo_form_kit.profiles.runtime_env` (prepended by the bundle; see [CONFIGURATION](CONFIGURATION.md#formkit-profile)) — define that profile instead of a `form_themes` override to add classes or change the domain. Field names, CSRF token ids (`runtime_env_delete_<id>`) and markup are unchanged.

## To 1.3.0

From **1.2.1**:

```bash
composer update nowo-tech/runtime-env-bundle
```

From **1.2.1**:

```bash
composer update nowo-tech/runtime-env-bundle nowo-tech/ui-kit-bundle
php bin/console assets:install
php bin/console cache:clear
```

### Manage pages on the UiKit page contract

The manage pages now extend the UiKit list / form scaffolds (REQ-UI-003) and render inside
`nowo_ui_kit.layouts.admin`. Requires `nowo-tech/ui-kit-bundle` **^2.17.3** (enable `NowoUiKitBundle` and run
`assets:install`: the confirm dialog script and `bundles/noworuntimeenv/css/runtime-env.css` are bundle assets).

- **Default setup (no layout configured):** point `nowo_ui_kit.layouts.admin` at your admin layout or at a one-file
  bridge that renders the UiKit canonical blocks (see UiKit `layout/_bridge.html.twig`); until then the UiKit admin
  layout is used.
- **`web_ui.layout_template` set to your own layout:** it still works. A layout without the UiKit canonical blocks
  (e.g. rendering `title` + `body` like the removed bundle layout) is wrapped in a deprecated bridge and triggers a
  deprecation; migrate it to the canonical blocks or remove the key.
- **`web_ui.layout_template: '@NowoRuntimeEnvBundle/manage/layout.html.twig'`:** that layout was removed; the value is
  mapped to the UiKit layout.
- The list is paginated (`web_ui.list_page_size`, default 50). Custom repositories may implement
  `PaginatedRuntimeEnvVariableRepositoryInterface` to page in SQL; otherwise the full list is sliced in memory.
- Access checks (REQ-UI-002), CSRF on every mutation and value masking are unchanged.

### Canonical configuration keys

Deprecated keys keep working until 2.0 (the canonical key wins when both are set):

```yaml
# Before (1.2)
nowo_runtime_env:
    panel:
        enabled: true
        path_prefix: /admin/env
    templates:
        index: 'admin/env/index.html.twig'
    web_ui:
        css_framework: bootstrap5
        icon_set: bootstrap-icons

# After (1.3)
nowo_runtime_env:
    web_ui:
        enabled: true
        path_prefix: /admin/env
        templates:
            index: 'admin/env/index.html.twig'
nowo_ui_kit:
    css_framework: bootstrap5
    icon_set: bootstrap-icons
```

Keep your firewall / `access_control` path in sync with `web_ui.path_prefix`.

### Template contract

- `manage/index.html.twig` → `@NowoUiKit/page/list.html.twig` (`page: ListPage`, data block `nowo_ui_list_row`).
- `manage/form.html.twig` → `@NowoUiKit/page/form.html.twig` (`page: FormPage`, block `nowo_ui_styles`).
- Removed: `manage/layout.html.twig`, `manage/_form_fields.html.twig`, the `runtime_env_layout` variable.
- Full-file overrides under `templates/bundles/NowoRuntimeEnvBundle/manage/` written for 1.2 (they extended
  `runtime_env_layout` and filled `body`) must be removed: theme the UiKit scaffolds once instead, or use
  `web_ui.templates.<page>` with `{% extends '@!NowoRuntimeEnvBundle/manage/<page>.html.twig' %}` and override one data
  block. Never print a variable value in an override. Page map: [TEMPLATES.md](TEMPLATES.md).
- `page.rows`, `variables` (index) and `variable` (form) are value-free arrays (`id`, `name`, `description`,
  `enabled`), not entities: `row.value` / `variable.value` no longer exist.

### Security hardening

- `enabled`, `web_ui.enabled`, `panel.enabled` and `security.allow_unauthenticated` must be literal booleans. Replace
  `%env(bool:…)%` on these keys with per-environment config files (`when@prod:` / `config/packages/prod/`).
- `security.allow_unauthenticated: true` now fails the container compilation unless `kernel.debug` is true. Keep it in
  dev / demo config only.
- After a failed submission the value field is printed empty (create and edit): the admin types the value again.

## To 1.2.1

From **1.2.0**:

```bash
composer update nowo-tech/runtime-env-bundle
```

## To 1.2.0

From **1.1.3**:

```bash
composer update nowo-tech/runtime-env-bundle
```

### Manage panel requires form CSRF protection at compile time

While the manage panel is enabled the container no longer compiles without form CSRF protection (`LogicException`: "requires CSRF protection"). Standard Flex hosts (`symfony/security-csrf` installed, `framework.csrf_protection` enabled, `framework.form.csrf_protection` not disabled) need no change. Otherwise enable `framework.csrf_protection: true` — also in the `test` environment of functional suites that enable the panel — or set `panel.enabled: false`.

## To 1.1.3

From **1.1.2**:

```bash
composer update nowo-tech/runtime-env-bundle
```

## To 1.1.2

From **1.1.1**:

```bash
composer update nowo-tech/runtime-env-bundle
```

## To 1.1.1

From **1.1.0** — SECURITY.md covers the 1.1.0 edit form and web_ui.enabled.

```bash
composer update nowo-tech/runtime-env-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.1.0

From **1.0.6** — web_ui options honoured, empty values allowed, secrets no longer pre-filled in the edit form.

```bash
composer update nowo-tech/runtime-env-bundle
```

- `web_ui.enabled: false` now really disables the manage UI (no routes). If you had it set to `false` while still using the panel, remove it.
- Manage templates extend `runtime_env_layout` (from `web_ui.layout_template`). If you set `templates.layout`, move the value to `web_ui.layout_template` (the old key is deprecated but still wins when changed). Custom `templates.index` / `templates.form` overrides may use `{% extends runtime_env_layout %}`.
- The edit form no longer shows the stored value: leave the field blank to keep it, or tick "Clear the value" to empty it. Overridden form templates must render the new `clear_value` field (the default `form_row` loop does).

## To 1.0.6

From **1.0.5** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/runtime-env-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.0.5

From **1.0.4** — dependency refresh only.

```bash
composer update nowo-tech/runtime-env-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.0.4

From **1.0.3** — `access_roles` fail-closed.

```bash
composer update nowo-tech/runtime-env-bundle
php bin/console cache:clear
```

- Empty `security.access_roles` is rejected at config compile unless `allow_unauthenticated` or a custom `access_checker` is set. The default checker denies when given no roles.

## To 1.0.3

From **1.0.2** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/runtime-env-bundle
php bin/console cache:clear
```

- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

This guide provides step-by-step instructions for upgrading Runtime Env Bundle between versions.

## From 1.0.1 to 1.0.2

No configuration key changes. Behaviour notes (FrankenPHP worker hardening for `resetKernel=false`):

- The manage-UI access subscriber now listens on `kernel.request` with priority **7** (was 8) and passes `null` as user to the access checker when no security firewall matched the request. Keep `path_prefix` behind a firewall (as already documented); with `security.allow_unauthenticated: true` nothing changes.
- `DoctrineOrmRuntimeEnvVariableRepository` is wired with `ManagerRegistry` instead of a fixed entity manager. If you instantiate it yourself, `new DoctrineOrmRuntimeEnvVariableRepository($entityManager)` still works; prefer `new DoctrineOrmRuntimeEnvVariableRepository(null, $registry, 'default')`.
- Repository reads refresh managed `RuntimeEnvVariable` entities from the database; unflushed in-memory changes to those entities are discarded on the next read.

```bash
composer update nowo-tech/runtime-env-bundle
```

## From 1.0.0 to 1.0.1

No breaking changes. **No application upgrade steps.** CI-only fixes for the Symfony 8 matrix.

```bash
composer update nowo-tech/runtime-env-bundle
```

## To 1.0.0 (initial release)

This is the first stable release. Install or require the package:

```bash
composer require nowo-tech/runtime-env-bundle:^1.0
```

### Requirements

- PHP `>=8.2` (`<8.6`).
- Symfony **7.4**, **8.0**, or **8.1+**.
- Doctrine ORM + Doctrine Bundle.
- [`nowo-tech/doctrine-encrypt-bundle`](https://packagist.org/packages/nowo-tech/doctrine-encrypt-bundle) `^2.2` (Halite recommended).
- [`nowo-tech/ui-kit-bundle`](https://packagist.org/packages/nowo-tech/ui-kit-bundle) `^1.4`.

### Enable and configure

1. Register the bundle (or use the Symfony Flex recipe — see [Installation](INSTALLATION.md)).
2. Configure DoctrineEncryptBundle (encryptor key in `.env` / key files — never in the runtime-env table).
3. Import routes (`config/routes/nowo_runtime_env.yaml`) or rely on Flex.
4. Protect `panel.path_prefix` with host `access_control` (default `/_runtime_env` → `ROLE_ADMIN`).
5. Create/update the schema (`{table_prefix}_variables`, default `runtime_env_variables`).

See [Installation](INSTALLATION.md) and [Configuration](CONFIGURATION.md).

### Breaking changes

None — there is no prior stable release.

## Future versions

For upgrade instructions between versions, see the [Changelog](CHANGELOG.md).

## Getting help

- [Usage](USAGE.md) — integration examples
- [Configuration](CONFIGURATION.md) — all options
- [GitHub Issues](https://github.com/nowo-tech/RuntimeEnvBundle/issues)
