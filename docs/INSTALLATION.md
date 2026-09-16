# Installation

## Requirements

- PHP `>=8.2 <8.6`
- Symfony `^7.4 || ^8.0`
- Doctrine ORM + DoctrineBundle
- [`nowo-tech/doctrine-encrypt-bundle`](https://github.com/nowo-tech/DoctrineEncryptBundle) `^2.2` (hard dependency)
- [`nowo-tech/ui-kit-bundle`](https://github.com/nowo-tech/UiKitBundle) `^1.4`

## Composer

```bash
composer require nowo-tech/runtime-env-bundle
```

With Symfony Flex, the recipe registers the bundle and copies:

- `config/packages/nowo_runtime_env.yaml`
- `config/packages/security_nowo_runtime_env.yaml`
- `config/routes/nowo_runtime_env.yaml`

## DoctrineEncryptBundle

Configure a Halite (or Defuse) profile **before** storing variables. The encryptor key must live in `.env` / key files — never in the runtime-env table.

```yaml
# config/packages/nowo_doctrine_encrypt.yaml
nowo_doctrine_encrypt:
    default_profile: default
    profiles:
        default:
            encryptor_class: Halite
            secret_key_path: '%kernel.project_dir%/config/secrets/halite.key'
```

Generate a key (see DoctrineEncryptBundle docs):

```bash
php bin/console nowo:doctrine-encrypt:generate-secret-key
```

## Schema

Create/update the schema (table name = `{table_prefix}_variables`, default `runtime_env_variables`):

```bash
php bin/console doctrine:schema:update --force
# or a proper migration
```

## Firewall

Protect the panel prefix (recipe adds an `access_control` example):

```yaml
security:
    access_control:
        - { path: ^/_runtime_env, roles: ROLE_ADMIN }
```
