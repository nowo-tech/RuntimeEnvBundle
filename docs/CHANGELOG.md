# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.0.1] - 2026-09-17](#101---2026-09-17)
- [[1.0.0] - 2026-09-16](#100---2026-09-16)

## [Unreleased]

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
