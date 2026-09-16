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
make rector-dry
make validate-translations
```

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.
If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.
