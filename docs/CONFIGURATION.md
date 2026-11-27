# Configuration

Alias: `nowo_runtime_env`

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Master switch for bag + manage UI |
| `table_prefix` | `runtime_env` | Table becomes `{prefix}_variables` |
| `database.entity_manager` | `default` | Doctrine entity manager that maps the bundle entity. 1.5: mapped on that manager **only** (also off an `auto_mapping` default), every read / write uses it, the build fails when it is not declared. When it is not your default manager the bundle owns it (reset when closed, cleared at every request / message / command). |
| `database.transaction` | `bundle` | 1.5. `host` (DDD hosts): `RuntimeEnvWriter` does not flush when your code calls it; your use case commits and `RuntimeEnvVariableChangedEvent` follows your commit. The admin UI keeps flushing its own writes. |
| `runtime_setting.enabled` | `false` | 1.6: nowo-tech/runtime-setting-contract source and writer on this table (key = variable name, values encrypted, rotation, version token). Maps two nullable columns and `{prefix}_versions`: migrate when you enable it. Literal boolean only. See [USAGE](USAGE.md#runtime-settings-16). |
| `providers.<name>` | `{}` | 1.10: read-only DBAL query providers for nowo-tech/runtime-setting-contract 1.1 (see [below](#dbal-query-providers-110)). |
| `conflict_response.status` | `null` | Answer of a lock conflict (deadlock, lock wait, serialization failure) on the manage pages: `409` with `CONFLICT_001` / `CONFLICT_002` (default) or `503`. Null follows nowo-tech/transaction-doctrine's `conflict_response`, else 409. See [CONCURRENCY](CONCURRENCY.md). |
| `conflict_response.retry_after` | `null` | `Retry-After` seconds of a `CONFLICT_001` answer without a hint. Null follows transaction-doctrine, else 1. |
| `security.access_roles` | `[ROLE_ADMIN]` | REQ-UI-002. An empty list is rejected unless `access_checker` or `allow_unauthenticated` is set. |
| `security.access_checker` | `null` | Optional service id implementing `RuntimeEnvAccessCheckerInterface` |
| `security.allow_unauthenticated` | `false` | Demo only: refused at compile time unless `kernel.debug` is true (manage UI enabled). |
| `web_ui.enabled` | `true` | `false` removes the HTML manage UI (no routes, controller and access subscriber removed). The bag and Twig helpers keep working. |
| `web_ui.path_prefix` | `null` → `/_runtime_env` | URL prefix of the manage routes (also the path the access subscriber guards). |
| `web_ui.layout_template` | `null` | Layout the manage pages extend. `null` = `nowo_ui_kit.layouts.admin` (UiKit page contract, REQ-UI-003). A layout without the UiKit canonical blocks (pre-1.3: `title` + `body`) still works through a deprecated bridge. |
| `web_ui.templates.<page>` | `{}` | Per-page template overrides: `index`, `form` (see [TEMPLATES.md](TEMPLATES.md#per-page-overrides)). Unknown page ids fail the container compilation. |
| `web_ui.list_page_size` | `50` (1–500) | Rows per page of the manage list (REQ-PERF-001; `?page=N`). |

`enabled`, `web_ui.enabled`, `panel.enabled`, `security.allow_unauthenticated` and `runtime_setting.enabled` are security switches: they accept
only literal `true` / `false`. An `%env(bool:…)%` placeholder is refused (`InvalidConfigurationException`) because it
would read as true while the container is built.

### Deprecated keys (removed in 2.0)

| Key | Since | Use instead | Behaviour until 2.0 |
|-----|-------|-------------|---------------------|
| `panel.enabled` | 1.3 | `web_ui.enabled` | `false` still removes the manage UI |
| `panel.path_prefix` | 1.3 | `web_ui.path_prefix` | Used when `web_ui.path_prefix` is not set |
| `templates.index` / `templates.form` | 1.3 | `web_ui.templates.index` / `.form` | Used when the canonical key is not set |
| `templates.layout` | 1.1 | `web_ui.layout_template` | Used when `web_ui.layout_template` is not set |
| `web_ui.css_framework` / `web_ui.icon_set` | 1.3 | `nowo_ui_kit.css_framework` / `nowo_ui_kit.icon_set` | Copied to `nowo_ui_kit` when the host did not set those keys there |

The value `@NowoRuntimeEnvBundle/manage/layout.html.twig` (the removed pre-1.3 default layout) is mapped to `null`.

## FormKit profile

The manage forms (`RuntimeEnvVariableType`, the CSRF-only `RuntimeEnvDeleteType`) are FormKit Types
(`#[FormKitConfig('runtime_env')]`, REQ-TWIG-005 rule 11). Their field defaults come from the FormKit profile
**`nowo_form_kit.profiles.runtime_env`**, which the bundle prepends unless you define it:

```yaml
# config/packages/nowo_form_kit.yaml — define the profile to override the bundle default (nothing is prepended then)
nowo_form_kit:
    profiles:
        runtime_env:
            alias: runtime_env
            translation_domain: NowoRuntimeEnvBundle
            auto_help: false
            auto_placeholder: false
            defaults:
                attr: {}
                row_attr: {}
            field_types: {}   # e.g. text: { attr: { class: my-input } }
```

No framework classes by default (REQ-UI-003): the UiKit form scaffold paints the fields. Explicit options on the types
(labels, `nowo-runtime-env-secret`, `rows`, `autocomplete`) win over the profile. The profile does not change your
`nowo_form_kit.default_profile`.

## Look and feel (UiKit page contract)

The manage pages extend the UiKit list / form scaffolds (REQ-UI-003): the host themes them once, for every Nowo bundle,
in `nowo_ui_kit` — no per-bundle CSS framework or layout keys. See [TEMPLATES.md](TEMPLATES.md).

```yaml
# Host layout + theme, once for all bundles (Bootstrap 5 example)
nowo_ui_kit:
    css_framework: bootstrap5     # or: tailwind | foundation | tabler | custom | none
    icon_set: bootstrap-icons     # or: tabler-icons | ux_icon | svg_inline | none
    row_actions_display: icon     # or: text | icon_text
    layouts:
        admin: 'layouts/admin.html.twig'   # your layout or a one-file bridge rendering the UiKit canonical blocks
```

```yaml
# Own design system: semantic nowo-ui-* classes only, mapped through the UiKit theme slots
nowo_ui_kit:
    css_framework: custom
    icon_set: svg_inline
    row_actions_display: text
    theme:
        classes:
            btn_primary: 'app-button app-button--primary'
            table: 'app-table'
```

Tailwind and Foundation work the same way (`css_framework: tailwind` / `foundation`); the manage pages never hard-code
framework classes. The form fields use the host form theme (`twig.form_themes`).

## Example

```yaml
nowo_runtime_env:
    enabled: true
    table_prefix: runtime_env
    database:
        entity_manager: default
        transaction: bundle   # 1.5: bundle | host
    security:
        access_roles: [ROLE_ADMIN]
        allow_unauthenticated: false
    web_ui:
        enabled: true
        path_prefix: /_runtime_env
        layout_template: ~          # null = nowo_ui_kit.layouts.admin
        list_page_size: 50
```

Keep the host firewall / `access_control` in sync with `web_ui.path_prefix` (the recipe adds `^/_runtime_env`).

## DBAL query providers (1.10)

```yaml
nowo_runtime_env:
    providers:
        tenant_db:                                  # provider name: [a-z][a-z0-9_]{0,63}
            connection: tenants                     # required: doctrine.dbal.connections.<name>
            sql: >-
                SELECT s.value, s.previous_value, s.previous_until
                FROM tenant_setting s JOIN tenant t ON t.id = s.tenant_id
                WHERE t.code = :tenant AND s.name = :key
            parameters:                             # constants, bound as named parameters (may be %env()%)
                tenant: '%env(TENANT_CODE)%'
            value_column: value                     # default "value"; NULL or no row = no value
            previous_column: previous_value         # optional, with previous_expires_column (rotation)
            previous_expires_column: previous_until # date-time (expires_timezone) or Unix timestamp
            expires_timezone: UTC                   # default UTC
            max_length: 65536                       # bytes, 1..65536; longer = source failure
            cache_ttl: 0                            # 0..3600 s in nowo_runtime_setting.cache_pool; secrets never

nowo_runtime_setting:
    setting_providers:
        TENANT_API_URL: tenant_db                   # or runtime.<setting>.provider in the owning bundle
```

| Key | Default | Description |
|-----|---------|-------------|
| `connection` | required | DBAL connection name; the build fails when it is not declared. Literal only. |
| `sql` | required | One `SELECT` (validated at container build, never editable at runtime): no `;`, no comments (`--`, `#`, `/* */`), no string literals (`'`, `$$`), no `\`, no positional `?`, no `::` casts, no `%`; identifiers may be quoted with `"` or backticks; no keyword that writes, locks, sleeps or reaches files (`INSERT`, `UPDATE`, `DELETE`, `INTO`, `FOR UPDATE` / `SHARE`, `LOCK`, `SET`, `CALL`, `SLEEP`, `PG_SLEEP`, `LOAD_FILE`, ...). Named parameters only: `:id` (the setting id), `:namespace`, `:key` and the keys of `parameters`. At most 4096 bytes. |
| `parameters` | `{}` | Constants (`[a-z_][a-z0-9_]*`, not `id` / `namespace` / `key`); every one must be used by `sql`. Scalars or `%env()%`. |
| `value_column` | `value` | Result column of the value. Matched exactly, else case-insensitively when that is unambiguous. |
| `previous_column` / `previous_expires_column` | `null` | Both or neither. A previous value without an expiry is ignored (fail closed for that candidate). |
| `expires_timezone` | `UTC` | Zone of expiry values without an offset (this bundle's own table stores UTC). |
| `max_length` | `65536` | Longest value accepted before parsing. |
| `cache_ttl` | `0` | Seconds a non-secret value may be served from the contract's shared pool. Keep `0` when a parameter depends on the request. |

The query must return **at most one row**: a second row is a source failure, never "the first one wins". Every
failure (driver error, two rows, missing column, oversized value, bad expiry) is a `RuntimeSettingSourceFailure` that
names the provider and the rule only: no SQL, no parameter, no value, no chained driver exception. The setting's
`on_error` then applies.

**Read-only.** Settings read from a provider cannot be set, unset or rotated: the `nowo:runtime-setting:*` commands
and the manage UI refuse them with `RuntimeSettingNotWritable`. Run the provider on a connection whose database user
can only `SELECT` the tables it reads, and give that user a statement timeout (PostgreSQL
`ALTER ROLE ... SET statement_timeout = '2s'`, MySQL `max_execution_time`, MariaDB `max_statement_time`): the
validation is defence in depth, the database grants are the guarantee.

## Encryption

Field encryption is owned by **DoctrineEncryptBundle** (`#[Encrypted]` on `RuntimeEnvVariable::$value`). There is no separate cipher in this bundle.
