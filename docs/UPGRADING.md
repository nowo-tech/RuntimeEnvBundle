# Upgrade Guide

## Table of contents

- [Unreleased](#unreleased)
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
