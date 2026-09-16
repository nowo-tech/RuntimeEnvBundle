## AI contribution guidelines (Nowo Symfony bundle)

Use this when suggesting code, tests, documentation, or CI changes for this repository.

### Scope

- This is a **Symfony bundle** for **serial number generation and validation** (`nowo-tech/*` on Packagist).
- Respect **PHP** and **Symfony** ranges in `composer.json`.
- Prefer **PHP 8 attributes**; do not add `doctrine/annotations` for new metadata.

### Code

- Follow **PSR-12** and `.php-cs-fixer.dist.php`.
- Preserve **deterministic and predictable** behavior for serial formats; document any change to algorithms or formats in `docs/` and `CHANGELOG.md`.
- Use strict comparisons where relevant; keep changes minimal and aligned with `src/` and `tests/`.

### Documentation

- User-facing docs in **English** under `docs/` as per Nowo standards.
- README remains the only root markdown file besides standards.

### Tests

- Add or update tests for new serial strategies or edge cases; maintain coverage targets from CI and README.
