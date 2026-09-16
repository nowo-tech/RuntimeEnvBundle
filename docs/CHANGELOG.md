# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added

- Initial scaffold: encrypted DB-backed runtime env variables (`#[Encrypted]` + DoctrineEncryptBundle), admin CRUD, `RuntimeEnvBag` with `ResetInterface` / `kernel.reset`, Twig helpers, Flex recipe stub, unit tests.
- Demo `demo/symfony8` (FrankenPHP, `FRANKENPHP_MODE=worker` default, PORT 8026) + `docs/DEMO-FRANKENPHP.md`.
- GitHub Actions CI (PHPUnit + PHPStan) and FrankenPHP Friendly README banner.
- Translations: `en`, `es`, `fr`.
- `TwigPathsPass` for `@NowoRuntimeEnvBundle` templates; Symfony 8–compatible `Regex` constraint on the form.
