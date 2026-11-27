# Security

## Table of contents

- [Threat model](#threat-model)
- [Defaults](#defaults)
- [REQ-SEC-004 (AI security audit)](#req-sec-004-ai-security-audit)
- [Residual](#residual)
- [Reporting a vulnerability](#reporting-a-vulnerability)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)

## Threat model

| Asset | Risk | Control |
|-------|------|---------|
| Variable values in DB | Confidentiality at rest | `#[Encrypted]` via DoctrineEncryptBundle (Halite/Defuse) |
| Encryptor key | Key compromise → all values | Key only in `.env` / file permissions / KMS — never in this table |
| Manage UI | Unauthorized read/write | REQ-UI-002 `access_roles` + access checker + host `access_control` on path prefix |
| Mutations | CSRF / session fixation | Symfony Form CSRF on create/update/delete (`RuntimeEnvDeleteType`). **Fails closed**: `CsrfProtectionPass` refuses to compile the container while the manage panel is registered without `security.csrf.token_manager` + `form.type_extension.csrf`, or with `framework.form.csrf_protection: false` (`RuntimeEnvDeleteType` declares its own CSRF options and would otherwise accept tokenless POSTs) |
| Twig / logs | Accidental secret leak | List UI masks values; do not log `RuntimeEnvBag::all()`; do not render `runtime_env()` on public pages |
| Edit form | Secret rendered back into HTML | Since 1.1.0 the edit form never renders the stored (decrypted) value: the field starts empty, blank keeps the value, `clear_value` empties it. Since 1.3.0 a typed value is never re-rendered after a failed submission (create and edit) |
| List / templates | Secret printed by a template | Since 1.3.0 no entity reaches the templates: list rows are value-free arrays, the value column key resolves to nothing |
| Configuration | Fail-open switches | Since 1.3.0 security switches accept only literal booleans; `allow_unauthenticated: true` is refused unless `kernel.debug` |
| Runtime settings (1.6, opt-in) | Invalid or hostile values written live; secrets echoed; stale values; look-alike names | Admin edits of registered settings and the commands go through `RuntimeSettingUpdater` (registered ids, bundle validator first, secrets only via STDIN and never fingerprinted); values encrypted as in 1.5 (previous values too, as stored ciphertext); version token changed in the write transaction; DBAL reads re-checked with `===`; binary NO PAD key collation on MySQL / MariaDB; storage failures answer 503 / 409 with fixed messages on the bundle's routes |
| DBAL query providers (1.10, opt-in) | SQL injection, writes or locks through configured SQL; SQL / values leaking in errors; cross-tenant values through the shared cache | SQL fixed at container build (no `%env()%`, no `%`), one `SELECT` validated (no `;`, comments, literals, `?`, `::`, write / lock / sleep / file keywords), named parameters only (the setting id is bound, never interpolated); at most one row; values bounded before parsing; failures name the provider and the rule only, no chained driver exception; settings refused by every write path; secrets never in the shared cache; `cache_ttl: 0` by default. Residual: a SELECT-only database user with a statement timeout is the real guarantee |
| FrankenPHP worker | Cross-request leak / stale config | Request-scoped bag memoization; repository `HINT_REFRESH` + `ManagerRegistry`; access subscriber priority 7 + `_firewall_context`; no `$_ENV`/`putenv` |

## Defaults

- `security.allow_unauthenticated: false`
- `security.access_roles: [ROLE_ADMIN]`
- Flex recipe adds `access_control` for `^/_runtime_env` → `ROLE_ADMIN`
- Panel disabled if you set `web_ui.enabled: false` (or the deprecated `panel.enabled: false`)

## REQ-SEC-004 (AI security audit)

| Field | Value |
|-------|--------|
| **Date** | 2026-10-30 (previous: 2026-09-28) |
| **Method** | Claude Code static re-audit (`v1.2.1..302d316`, every security path of `src/`, UiKit 2.17.3 list scaffold) |
| **Overall risk** | **Low** |
| **Grade** | **Pass (good)** |
| **Record** | This document (in-package). Monorepo catalog: `BUNDLES_SECURITY_ANALYSIS.md` §4 / §7 / Appendix BM (2026-10-30), Appendix X (2026-09-28) |

No Critical / High / Medium findings. The four Low findings of the 2026-10-30 re-audit (Appendix BM) are fixed in 1.3.0:

| Finding | Fix (1.3.0) |
|---------|-------------|
| L1 list view model carried decrypted values (UiKit default cells could print them under a host override) | Value-free row arrays, `masked_value` column key, no entity in the page contexts; contract test on the plain UiKit list scaffold |
| L2 `%env(bool:…)%` on security switches read as true (fail open) | `enabled`, `web_ui.enabled`, `panel.enabled`, `security.allow_unauthenticated` accept only literal booleans (`InvalidConfigurationException`) |
| L3 `allow_unauthenticated: true` accepted in production | `UnauthenticatedAccessPass` refuses it unless `kernel.debug` is true (manage UI enabled) |
| L4 a typed secret was re-rendered after a failed submission; mask on edit only | Value field blanked on every re-render (create and edit), mask class on create; Firefox `-webkit-text-security` gap documented |

Info items also addressed: dev files export-ignored from the dist; embedded sub-requests (`render(controller())`) and array `_controller` values of the bundle controller are gated; an invalid edit no longer modifies the managed entity (the form edits a detached copy).

### 2026-11-15 audit QT (1.6.0, runtime-setting adapter, PR #39)

| Field | Value |
|-------|--------|
| **Head** | `bdf2dc0` |
| **Method** | Claude Code review of `v1.5.1..bdf2dc0` plus PoCs on MySQL 8.4, MariaDB 11.4 and PostgreSQL 17 |
| **Result** | No High / Medium. Low findings fixed before the tag: QT-L-1 (rotation expiry UTC), QT-L-2 (write purges only its row; index), QT-L-3 (version row without insert race), QT-L-4 (collation listener only with the feature on), QT-I-4 (`unset` empties a secret). QT-L-5 / QT-L-6: known issues for 1.6.x |
| **Status** | Audited (QT + Delta QT, 2026-11-16, `85701e0`): taggable, no open High/Medium; QT-L-5/L-6 known issues for 1.6.x |
| **GHSA** | None (≤ 1.5.1 not affected) |

This subsection is the in-package REQ-SEC-004 record (date, method, grade, residuals).

## Residual

- Host owns firewall `access_control` on `web_ui.path_prefix` (soft gate alone is not enough)
- Empty `security.access_roles` is **rejected** at compile time (fail-closed) unless `allow_unauthenticated` or a custom `access_checker` is set
- Demo may set `allow_unauthenticated: true` — refused at compile time outside `kernel.debug` since 1.3.0
- Host owns retention / audit of who changed which key (1.6: runtime-setting writes log an `info` audit line with the actor; route that channel where you keep audit logs)
- 1.6 (runtime settings): see the audit QT row below; QT-L-5 and QT-L-6 are known issues for a 1.6.x patch (UPGRADING)
- Multi-worker: after save, other workers see updates on their next request (request-scoped bag + refreshed Doctrine reads; no shared in-memory cache by design)
- 1.10 DBAL query providers: give the provider connection a database user that can only `SELECT` the tables it reads and a statement timeout; keep `cache_ttl: 0` when a parameter depends on the request (the contract's shared cache is keyed by setting id)
- Encryptor key material remains an application secret (DoctrineEncryptBundle configuration)
- Twig `runtime_env('KEY')` returns decrypted strings — do not expose secrets in public HTML
- Do not put the manage panel behind a firewall with `security: false` unless `allow_unauthenticated` is intentionally enabled (token storage may not refresh)

## Reporting a vulnerability

Report security issues **privately**:

1. Do **not** open a public GitHub issue for security-sensitive bugs.
2. Use [GitHub Security Advisories](https://github.com/nowo-tech/RuntimeEnvBundle/security/advisories) or email **hectorfranco@nowo.tech** / **security@nowo.tech**.
3. Include steps to reproduce, affected versions, and impact.
4. We will acknowledge and coordinate disclosure after a fix is available.

See also [`.github/SECURITY.md`](../.github/SECURITY.md) for supported versions and reporting policy.

## Release security checklist (12.4.1)

Before tagging a release, confirm:

| Item | Notes |
|------|--------|
| **SECURITY.md** | This document is current and linked from the README. |
| **`.gitignore` and `.env`** | `.env` and local env files are ignored; no committed secrets. |
| **No secrets in repo** | No API keys, passwords, Halite key files, or tokens in tracked files. |
| **Recipe / Flex** | Default recipe does not ship production secrets; `allow_unauthenticated: false`. |
| **Input / output** | Variable names validated (`^[A-Z][A-Z0-9_]*$`); list UI masks values; Twig auto-escape. |
| **Dependencies** | `composer audit` run; issues triaged. |
| **Logging** | Do not log decrypted `RuntimeEnvBag` values. |
| **Cryptography** | Field encryption via DoctrineEncryptBundle; keys from secure config / files. |
| **Permissions / exposure** | Host firewall on `web_ui.path_prefix`; roles configured for production. |
| **Limits / DoS** | Admin-only UI; no public write endpoints. |
| **REQ-SEC-004** | Latest grade Pass (good) / Low recorded in org security analysis (re-audit 2026-10-30, Appendix BM; Lows fixed in 1.3.0). |

Record confirmation in the release PR or tag notes.
