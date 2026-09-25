# Upgrade Guide

This guide provides step-by-step instructions for upgrading Runtime Env Bundle between versions.

## Table of contents

- [From 1.0.1 to 1.0.2](#from-101-to-102)
- [From 1.0.0 to 1.0.1](#from-100-to-101)
- [To 1.0.0 (initial release)](#to-100-initial-release)
- [Future versions](#future-versions)
- [Getting help](#getting-help)

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
