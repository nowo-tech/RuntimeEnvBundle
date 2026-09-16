# Release

Maintainers: follow this checklist before creating a new tag.

## Pre-release checklist

1. **Update version and docs**
   - Ensure [CHANGELOG.md](CHANGELOG.md) has an entry for the new version (e.g. `[1.0.12] - YYYY-MM-DD`) and that `[Unreleased]` is updated or empty.
   - Ensure [UPGRADING.md](UPGRADING.md) mentions any behaviour changes for that version if needed.

2. **Run quality checks**

   ```bash
   make release-check
   ```

   This runs: `check-no-cursor-coauthor`, `composer-sync`, `cs-fix`, `cs-check`, `rector-dry`, `phpstan`, `test-coverage`, and demo verification.

3. **Commit** any changes (e.g. changelog, version bumps). Ensure the tree is clean and pushed:

   ```bash
   git status
   git add -A && git commit -m "chore(release): prepare 1.0.12"
   make check-no-cursor-coauthor
   git push origin main
   ```

## Tag and publish

4. **Create an annotated tag** (replace with the version you are releasing, e.g. `v1.0.12`). Ensure you have at least one commit before tagging:

   ```bash
   git tag -a v1.0.12 -m "Release v1.0.12 - FrankenPHP banner, demo-smoke, final classes"
   git push origin v1.0.12
   ```

   If the bundle is developed in a monorepo and released from a separate clone (e.g. `nowo-tech/RuntimeEnvBundle`), run these commands in the clone that is pushed to the release remote.

5. **GitHub release**  
   The [release workflow](.github/workflows/release.yml) runs on tag push and creates the GitHub Release (including “Latest”) with body from the tag message and [CHANGELOG.md](CHANGELOG.md). No manual draft needed unless you want to edit the notes.

6. **Packagist**  
   If the package is on [Packagist](https://packagist.org/packages/nowo-tech/runtime-env-bundle), the new tag will be picked up automatically (or use “Update” there).

After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001). The release commit itself is not covered by an earlier `release-check` run.
