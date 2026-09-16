# Upgrade Guide

This guide provides step-by-step instructions for upgrading Runtime Env Bundle between versions.

## Table of contents

- [To 1.0.0 (initial release)](#to-100-initial-release)
- [Future versions](#future-versions)
- [Getting help](#getting-help)

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
