# Spec-driven development

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — what **RuntimeEnvBundle** guarantees to applications that integrate it (see [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md)). **PHPUnit** and **PHPStan** enforce contracts in CI where applicable.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos (when present) so changes to scripts, ports, and demo workflows stay discoverable from issues and PRs.

There is no separate executable spec language (for example Gherkin); tests and static analysis are the mechanical proof alongside this document.

---

## User stories

The sections below state **behavior**; this subsection states **intent** in backlog-friendly form.

| ID | Story |
| --- | --- |
| US-01 | **As a** Symfony integrator, **I want** pattern-based serial generation **so that** invoices and receipts get consistent `{year}/{prefix}/{id}` numbers. |
| US-02 | **As a** template author, **I want** the `serial_number` Twig function **so that** I render serials without calling services in controllers. |
| US-03 | **As a** privacy-conscious integrator, **I want** the `serial_number_mask` filter **so that** only the last N digits appear on customer-facing pages. |
| US-04 | **As a** maintainer, **I want** PHPUnit coverage **so that** padding and placeholder substitution do not regress. |
| US-05 | **As a** contributor, **I want** `REQ-*` anchors on scripted flows **so that** PRs cite the same identifiers as this document. |

**Out of scope for these stories:** guarantees outside the stated public API and outside dependency limits (PHP, Symfony, third-party libraries).

---

## Bundle functional scope

**Goal:** Generate and mask serial numbers for invoices, receipts, etc. Pattern-based with context placeholders and Twig helpers.

**Configuration root:** `nowo_runtime_env`

**In scope**

| Area | Responsibility |
| --- | --- |
| `RuntimeEnvGenerator` | Substitute `{context}` keys and `{id}` with optional zero-padding (max 32). |
| Twig | Function `serial_number(...)`, filter `serial_number_mask(...)` with config defaults. |
| Config | `mask_char`, `mask_visible_last` for display privacy. |

- Documented integration (see root `README.md` and `docs/`).
- Configuration and runtime behavior described in [`CONFIGURATION.md`](CONFIGURATION.md) and [`USAGE.md`](USAGE.md).
- Consumer-facing change notes in [`CHANGELOG.md`](CHANGELOG.md) and [`UPGRADING.md`](UPGRADING.md) when applicable.

**Explicit non-goals**

- Behavior not documented here or in linked integrator docs.
- **`demo/`** trees: illustrative unless a path is explicitly published as stable API in this document.

**Demos** (if present): examples only; not part of the Packagist contract unless services or contracts are explicitly documented as stable.

---

## Validating the functional spec

- Run **`composer qa`** and/or **`make qa`** / **`make release-check`** as documented in [`CONTRIBUTING.md`](CONTRIBUTING.md) (Docker-based flows may apply).
- Run **PHPUnit** and **PHPStan** in CI and locally for code changes.
- New or changed behavior should add or adjust **tests** under `tests/` (or the repo’s documented test layout) rather than relying on prose alone.

---

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| `REQ-MAKE-008` | Root `Makefile`, `demo/Makefile`, `demo/symfony8/Makefile`, `demo/symfony8/Makefile` | `update-deps` / `update-deps-demos`: refresh Composer locks for the bundle and Symfony demos (shared scripts under the parent `bundles/.scripts/` tree). |
| `REQ-GIT-001` | `.scripts/check-no-cursor-coauthor.sh`, `.githooks/commit-msg`, `.github/workflows/ci.yml`, `Makefile` | No Cursor `Co-authored-by` trailers in commit messages or reachable history; see [GITHUB_CI.md](GITHUB_CI.md). |
| `REQ-SF-002` | `.github/workflows/ci.yml` | CI PHPUnit matrix: minimum Symfony minors **6.4**, **7.0**, **7.4**, **8.0**, **8.1** across PHP 8.1–8.5. |
| `REQ-CI-002` | `.scrutinizer.yml` | Scrutinizer build and analysis exclusions (see org `BUNDLES_FULL_SPECS_DETAILS.md`). |
| `REQ-TEST-008` | `.scripts/php-coverage-percent.sh` | Parse PHPUnit coverage output for the Makefile `test-coverage` target. |

When you change scripted behavior, **update the existing `REQ-*` comment** if the ID still matches the rule, or **add a new `REQ-*`** and document it here and in the PR description.

---

## Suggested workflow for contributors

1. **Clarify behavior** in an issue or draft PR: acceptance criteria for the **product** and, if relevant, **Makefiles/demos** (`REQ-*`).
2. **Implement** with tests and static analysis.
3. **Anchor scripts and demos** when dev UX changes: add or adjust `REQ-*` comments and this table.
4. **Ship integrator docs** when behavior or configuration changes: [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`CHANGELOG.md`](CHANGELOG.md), and [`UPGRADING.md`](UPGRADING.md) when consumers must change code or config.
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).
   - For **new features**, use Cursor Agent skills (`/speckit-specify`, `/speckit-plan`, `/speckit-tasks`) as documented in SPEC-KIT.

---


## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
# Install Specify CLI (once per machine) — see SPEC-KIT.md
specify init --here --force --integration cursor-agent --script sh
specify integration list   # Cursor → installed (default)
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. For day-to-day tooling details, skills reference, folder layout, and troubleshooting, read **[`SPEC-KIT.md`](SPEC-KIT.md)**.

---

## Relationship to Engram / external checklists

[`ENGRAM.md`](ENGRAM.md) covers Nowo-wide documentation checklist items. This document ties together **what the package does**, **how we verify it**, and **local `REQ-*` habits**. Both coexist: Engram for org-level compliance, this file for product + traceability expectations.

---

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md) — GitHub Spec Kit manual (install, structure, usage)
- [`USAGE.md`](USAGE.md)
- [`CONFIGURATION.md`](CONFIGURATION.md)
- [`CONTRIBUTING.md`](CONTRIBUTING.md)
- [`RELEASE.md`](RELEASE.md)
