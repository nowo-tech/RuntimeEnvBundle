# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
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

### Changed

- **`nowo-tech/form-kit-bundle` `^2.17`:** FormKit 2.17 keeps an explicit `'label' => false` through `FormOptionsMerger`, so the local `FormKitFieldTrait::addKitField()` workaround is removed and `RuntimeEnvVariableType` add their fields with FormKit's own `FormOptionsTrait::addWithDefaults()` (same options). The rendered forms are unchanged (hidden labels, translations, field names, ids and CSRF tokens). Demo lock updated.

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
