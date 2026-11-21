# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.8.1] - 2026-11-21](#181---2026-11-21)
- [[1.8.0] - 2026-11-18](#180---2026-11-18)
- [[1.7.1] - 2026-11-17](#171---2026-11-17)
- [[1.7.0] - 2026-11-17](#170---2026-11-17)
- [[1.6.0] - 2026-11-15](#160---2026-11-15)
- [[1.5.1] - 2026-11-12](#151---2026-11-12)
- [[1.5.0] - 2026-11-11](#150---2026-11-11)
- [[1.4.1] - 2026-10-31](#141---2026-10-31)
- [[1.4.0] - 2026-10-30](#140---2026-10-30)
- [[1.3.0] - 2026-10-30](#130---2026-10-30)
- [[1.2.1] - 2026-10-28](#121---2026-10-28)
- [[1.2.0] - 2026-10-28](#120---2026-10-28)
- [[1.1.3] - 2026-10-22](#113---2026-10-22)
- [[1.1.2] - 2026-10-18](#112---2026-10-18)
- [[1.1.1] - 2026-10-13](#111---2026-10-13)
- [[1.1.0] - 2026-10-13](#110---2026-10-13)
- [[1.0.6] - 2026-10-13](#106---2026-10-13)
- [[1.0.5] - 2026-10-09](#105---2026-10-09)
- [[1.0.4] - 2026-09-28](#104---2026-09-28)
- [[1.0.3] - 2026-09-27](#103---2026-09-27)
- [[1.0.2] - 2026-09-25](#102---2026-09-25)
- [[1.0.1] - 2026-09-17](#101---2026-09-17)
- [[1.0.0] - 2026-09-16](#100---2026-09-16)

## [Unreleased]

## [1.8.1] - 2026-11-21

### Changed

- demo: the demo runs inside UiKit's demo platform shell (`@NowoUiKit/demo_platform/layout.html.twig`, UiKit ^2.60): shared top bar, aside navigation and section cards, no demo masthead markup; the Web Debug Toolbar and the CDN hot-reload scripts are off in the demo and its README screenshots. Demo only, no release.
- CI: new `deps-floor` job (as in BreadcrumbKit). It checks that the demo lock installs every nowo-tech requirement of the bundle (`.scripts/check-demo-lock.py`), then downgrades nowo-tech/transaction-* to the lowest versions composer.json allows (`composer update 'nowo-tech/transaction-*' --prefer-lowest`) and runs PHPStan and the tests there, so a call to a newer API than the constraint allows fails the pull request.
- Demo: `make update-bundle` runs `composer update nowo-tech/runtime-env-bundle -w`, so the bundle's new or raised requirements reach the demo lock too.
- Requires `nowo-tech/transaction-doctrine` ^1.8.1 (fixes GHSA-9wh3-m3m5-jffx and GHSA-65w2-9r4h-848g).

### Fixed

- Schema: the collation of the key columns (variable `name`, version `namespace`) is now applied on `postGenerateSchema`, once the schema tool has added every foreign key, instead of replacing the variables and versions tables on `postGenerateSchemaTable` (audit XI). On DBAL 4.5+ `KeyCollationListener` replaced the table with `setClassTable()`, and ORM 3.7–3.8's schema tool then added the foreign keys that start on that table to the replaced object, so on MySQL / MariaDB they were lost from the generated schema, with their indexes (the bundle tables have no foreign key today, so nothing was lost yet; a host association onto them would have been). On DBAL 4.5+ the tables are now rebuilt with their table editor and swapped into a new schema (`GenerateSchemaEventArgs::setSchema()`), which keeps every foreign key and index; on older DBAL (and ORM versions without `setSchema()`) the column options are set in place. Managers that do not map the bundle entities are skipped, the exact collations are unchanged, and `KeyCollationListener::postGenerateSchemaTable()` stays as a deprecated no-op (the constructor's registry argument is unused but kept). The old listener also checked only `Table::edit()`, not `setClassTable()`, so on ORM 3.6 with DBAL 4.5+ (both allowed by the constraints) generating the schema was a fatal error (`Call to undefined method GenerateSchemaTableEventArgs::setClassTable()`); it no longer is.
- Demo: `demo/symfony8/composer.lock` now installs nowo-tech/transaction-doctrine 1.6.0. The locks of 1.6.0 to 1.8.0 did not install nowo-tech/transaction-doctrine, nowo-tech/transaction-contract or nowo-tech/runtime-setting-contract, which the bundle already required (^1.5): the demo installs from its lock, so it failed with class not found (`CommitLedger`). The bundle's composer.json constraint was already right; hosts are not affected.

[1.8.1]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.8.1

## [1.8.0] - 2026-11-18

### Added

- `conflict_response.status` (409 default, or 503) and `conflict_response.retry_after`: the answer of a lock conflict on the bundle's routes. Unset, they follow nowo-tech/transaction-doctrine's `conflict_response` when its bundle is registered.
- `CONFLICT_001` (nothing committed, with `Retry-After`) and `CONFLICT_002` (part of the request committed, no `Retry-After`) in every conflict answer, in the `X-Conflict-Code` header. A `CommitLedger` counts the real commits of the bundle's connection (`RuntimeEnvOutbox`).
- `docs/CONCURRENCY.md`: write paths, codes, host options, residual risks.
- Two-connection tests on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16: lock wait answers `CONFLICT_001`, lock wait after a committed unit answers `CONFLICT_002`, duplicate name answers the domain 409.

### Changed

- A deadlock, lock wait timeout or serialization failure on the manage pages answers **409** (was 503); set `conflict_response.status: 503` to keep 503. `Retry-After` stays on `CONFLICT_001`.
- A duplicate name (unique key) still answers 409, now with the domain code `RUNTIME_ENV_NAME_TAKEN` in `X-Error-Code` (`X-Conflict-Code` only ever holds `CONFLICT_001` / `CONFLICT_002`).
- The lock-conflict answer of a page is an `HttpException` (was `ServiceUnavailableHttpException`).
- Requires `nowo-tech/transaction-doctrine` `^1.5` (was `^1.1`).

[1.8.0]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.8.0

## [1.7.1] - 2026-11-17

### Changed

- The `nowo_runtime_env` asset package is registered only when `symfony/asset` is installed (`class_exists(Package::class)` guard); with it installed nothing changes.

### Documentation

- USAGE / UPGRADING: the package is local; the host's default `framework.assets.base_urls` (CDN) and `base_path` are not inherited.

### Tests

- Integration test of the rendered asset URL (`/bundles/noworuntimeenv/css/runtime-env.css`), with and without host `base_urls` / `base_path` (audit SE follow-up).

[1.7.1]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.7.1

## [1.7.0] - 2026-11-17

### Changed

- Demo: `make test-e2e` uses the Playwright image that matches `e2e/package.json` (1.65).
- `prepend()` registers the asset package `nowo_runtime_env` (`/bundles/noworuntimeenv`) and `manage/form.html.twig` loads `css/runtime-env.css` through it; the URL is unchanged (REQ-ASSETS-004).

### Tests

- Coverage is 100% again in CI: the persistence test kernel compiled its container into a cache directory shared between runs, so the coverage run reused the container built by `composer test` and never executed the extension's `prepend()` paths (99.49% reported). The cache directory now carries the process id.
- CI: the coverage run is followed by a 100% line gate (`.scripts/coverage-fail-under.sh`); `make test-coverage` gate raised from 99 to 100.
- `make test-with-db` / `make test-coverage-with-db`: the real-database group on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 containers (compose profile `db`).

### Docs

- Spec Kit inventory maps `RuntimeSetting/RuntimeEnvVersionRow.php` (60 artifacts); `docs/ENGRAM.md` points at the upstream Engram repository.

### CI

- Dependabot: npm entry for `demo/symfony8/e2e`.

[1.7.0]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.7.0

## [1.6.0] - 2026-11-15

### Added

- **Runtime settings backend (nowo-tech/runtime-setting-contract 1.0, opt-in: `runtime_setting.enabled`, default
  `false`).** A bundle setting that lives in `.env` can come from this table instead, change live through
  `nowo:runtime-setting:*` or the admin UI, and rotate without downtime.
  - `RuntimeSetting\RuntimeEnvSettingStore` implements `RuntimeValueSourceInterface` and `RuntimeSettingWriterInterface`
    on `{prefix}_variables`: the key is the environment variable name, values are encrypted in the entity's
    `#[Encrypted]` format, reads go through DBAL on every call (no identity map), writes run in their own transaction
    through transaction-doctrine (never flushing, clearing or resetting an entity manager; refused inside a caller's
    transaction), rotation keeps the previous value with an expiry (UTC wall-clock time), `purgeExpired()` is one conditional
    `UPDATE` (a write purges only its own row, so writes of different variables never wait for each other on MySQL /
    MariaDB), a write locks only an existing row, and every write (also an identical one) changes a never-reused
    version token of the `env` namespace in `{prefix}_versions` in the same transaction; the version row is created
    with a statement that cannot conflict (`ON DUPLICATE KEY` / `ON CONFLICT DO NOTHING`). `unset` disables the
    variable and empties a secret's value. After the commit: an audit log line (actor, name, action,
    fingerprints, none for secrets) and `RuntimeEnvVariableChangedEvent`.
  - The 1.5 writer (`RuntimeEnvWriter`: admin UI and host code) changes the version token in the same flush as its
    write and drops the previous value of a rotation on a plain update.
  - The contract ports are bound to the store only when nothing else is (`RuntimeSettingPortsPass`, before
    NowoRuntimeSettingBundle's default).
  - With NowoRuntimeSettingBundle registered, admin edits of a registered writable setting go through
    `RuntimeSettingUpdater`: same validator, bounds and secret rules as the command, audit actor `user:<identifier>`.
    Other variables keep the free-form path.
- **Binary NO PAD collation of the key columns on MySQL / MariaDB** (`Doctrine\KeyCollationListener`, REQ-SEC-006),
  with `runtime_setting.enabled` only: `name` (and `namespace` of the versions table) in the generated schema. With
  the feature off, `migrations:diff` stays empty.
- CI job "Real databases" (MySQL 8.4, MariaDB 11.4, PostgreSQL 16): the store and the kept-alive kernel test on real
  servers (`phpunit --group database`).
- **503 / 409 on the bundle's routes** (`Transaction\StorageFailureListener`): a transient storage failure answers
  503 with `Retry-After`, a unique-key conflict 409, instead of 500.

### Known issues

- QT-L-5 (previous value not covered by DoctrineEncrypt key rotation) and QT-L-6 (admin delete of a registered
  setting writes no actor audit line): see [UPGRADING](UPGRADING.md#known-issues-fixed-in-a-16x-patch), fixed in a
  1.6.x patch.
- QT-D-I-1: on MySQL / MariaDB with `database.transaction: host`, the version-row `ensure()` of the 1.5 writer's
  bumper may hit a transient deadlock (503) with a concurrent store write of the same variable; fixed in a 1.6.x patch.
- QT-D-I-2: with a very long `table_prefix`, the name of the `previous_expires_at` index may exceed MySQL's 64
  characters.

### Changed

- Demo uses the UiKit base style (REQ-DEMO-014)
- Requires `nowo-tech/runtime-setting-contract ^1.0`, `nowo-tech/transaction-contract ^1.0` and
  `nowo-tech/transaction-doctrine ^1.1` (interfaces and a small DBAL runner; nothing changes unless
  `runtime_setting.enabled` is true).

[1.6.0]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.6.0

## [1.5.1] - 2026-11-12

Next patch: 1.5.1.

### Fixed

- **Unflushed write events discarded mid-unit, and console boundaries that could leak (REQ-RUNTIME-002, audits NM,
  NL2-v and NL2-v2).** `Persistence\RuntimeEnvTransaction` discarded the events of unflushed writes at every
  `kernel.terminate`, `kernel.exception`, `console.error`, `console.terminate` and Messenger handled / failed. With
  `database.transaction: host`, a nested command, an unknown nested command (`console.error` without a command), a
  command run from a handler or a controller, a request handled inside a command, or a sub-request / nested main
  request that failed dropped the outer unit's write events: the host's later flush wrote the variables but no
  `RuntimeEnvVariableChangedEvent` was dispatched. Unflushed events are now discarded at the OUTERMOST boundary only;
  nested boundaries still dispatch what was committed. Which unit is outermost is tracked by the new internal
  `Worker\UnitOfWorkFrames` (same design as PageBuilderKitBundle), fed at `PHP_INT_MAX` so a listener that throws or
  stops propagation cannot unbalance it: one frame per console run keyed by the Command AND its input, saving the
  outer Messenger / main-request state and restoring it when the frame is removed; a terminate without a frame never
  removes another run's frame; `console.error` marks the frame as failing and a frame left failing by a throwing error
  listener is dropped at the next main request, message, command or worker stop (before the handler writes its own
  flag); a main-request depth (`kernel.request` → `kernel.finish_request`). `kernel.exception` (which may never run:
  FrameworkBundle's error listener stops propagation at -128) now only dispatches committed events; `kernel.terminate`
  is the HTTP boundary. `WorkerMessageSkipEvent` (only dispatched by `messenger:failed:retry`) ends the message in
  flight. The frames are not dropped by `kernel.reset` (only the pending events are). The top-level `kernel.terminate`
  (`PHP_INT_MAX`) forgets a main-request depth left behind when `kernel.finish_request` never came (a
  `kernel.exception` listener that threw), so the next requests stay the outermost unit and keep discarding their
  unflushed events (audit NR-c F1).

### Tests

- `RuntimeEnvTransactionTest`: nested command (succeeding and failing), unknown nested command, request inside a
  command, message inside a command, command inside a request, worker stop, a failed run healed at the next request,
  `kernel.reset` keeps the frames.
- `RuntimeEnvPersistenceKernelTest::testConsoleProbesNeverDiscardTheOuterUnitAndNeverStickTheProcessInsideAUnit`
  (`transaction: host`, real console `Application`, kept-alive kernel without the services resetter): throwing
  terminate listener of a nested command, unknown nested command, nested `console.command` stopped above 4096 keep the
  outer unflushed event (the host flush dispatches it); after those and a self-recursive command with a throwing error
  listener, an error listener at `PHP_INT_MIN`, a throwing message-received listener inside a command and a failing
  command inside a message then the worker stop, the next main request is the outermost unit again.
- `RuntimeEnvPersistenceKernelTest::testThrowingExceptionListenerNeverLeavesARequestInFlight`: a throwing
  `kernel.exception` listener (with and without `kernel.terminate` afterwards), then two normal requests that discard
  their unflushed events and no phantom event after a later host flush.
- Unit tests for `UnitOfWorkFrames`.

[1.5.1]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.5.1

## [1.5.0] - 2026-11-11

database.entity_manager really applies; database.transaction bundle|host; RuntimeEnvVariableChangedEvent after the outermost commit, never carrying the value; reader and writer ports; host managers are never reset. See [UPGRADING](https://github.com/nowo-tech/RuntimeEnvBundle/blob/v1.5.0/docs/UPGRADING.md#to-150).

### Fixed

- `database.entity_manager` was half-wired: the mapping was always prepended to the default manager, so another
  manager name did not map the entity, and the repository reset ANY closed manager it used, including the host's
  default one. Now the entity is mapped on that manager ONLY (`RuntimeEnvEntityManagerPass`, also off an
  `auto_mapping: true` default), every read and write uses it, the host's `default_entity_manager` stays the host's,
  and the build fails clearly when the manager is not declared. The bundle resets / clears a manager only when it owns
  it (it is not the host's default manager): reset when closed, cleared at every request / message / command boundary.

### Added

- `database.transaction: bundle|host` (default `bundle`). `host` (DDD hosts): when your code calls the writer it does
  not flush; your use case commits. The admin UI keeps flushing its own writes.
- `RuntimeEnvVariableChangedEvent` (name, action, at, previous name; never the value), dispatched after the real
  commit, never inside a flush or an open transaction, and never for a rolled-back write: `AfterCommitMiddleware` on
  the bundle connection reports commits, rollbacks and savepoints to a per-transaction outbox. `RuntimeEnvPendingEventsInterface::dispatchPending()` for hosts that
  commit mid-process.
- `RuntimeEnvReaderInterface` (implemented by `RuntimeEnvBag`) and the name-based `RuntimeEnvWriterInterface`
  (`createVariable()`, `updateByName()`, `renameByName()`, `deleteByName()`; implemented by `RuntimeEnvWriter`), both
  autowirable, no entity in the signatures. The entity-based writer methods stay.
- CI runs `composer igor` (worker-state audit, REQ-CS-008).

[1.5.0]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.5.0

## [1.4.1] - 2026-10-31

Requires FormKit ^2.17; local label:false workaround removed (forms render identically). See [UPGRADING](UPGRADING.md#to-141).

### Changed

- **`nowo-tech/form-kit-bundle` `^2.17`:** FormKit 2.17 keeps an explicit `'label' => false` through `FormOptionsMerger`, so the local `FormKitFieldTrait::addKitField()` workaround is removed and `RuntimeEnvVariableType` add their fields with FormKit's own `FormOptionsTrait::addWithDefaults()` (same options). The rendered forms are unchanged (hidden labels, translations, field names, ids and CSRF tokens). Demo lock updated.

[1.4.1]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.4.1

## [1.4.0] - 2026-10-30

Page forms are FormKit types with a bundle FormKit profile; requires nowo-tech/form-kit-bundle (REQ-TWIG-005 rule 11). See [UPGRADING](UPGRADING.md#to-140).

### Changed

- **Forms are FormKit Types (REQ-TWIG-005 rule 11):** `nowo-tech/form-kit-bundle` (`^2.16`) is now a required dependency. `RuntimeEnvVariableType` and the CSRF-only `RuntimeEnvDeleteType` carry `#[FormKitConfig('runtime_env')]` + `FormOptionsTrait` (fields added through FormKit), themed by the FormKit profile `nowo_form_kit.profiles.runtime_env` the bundle prepends (translation domain `NowoRuntimeEnvBundle`, no auto help / placeholder keys, no framework classes: the UiKit form scaffold paints the fields). Host override: define `nowo_form_kit.profiles.runtime_env` yourself. Field names, CSRF token ids and the rendered markup are unchanged.

### Added

- Integration test `HostControllersRoutesTest`: a host whose routing is only `routing.controllers` (Symfony >= 7.3 default) gets no bundle route; the manage controller is already registered with `setAutoconfigured(false)` (REQ-SF-004 rule 7).

[1.4.0]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.4.0

## [1.3.0] - 2026-10-30

RuntimeEnv: UiKit page contract (list/form scaffolds, confirm dialog), #[Route] with prefix loader, paginated list; SEC-004 BM: value-free view models, literal-boolean switches, allow_unauthenticated debug-only, secrets never re-rendered See [UPGRADING](UPGRADING.md#to-130).

UiKit page contract for the manage UI (REQ-UI-003), paginated list (REQ-PERF-001), attribute routes (REQ-SF-004).
See [UPGRADING](UPGRADING.md#unreleased).

### Added

- **UiKit page contract (REQ-UI-003).** The manage pages extend the UiKit list / form scaffolds and render inside
  `nowo_ui_kit.layouts.admin`; view models come from `Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPageFactory`, the layout and
  per-page overrides from `RuntimeEnvPages`. Deletes go through the UiKit confirm `<dialog>` (CSRF form unchanged); the
  edit page gains a delete zone. Page map: [TEMPLATES.md](TEMPLATES.md).
- `web_ui.path_prefix`, `web_ui.templates.<page>` (`index`, `form`; unknown ids fail the compilation) and
  `web_ui.list_page_size` (default 50, 1–500).
- **Paginated manage list (REQ-PERF-001):** `?page=N`, one `COUNT` + one bounded `SELECT` per page through the new
  `PaginatedRuntimeEnvVariableRepositoryInterface` (implemented by the Doctrine repository); custom repositories that
  only implement `RuntimeEnvVariableRepositoryInterface` are sliced in memory.
- `src/Resources/public/css/runtime-env.css` (masks what is typed in the edit value field; `assets:install`).
- New translation keys (`runtime_env.index.value_masked|enabled_yes|enabled_no`, `runtime_env.form.new_intro`,
  `runtime_env.action.cancel`, `runtime_env.confirm.*`) in en, es, fr, it, pt, de, nl; flashes are translated in the
  controller.
- Tests: UiKit `PageContractTest` (every page, plus a check that no page prints a stored value), legacy layout bridge,
  route attributes parity, paginated repository reads.

### Changed

- **Attribute routes (REQ-SF-004):** the four manage routes are declared with `#[Route]` on
  `RuntimeEnvManageController`; `RuntimeEnvRouteLoader` reads them and prepends `web_ui.path_prefix`. Route names,
  paths, methods and requirements are unchanged. The controller is not autoconfigured, so a host
  `routing.controllers` import never mounts the routes without the prefix.
- `web_ui.layout_template` defaults to `null` (= `nowo_ui_kit.layouts.admin`); requires `nowo-tech/ui-kit-bundle` ^2.17.3.
- `make validate-translations` compares leaf keys (it only compared the top-level node before).

### Deprecated

- `panel.enabled`, `panel.path_prefix` → `web_ui.enabled`, `web_ui.path_prefix`.
- `templates.index`, `templates.form` → `web_ui.templates.index`, `web_ui.templates.form` (`templates.layout` was
  already deprecated).
- `web_ui.css_framework`, `web_ui.icon_set` → `nowo_ui_kit.css_framework`, `nowo_ui_kit.icon_set` (still copied there
  when the host did not set them).
- Layouts without the UiKit canonical blocks are wrapped in `manage/_legacy_layout.html.twig` with a deprecation
  (removed in 2.0).

### Removed

- `manage/layout.html.twig` (full HTML document with inline CSS) and `manage/_form_fields.html.twig`; the value
  `@NowoRuntimeEnvBundle/manage/layout.html.twig` is mapped to the UiKit layout.
- The `runtime_env_layout` template variable.

### Security

Fixes of the REQ-SEC-004 re-audit 2026-10-30 (Appendix BM, Pass (good) / Low); see [SECURITY](SECURITY.md).

- **No value in the view models (L1).** List rows are value-free arrays (`RuntimeEnvPageFactory::row()`), the value
  column key is `masked_value`, and no entity reaches the page contexts (`variables` / `variable` are value-free
  arrays): a host template extending the plain UiKit list scaffold can no longer print decrypted values.
- **Literal booleans for security switches (L2).** `enabled`, `web_ui.enabled`, `panel.enabled` and
  `security.allow_unauthenticated` refuse `%env(bool:…)%` placeholders, which read as true while the container is built
  (fail open).
- **`allow_unauthenticated: true` only with `kernel.debug` (L3).** `UnauthenticatedAccessPass` refuses to compile the
  container otherwise while the manage UI is enabled.
- **Typed secrets are never re-rendered (L4).** After a failed submission the value field is printed empty on create
  and edit; the create field is masked too. Firefox ignores `-webkit-text-security` (documented).
- An invalid edit no longer modifies the managed entity (the form edits a detached copy); embedded sub-requests
  (`render(controller())`) and array `_controller` values of the bundle controller are access-checked; dev files
  (`.specify/`, `.coderabbit.yaml`, `.cursorignore`, `.scripts/`, `igor.json`, `composer.lock`) are export-ignored.

### Template contract

- `manage/index.html.twig` extends `@NowoUiKit/page/list.html.twig` (variable `page: ListPage`, data block
  `nowo_ui_list_row`); `manage/form.html.twig` extends `@NowoUiKit/page/form.html.twig` (variable `page: FormPage`,
  block `nowo_ui_styles`). Full-file overrides of these templates written for 1.2 must be redone (see UPGRADING).
- List rows (`page.rows`, `variables`) and the form `variable` are value-free arrays `{id, name, description, enabled}`,
  not `RuntimeEnvVariable` entities; the value column key is `masked_value`.

[1.3.0]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.3.0

## [1.2.1] - 2026-10-28

Security: the manage UI access check also applies when the routes are mounted under another prefix. See [UPGRADING](UPGRADING.md#to-121).

### Security

- **Manage UI guard no longer depends on the URL.** `RuntimeEnvAccessSubscriber` only checked requests under
  `path_prefix`; importing the routes with another `prefix:` served the manage UI outside it (and outside the recipe's
  `access_control`) without the soft gate. Requests handled by the bundle's controllers are now always checked.

[1.2.1]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.2.1

## [1.2.0] - 2026-10-28

Security: the admin UI refuses to compile without form CSRF protection (CsrfProtectionPass, fail closed). See [UPGRADING](UPGRADING.md#to-120).

### Security

- CSRF fails closed: new `CsrfProtectionPass` makes the container refuse to compile while the manage panel is registered (`panel.enabled` and `web_ui.enabled` true) and form CSRF protection is unavailable (`security.csrf.token_manager` or `form.type_extension.csrf` missing, or `framework.form.csrf_protection: false`). Before, variable delete (`RuntimeEnvDeleteType` declares its own CSRF options) and the create / edit forms accepted tokenless cross-site POSTs in that configuration. See [UPGRADING](UPGRADING.md#unreleased) and [SECURITY](SECURITY.md#threat-model).

[1.2.0]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.2.0

## [1.1.3] - 2026-10-22

Dependency refresh, MySQL-only demo with a real release check, explicit worker reset, open-PR and coverage release gates. See [UPGRADING](UPGRADING.md#to-113).

### Changed

- Demo: the unused PostgreSQL `database` service left by the Doctrine Flex recipe (and the `compose.override.yaml` that published its port) is removed; MySQL `db` is the only demo database and publishes no port (REQ-DEMO-006, REQ-DEMO-011). The demo release check syncs the bundle (`update-bundle`), runs the demo checks (`make test` = container + Twig lint) and requires a final HTTP 200 (REQ-DEMO-007).
- `RuntimeEnvBag::reset()` clears its memoized values directly, so Igor reports no "review reset" warning (REQ-CS-008); Rector targets the PHP 8.2 floor (REQ-CS-003).
- `release-check` starts with `ensure-up` and runs the new `check-open-prs`; `make test-coverage` fails under 99% (REQ-MAKE-002, REQ-TEST-003).
- USAGE documents translation overrides (REQ-I18N-001); PSR.md gives the real PSR-3 reason (REQ-CS-007); the co-author check accepts git worktrees.

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Documentation: wave-1 REQ compliance — README documentation list order (Code of Conduct in the base list, extras under Additional documentation), Installation before Requirements, GitHub stars badge, canonical bug report template, DEMO-FRANKENPHP dev/prod start and troubleshooting, demo `APP_SHARE_DIR` comment, CONTRIBUTING Cursor Agent settings, TOCs for long docs.

[1.1.3]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.1.3

## [1.1.2] - 2026-10-18

Allows nowo-tech/ui-kit-bundle 2.x (^1.4 || ^2.2) so hosts can move to UiKit 2.2. See [UPGRADING](UPGRADING.md#to-112).

### Changed

- Allows nowo-tech/ui-kit-bundle 2.x (`^1.4 || ^2.2`); the dev lock and the demo lock use 2.2.0. Templates are unchanged (compatibility release).

[1.1.2]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.1.2

## [1.1.1] - 2026-10-13

### Changed

- `docs/SECURITY.md`: threat model and defaults cover the 1.1.0 edit form (stored value never rendered) and `web_ui.enabled: false`.

[1.1.1]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.1.1

## [1.1.0] - 2026-10-13

### Fixed

- `web_ui.enabled: false` is now honoured: the manage UI routes, controller and access subscriber are not registered (previously the value was only stored as a parameter).
- `web_ui.layout_template` is now used: the manage templates extend the `runtime_env_layout` variable passed by the controller instead of a hard-coded bundle layout.
- Variables can hold an empty string: the `NotBlank` constraint on the value field was dropped.

### Security

- The edit form no longer pre-fills the decrypted value. The value field starts empty and masked; a blank submission keeps the current value, a new value replaces it and the new `clear_value` checkbox stores an empty string. New translation keys in all 7 locales.

### Deprecated

- `templates.layout`: use `web_ui.layout_template`. It is still honoured (and wins) when changed from the default.

[1.1.0]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.1.0

## [1.0.6] - 2026-10-13

### Dependencies

- `nowo-tech/ui-kit-bundle` 1.9.1 → 1.11.0 (development lock; #9).
- `nowo-tech/doctrine-encrypt-bundle` 2.4.3 → 2.5.0 (development lock; #10).
- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #11).

[1.0.6]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.0.6

## [1.0.5] - 2026-10-09

### Dependencies

- Dependabot: `nowo-tech/doctrine-encrypt-bundle` 2.4.2, `nowo-tech/ui-kit-bundle` 1.8.5, `igor-php/igor-php` `^0.10.0` (dev), `nowo-tech/phpstan-frankenphp` 1.2.1, phpstan group.
- Composer refresh: `nowo-tech/doctrine-encrypt-bundle` 2.4.3, `nowo-tech/ui-kit-bundle` 1.9.1, `doctrine/orm` 3.7.4, Symfony 7.4.20 components (lockfile); dev tooling `phpstan/phpstan` 2.3.1, `rector/rector` 2.7.0.
- Demo: Symfony 8.1.8, `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, `twig/twig` 3.30.0; regenerated `config/reference.php`.
- Code style: function imports in `Configuration` (PHP CS Fixer).

[1.0.5]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.0.5

## [1.0.4] - 2026-09-28

### Security

- **Fail-closed `access_roles`:** empty role lists are rejected at config compile unless `allow_unauthenticated` or a custom `access_checker` is set. `ConfigurableRuntimeEnvAccessChecker` denies access when given no roles (was allow-all).

## [1.0.3] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.0.4]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.0.4
[1.0.3]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.0.3

## [1.0.2] - 2026-09-25

### Fixed

- **FrankenPHP worker mode without `kernel.reset`:** `RuntimeEnvBag` binds its memoized map to the current main request (optional `RequestStack` constructor argument), so every new request reloads enabled variables even when `services_resetter` does not run.
- **Doctrine freshness / recovery:** `DoctrineOrmRuntimeEnvVariableRepository` resolves the entity manager per call through `ManagerRegistry` (`doctrine` + configured `database.entity_manager`), replaces a closed manager, resets it after a failed flush (for example a unique-name race), and reads with `Query::HINT_REFRESH` so values changed by another worker are never served from a stale identity map. The legacy `EntityManagerInterface` constructor argument is still accepted.
- **Access subscriber:** runs at priority `7` (after the security firewall) and only trusts the stored token when a firewall matched the current request (`_firewall_context`), so a token left by a previous worker request is never reused.

### Added

- Worker audit document [`docs/FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md) (scenario B verdict: viable).
- Integration tests simulating two worker threads without `reset()` (`tests/Integration/Repository/`).

### Notes

- No configuration key changes. See [UPGRADING.md](UPGRADING.md) for behaviour notes (subscriber priority, repository wiring).

[1.0.2]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.0.2

## [1.0.1] - 2026-09-17

### Fixed

- **CI:** Symfony 8.0 / 8.1 matrix jobs resolve correctly by requiring `doctrine/doctrine-bundle` `^3.2` and `doctrine/orm` `^3.4`, and by pinning the full Symfony component set (including `event-dispatcher`) before a full `composer update`.

### Notes

- **No API or configuration changes** for integrators.

[1.0.1]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.0.1

## [1.0.0] - 2026-09-16

### Added

- Initial public release: encrypted DB-backed runtime env variables (`#[Encrypted]` + DoctrineEncryptBundle), admin CRUD, `RuntimeEnvBag` with `ResetInterface` / `kernel.reset`, Twig helpers, Flex recipe stub, and unit tests.
- Demo `demo/symfony8` (FrankenPHP, `FRANKENPHP_MODE=worker` default, PORT 8026) + `docs/DEMO-FRANKENPHP.md`.
- GitHub Actions CI (PHPUnit + PHPStan) and FrankenPHP Friendly README banner.
- Translations: `en`, `es`, `fr`, `it`, `pt`, `de`, `nl`.
- `TwigPathsPass` for `@NowoRuntimeEnvBundle` templates; Symfony 8–compatible `Regex` constraint on the form.
- Narrow `RuntimeEnvManageController::delete()` return type to `RedirectResponse`.

### Notes

- First stable release. See [UPGRADING.md](UPGRADING.md) for install steps.
- REQ-SEC-004 AI security audit: **Pass (conditional)** (2026-09-16). See [SECURITY.md](SECURITY.md).

[1.0.0]: https://github.com/nowo-tech/RuntimeEnvBundle/releases/tag/v1.0.0
