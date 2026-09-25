# Runtime Env Bundle

[![CI](https://github.com/nowo-tech/RuntimeEnvBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/RuntimeEnvBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/runtime-env-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/runtime-env-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/runtime-env-bundle.svg)](https://packagist.org/packages/nowo-tech/runtime-env-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%2B%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** [Install from Packagist](https://packagist.org/packages/nowo-tech/runtime-env-bundle) · Give it a **star** on [GitHub](https://github.com/nowo-tech/RuntimeEnvBundle) so more developers can find it.

**Database-backed application environment variables** with admin CRUD. Values are **encrypted at rest** with [`nowo-tech/doctrine-encrypt-bundle`](https://github.com/nowo-tech/DoctrineEncryptBundle). Designed for **FrankenPHP worker** (no `$_ENV` / `putenv` mutation; safe with or without `kernel.reset` / `services_resetter`).

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly**.

> Bootstrap secrets (`DATABASE_URL`, `APP_SECRET`, encryptor key files, `FRANKENPHP_MODE`, …) **stay in `.env`**. This bundle is for agility on business/integration variables you want to edit without redeploy.

## Features

- Doctrine entity `RuntimeEnvVariable` with `#[Encrypted]` value column
- Admin CRUD at `/_runtime_env` (REQ-UI-002: `ROLE_ADMIN` by default)
- `RuntimeEnvBag` API + Twig `runtime_env('KEY')` — request-scoped memoization (worker-safe even when `resetKernel` is false) + `kernel.reset`
- Configurable `table_prefix` (default `runtime_env` → table `runtime_env_variables`)
- UiKit-friendly markup (`nowo-ui-*`)

## Requirements

- PHP >= 8.2, < 8.6
- Symfony 7.4, 8.0, or 8.1 (see `composer.json`; CI exercises 7.4, 8.0, and 8.1)
- Doctrine ORM + Doctrine Bundle
- [`nowo-tech/doctrine-encrypt-bundle`](https://packagist.org/packages/nowo-tech/doctrine-encrypt-bundle) (Halite recommended)
- Twig 3.12+

## Installation

```bash
composer require nowo-tech/runtime-env-bundle
```

Requires a configured **DoctrineEncryptBundle** profile (Halite recommended). See [docs/INSTALLATION.md](docs/INSTALLATION.md).

Register routes (Flex recipe copies this):

```yaml
# config/routes/nowo_runtime_env.yaml
nowo_runtime_env:
    resource: .
    type: nowo_runtime_env
```

## Configuration

```yaml
# config/packages/nowo_runtime_env.yaml
nowo_runtime_env:
    enabled: true
    table_prefix: runtime_env
    panel:
        enabled: true
        path_prefix: /_runtime_env
    security:
        access_roles: [ROLE_ADMIN]
        allow_unauthenticated: false  # never true in production
```

Full tree: [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

## Usage

```php
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;

public function __construct(private RuntimeEnvBag $runtimeEnv) {}

public function __invoke(): void
{
    $token = $this->runtimeEnv->get('MY_API_TOKEN');
}
```

```twig
{{ runtime_env('MY_API_TOKEN') }}
```

See [docs/USAGE.md](docs/USAGE.md) for more examples.

## Development

```bash
make setup-hooks
make up
make test
make phpstan
make cs-check
```

Demo: `cd demo/symfony8 && make up` → http://localhost:8026 — see [demo/README.md](demo/README.md).

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)
- [Demo with FrankenPHP (development and production)](docs/DEMO-FRANKENPHP.md)
- [FrankenPHP worker audit (`resetKernel=false`)](docs/FRANKENPHP-WORKER-AUDIT.md)
- [GitHub Actions CI requirements](docs/GITHUB_CI.md)
- [PSR evaluation (REQ-CS-007)](docs/PSR.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)

## Tests and coverage

- Tests: PHPUnit (PHP) under `tests/Unit/` and `tests/Integration/` (FrankenPHP worker simulation)
- Coverage:
  - PHP: 100%
  - TS/JS: N/A
  - Python: N/A
- Badge / CI: PHPUnit coverage job on PHP 8.2 + Symfony 7.4

## License

MIT. See [LICENSE](LICENSE).

## Author

[Nowo.tech](https://nowo.tech) · Héctor Franco Aceituno
