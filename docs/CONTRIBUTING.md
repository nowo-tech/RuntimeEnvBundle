# Contributing

Contributions are welcome. Please open an issue or a pull request on the [GitHub repository](https://github.com/nowo-tech/RuntimeEnvBundle) (Composer package: `nowo-tech/runtime-env-bundle`).

- Follow PSR-12 and the project's PHP-CS-Fixer configuration.
- Add tests for new behaviour. Tests live under `tests/Unit/` (isolated) and optionally `tests/Integration/` (container/kernel). Run all with `make test`, or only `composer test-unit`.
- Keep documentation (README, docs/) in sync with code changes.

Run quality checks locally:

```bash
make setup-hooks
make install
make cs-check
make test
make phpstan
make igor
make rector-dry
make validate-translations
```

## Table of contents

- [Code of Conduct](#code-of-conduct)
- [Cursor Agent](#cursor-agent)
- [Git hooks (REQ-GIT-001)](#git-hooks-req-git-001)
- [End-to-end tests and screenshots (REQ-DEMO-013)](#end-to-end-tests-and-screenshots-req-demo-013)

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Cursor Agent

Contributors using Cursor Agent on this repository should enable these user-level settings (REQ-IDE-006):

1. **Submit with Ctrl + Enter** (macOS: **⌘ + Enter**) — Cursor Settings → Agents / Chat. Enter inserts a newline; Ctrl/⌘+Enter submits.
2. **Queue follow-ups** in user `settings.json` (Linux `~/.config/Cursor/User/settings.json`, Windows `%APPDATA%\Cursor\User\settings.json`, macOS `~/Library/Application Support/Cursor/User/settings.json`):

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```

Do not use `"steer"` as the default: it interrupts a running agent turn.

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers, nor `Co-Authored-By: Claude …` / `noreply@anthropic.com` ones, to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.
If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.

Maintainers run `make check-no-cursor-coauthor` **after** creating a release commit and **before** `git push` (REQ-GIT-001): an earlier `release-check` run does not cover the release commit itself.

## End-to-end tests and screenshots (REQ-DEMO-013)

The Symfony 8 demo ships a Playwright suite in `demo/symfony8/e2e/` (official Playwright image, nothing to install on the host):

```bash
make -C demo/symfony8 test-e2e          # starts the demo if needed, runs the happy-path specs
make -C demo/symfony8 demo-screenshots  # recaptures docs/images/demo/*.webp (PNG → WebP via sharp)
```

The specs create their own variables in the demo database (and delete the temporary `E2E_*` ones). Refresh the screenshots when the demo UI changes and commit only the `.webp` files.
