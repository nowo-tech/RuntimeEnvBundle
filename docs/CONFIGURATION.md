# Configuration

Alias: `nowo_runtime_env`

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Master switch for bag + manage UI |
| `table_prefix` | `runtime_env` | Table becomes `{prefix}_variables` |
| `database.entity_manager` | `default` | Doctrine EM name |
| `security.access_roles` | `[ROLE_ADMIN]` | REQ-UI-002. An empty list is rejected unless `access_checker` or `allow_unauthenticated` is set. |
| `security.access_checker` | `null` | Optional service id implementing `RuntimeEnvAccessCheckerInterface` |
| `security.allow_unauthenticated` | `false` | Demo only: refused at compile time unless `kernel.debug` is true (manage UI enabled). |
| `web_ui.enabled` | `true` | `false` removes the HTML manage UI (no routes, controller and access subscriber removed). The bag and Twig helpers keep working. |
| `web_ui.path_prefix` | `null` → `/_runtime_env` | URL prefix of the manage routes (also the path the access subscriber guards). |
| `web_ui.layout_template` | `null` | Layout the manage pages extend. `null` = `nowo_ui_kit.layouts.admin` (UiKit page contract, REQ-UI-003). A layout without the UiKit canonical blocks (pre-1.3: `title` + `body`) still works through a deprecated bridge. |
| `web_ui.templates.<page>` | `{}` | Per-page template overrides: `index`, `form` (see [TEMPLATES.md](TEMPLATES.md#per-page-overrides)). Unknown page ids fail the container compilation. |
| `web_ui.list_page_size` | `50` (1–500) | Rows per page of the manage list (REQ-PERF-001; `?page=N`). |

`enabled`, `web_ui.enabled`, `panel.enabled` and `security.allow_unauthenticated` are security switches: they accept
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

## Encryption

Field encryption is owned by **DoctrineEncryptBundle** (`#[Encrypted]` on `RuntimeEnvVariable::$value`). There is no separate cipher in this bundle.
