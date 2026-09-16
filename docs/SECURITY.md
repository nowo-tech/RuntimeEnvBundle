# Security

## Threat model

| Asset | Risk | Control |
|-------|------|---------|
| Variable values in DB | Confidentiality at rest | `#[Encrypted]` via DoctrineEncryptBundle (Halite/Defuse) |
| Encryptor key | Key compromise → all values | Key only in `.env` / file permissions / KMS — never in this table |
| Manage UI | Unauthorized read/write | REQ-UI-002 `access_roles` + access checker + host `access_control` on path prefix |
| Mutations | CSRF / session fixation | Symfony Form CSRF on create/update/delete (`RuntimeEnvDeleteType`) |
| Twig / logs | Accidental secret leak | List UI masks values; do not log `RuntimeEnvBag::all()`; do not render `runtime_env()` on public pages |
| FrankenPHP worker | Cross-request leak | `ResetInterface` on bag; no `$_ENV`/`putenv` mutation |

## Defaults

- `security.allow_unauthenticated: false`
- `security.access_roles: [ROLE_ADMIN]`
- Flex recipe adds `access_control` for `^/_runtime_env` → `ROLE_ADMIN`
- Panel disabled if you set `panel.enabled: false`

## REQ-SEC-004 (AI security audit)

| Field | Value |
|-------|--------|
| **Date** | 2026-09-16 |
| **Method** | Cursor agent static pass (`src/`, Flex recipe, demo, this doc + `.github/SECURITY.md`) |
| **Overall risk** | **Medium** |
| **Grade** | **Pass (conditional)** |
| **Record** | This document (in-package). Monorepo catalog row: `BUNDLES_SECURITY_ANALYSIS.md` §4 / §7.2 / Appendix X |

No Critical/High findings. Catalog checklist ✅ for REQ-SEC-004 once the package is listed in the inventory matrix.

This subsection is the in-package REQ-SEC-004 record (date, method, grade, residuals).

## Residual

- Host owns firewall `access_control` on `panel.path_prefix` (soft gate alone is not enough)
- Empty `security.access_roles` grants all authenticated users — keep at least `ROLE_ADMIN` in production
- Demo may set `allow_unauthenticated: true` — never copy that to production
- Host owns retention / audit of who changed which key
- Multi-worker: after save, other workers see updates after their next request reset (no shared in-memory cache by design)
- Encryptor key material remains an application secret (DoctrineEncryptBundle configuration)
- Twig `runtime_env('KEY')` returns decrypted strings — do not expose secrets in public HTML

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
| **Permissions / exposure** | Host firewall on `panel.path_prefix`; roles configured for production. |
| **Limits / DoS** | Admin-only UI; no public write endpoints. |
| **REQ-SEC-004** | Latest grade Pass (conditional) recorded in org security analysis (2026-09-16). |

Record confirmation in the release PR or tag notes.
