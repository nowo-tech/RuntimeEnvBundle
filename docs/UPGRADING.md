# Upgrade Guide

## Table of contents

- [Unreleased](#unreleased)
  - [Manage pages on the UiKit page contract](#manage-pages-on-the-uikit-page-contract)
  - [Canonical configuration keys](#canonical-configuration-keys)
  - [Template contract](#template-contract)
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
