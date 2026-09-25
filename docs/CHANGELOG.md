# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.0.2] - 2026-09-25](#102---2026-09-25)
- [[1.0.1] - 2026-09-17](#101---2026-09-17)
- [[1.0.0] - 2026-09-16](#100---2026-09-16)

## [Unreleased]

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
